<?php
/**
 * Blog reverse-proxy — serve the Content Agent's generated articles from our
 * NextJS/MUI renderer while keeping the CANONICAL URL on the merchant's apex
 * domain (`merchant.com/blog/*`). The plugin intercepts `/blog/*`, fetches the
 * rendered HTML from our tenant-scoped origin, and echoes it under the apex URL.
 *
 * This is a DISTINCT class from Xpay_Deflection (redirect vs proxy; UA-gated vs
 * all-visitors — different jobs). It copies deflection's SAFETY CONTRACT verbatim
 * because it runs on the same hot template_redirect path.
 *
 * SAFETY CONTRACT (this class must never break a store):
 *   - Writes ZERO files. Pure runtime fetch-and-echo (+ a body transient cache).
 *   - Fail-open everywhere: policy off / missing field / origin down / non-2xx /
 *     timeout / any doubt → `return` and let WordPress render normally. A broken
 *     origin must NEVER show a broken page — it shows the normal WP response.
 *   - Default OFF until the backend policy enables the merchant AND
 *     agentSurfaceReady === true (the proxy makes a visible surface appear on the
 *     merchant's own domain — require explicit per-merchant consent).
 *   - Front-end GET/HEAD only. Never admin / REST / cron / CLI / non-GET.
 *   - Owned-paths gate: only proxy a path that the tenant renderer actually
 *     publishes — the set is read from the renderer's own sitemap
 *     (`{originBase}{prefix}/sitemap.xml`, cached). We never shadow a merchant's
 *     own post that happens to live under the prefix, and we never hit the origin
 *     for a path it can't serve.
 *   - Precedence rule: if a real PUBLISHED WP post already exists at the path,
 *     bail (let WP serve it). This is what makes "push to WP + publish" (the
 *     Content_Pages escape hatch) cleanly take ownership of a page.
 *   - Policy is server-driven (the xpay backend), fetched in the BACKGROUND by
 *     WP-Cron into a transient — never on the hot request path.
 *
 * Spec: docs/jun/30/content-blog-apex-proxy-spec.md (Component A).
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Blog_Proxy {

	const CRON_HOOK     = 'xpay_wc_refresh_blog_proxy_policy';
	const TRANSIENT     = 'xpay_wc_blog_proxy_policy';
	const BODY_PREFIX   = 'xpay_wc_blogp_';           // per-path body cache key prefix
	const PATHS_PREFIX  = 'xpay_wc_blogp_paths_';     // per-origin owned-paths cache key prefix
	const PATHS_TTL     = 6 * HOUR_IN_SECONDS;
	const CACHE_SECONDS = 6 * HOUR_IN_SECONDS;        // policy cache TTL
	const FETCH_TIMEOUT = 4;
	const DEFAULT_PREFIX = '/blog';

	/**
	 * Hard, non-negotiable path prefixes that are NEVER proxied, regardless of
	 * what the backend policy says. Same belt-and-suspenders set as deflection.
	 */
	const ALWAYS_EXCLUDE = array(
		'/wp-admin',
		'/wp-login',
		'/wp-json',
		'/wp-content',
		'/wp-includes',
		'/.well-known',
		'/feed',
		'/cart',
		'/checkout',
		'/my-account',
		'/robots.txt',
		'/sitemap',
	);

	public static function register() {
		// template_redirect at priority 0 — BEFORE deflection (priority 1) and
		// before the theme renders the 404. On a match we echo + exit, so WP's
		// 404 template never loads even though is_404() would be true.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_proxy' ), 0 );

		// SEO bridge: advertise the (now apex-reachable) blog sitemap in robots.txt
		// so search engines discover the articles under the merchant's own domain.
		// PHP_INT_MAX priority = run LAST, appending to whatever other SEO plugins
		// (Yoast/SiteSEO/RankMath) already produced — we add, never replace.
		add_filter( 'robots_txt', array( __CLASS__, 'append_sitemap_directive' ), PHP_INT_MAX, 2 );

		// Background policy refresh — never on the hot request path.
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh_policy' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Short initial delay so a fresh activation (or a plugin update that
			// re-registers) repopulates the policy cache within ~a minute instead
			// of leaving the proxy dormant for several. Off the activation critical
			// path, then hourly thereafter.
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unregister_cron() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
		// NOTE: we deliberately do NOT delete the policy transient here. A plugin
		// UPDATE runs deactivate→reactivate, and deleting the cached policy on
		// deactivate blanks the proxy until the next cron tick repopulates it —
		// a several-minute blog outage on every update. The transient is harmless
		// while deactivated (the template_redirect hook isn't registered, so
		// maybe_proxy never runs), and it self-refreshes hourly once reactivated.
	}

	/**
	 * Pull the policy from the backend and cache it (background WP-Cron). On any
	 * failure we LEAVE the existing cached policy in place — a single failed fetch
	 * must not flip a working proxy off (or on).
	 */
	public static function refresh_policy() {
		$slug = Xpay_Plugin::merchant_slug();
		if ( ! $slug ) {
			return;
		}
		$res = Xpay_Client::get( 'v1/merchants/' . rawurlencode( $slug ) . '/blog-proxy-policy', array(), self::FETCH_TIMEOUT );
		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			return; // keep whatever we had
		}
		set_transient( self::TRANSIENT, $res, self::CACHE_SECONDS );
	}

	private static function policy() {
		$p = get_transient( self::TRANSIENT );
		return is_array( $p ) ? $p : null;
	}

	/**
	 * robots.txt filter — append `Sitemap: <apex>/<prefix>/sitemap.xml` when the
	 * proxy is enabled for this merchant, so the article sitemap (served by the
	 * proxy at the apex) is discoverable by search engines. Additive + idempotent:
	 * runs at PHP_INT_MAX so it appends after other SEO plugins' output rather than
	 * being clobbered, and no-ops if the line is already present. Only applies to a
	 * WordPress-generated (virtual) robots.txt — a physical robots.txt file bypasses
	 * this filter entirely (documented in the go-live playbook as the manual case).
	 */
	public static function append_sitemap_directive( $output, $public ) {
		$policy = self::policy();
		if ( ! $policy || empty( $policy['enabled'] ) ) {
			return $output; // proxy off => don't advertise a sitemap that won't serve
		}
		if ( array_key_exists( 'agentSurfaceReady', $policy ) && ! $policy['agentSurfaceReady'] ) {
			return $output;
		}
		$prefix = isset( $policy['prefix'] ) ? '/' . trim( (string) $policy['prefix'], '/' ) : self::DEFAULT_PREFIX;
		if ( '/' === $prefix ) {
			return $output;
		}
		$sitemap_url = home_url( $prefix . '/sitemap.xml' );
		if ( false !== strpos( (string) $output, $sitemap_url ) ) {
			return $output; // already advertised — don't duplicate
		}
		return rtrim( (string) $output, "\n" ) . "\n\nSitemap: " . esc_url_raw( $sitemap_url ) . "\n";
	}

	public static function maybe_proxy() {
		// ---- fail-open guards: any of these => do nothing ----
		if ( is_admin() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		if ( ! Xpay_Plugin::is_connected() ) {
			return; // unconnected stores never proxy
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return; // never touch POST/PUT/etc
		}

		// Our own self-probe header, and the proxy loop-guard header — never proxy.
		if ( ! empty( $_SERVER['HTTP_X_XPAY_PROBE'] ) || ! empty( $_SERVER['HTTP_X_XPAY_PROXY'] ) ) {
			return;
		}

		$policy = self::policy();
		if ( ! $policy || empty( $policy['enabled'] ) ) {
			return; // no policy / explicitly disabled => no-op
		}
		// Backend can hold the proxy back until the tenant renderer is ready AND
		// the merchant has consented to the surface appearing on their domain.
		if ( array_key_exists( 'agentSurfaceReady', $policy ) && ! $policy['agentSurfaceReady'] ) {
			return;
		}

		$origin_base = isset( $policy['originBase'] ) ? (string) $policy['originBase'] : '';
		if ( '' === $origin_base || 0 !== strpos( $origin_base, 'https://' ) ) {
			return; // no / non-https origin => no-op
		}

		$prefix = isset( $policy['prefix'] ) ? '/' . trim( (string) $policy['prefix'], '/' ) : self::DEFAULT_PREFIX;
		if ( '/' === $prefix ) {
			return; // refuse an empty prefix — would swallow the whole site
		}

		// Parse the request path (lower-cased for matching; original URI preserved
		// for the upstream fetch + canonical).
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- prefix-matched + forwarded, never echoed as HTML.
		$uri  = is_string( $uri ) ? $uri : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = ( '' === $path ) ? '/' : $path;
		$lpath = strtolower( rtrim( $path, '/' ) );
		$lpref = strtolower( $prefix );

		// Only paths at or under the prefix.
		if ( $lpath !== $lpref && 0 !== strpos( $lpath, $lpref . '/' ) ) {
			return;
		}

		// Hard safety excludes (+ any extra from the policy).
		$policy_excludes = ( isset( $policy['excludePaths'] ) && is_array( $policy['excludePaths'] ) ) ? $policy['excludePaths'] : array();
		foreach ( array_merge( self::ALWAYS_EXCLUDE, $policy_excludes ) as $ex ) {
			$ex = strtolower( (string) $ex );
			if ( '' !== $ex && 0 === strpos( $lpath, $ex ) ) {
				return;
			}
		}

		// The blog sitemap ({prefix}/sitemap.xml) is served by the renderer but is
		// NOT a member of its own <loc> list, so it can never pass the owned-paths
		// gate below. Let it through explicitly so search engines can fetch the
		// article sitemap under the apex (a-merchant-store.example/blog/sitemap.xml). Paired
		// with the robots.txt Sitemap directive (append_sitemap_directive).
		$is_blog_sitemap = ( $lpath === $lpref . '/sitemap.xml' );

		// Owned-paths gate: the requested path must be one the tenant renderer
		// actually publishes (its own sitemap is the source of truth). This covers
		// the prefix index, article pages, and category/tag archives — and nothing
		// else — so we never hit the origin for, or shadow, a path we don't own.
		if ( ! $is_blog_sitemap ) {
			$owned = self::owned_paths( $origin_base, $prefix );
			if ( empty( $owned ) || ! in_array( $lpath, $owned, true ) ) {
				return; // not one of ours (or sitemap unavailable) — let WP serve
			}
		}

		// Precedence rule (the anti-shadow keystone): if WordPress itself resolved
		// ANY real content at this URL — a published post/page, the blog posts-page
		// (Settings → Reading), a category/archive, anything that is NOT a 404 — it
		// WINS. We bail and let WP render it. Two things fall out of this:
		//   1. A merchant who already uses /blog for their own blog is never
		//      shadowed — their /blog isn't a 404, so we don't touch it.
		//   2. "Push to WP + publish" (Xpay_Content_Pages) cleanly takes ownership —
		//      once the page is a real published post, it stops being a 404.
		// We only ever render into the GAPS WordPress would otherwise 404 on.
		if ( ! is_404() ) {
			return;
		}

		// Loop guard: our origin host must differ from the apex host.
		$host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$origin_host = (string) wp_parse_url( $origin_base, PHP_URL_HOST );
		if ( '' !== $host && '' !== $origin_host && strtolower( $host ) === strtolower( $origin_host ) ) {
			return;
		}

		$cache_seconds = isset( $policy['cacheSeconds'] ) ? max( 0, (int) $policy['cacheSeconds'] ) : 600;
		$swr           = isset( $policy['staleWhileRevalidate'] ) ? max( 0, (int) $policy['staleWhileRevalidate'] ) : DAY_IN_SECONDS;

		// ---- serve: WP-transient body cache first, then the origin fetch ----
		$query     = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$cache_key = self::BODY_PREFIX . md5( $path . '?' . $query );
		$cached    = ( $cache_seconds > 0 ) ? get_transient( $cache_key ) : false;

		if ( is_array( $cached ) && isset( $cached['body'], $cached['status'], $cached['ctype'] ) ) {
			self::emit( (int) $cached['status'], (string) $cached['ctype'], (string) $cached['body'], $host, $path, $cache_seconds, $swr, $method, true );
			return; // emit() exits; defensive return
		}

		$origin = rtrim( $origin_base, '/' ) . $path . ( '' !== $query ? '?' . $query : '' );
		$resp   = wp_remote_get(
			$origin,
			array(
				'timeout'     => self::FETCH_TIMEOUT,
				'redirection' => 2,
				'headers'     => array(
					'Accept'         => isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : 'text/html',
					'X-Xpay-Proxy'   => '1',
					'X-Xpay-Tenant'  => $host,
					'X-Forwarded-Host' => $host,
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return; // FAIL-OPEN
		}
		$status = (int) wp_remote_retrieve_response_code( $resp );
		if ( $status < 200 || $status >= 400 ) {
			return; // FAIL-OPEN on 4xx/5xx (incl. the origin's own 404)
		}
		$body  = (string) wp_remote_retrieve_body( $resp );
		$ctype = (string) wp_remote_retrieve_header( $resp, 'content-type' );
		if ( '' === $ctype ) {
			$ctype = 'text/html; charset=UTF-8';
		}

		if ( $cache_seconds > 0 ) {
			set_transient(
				$cache_key,
				array( 'status' => $status, 'ctype' => $ctype, 'body' => $body ),
				$cache_seconds
			);
		}

		self::emit( $status, $ctype, $body, $host, $path, $cache_seconds, $swr, $method, false );
	}

	/**
	 * Emit the proxied response under the apex URL and exit. Sets a runtime marker
	 * so Xpay_Agent_Analytics (shutdown hook, after this exit) can record the hit.
	 */
	private static function emit( $status, $ctype, $body, $host, $path, $cache_seconds, $swr, $method, $cache_hit ) {
		$GLOBALS['xpay_wc_blog_proxied'] = array(
			'path'      => $path,
			'status'    => $status,
			'cache_hit' => (bool) $cache_hit,
		);

		status_header( $status );
		header( 'Content-Type: ' . $ctype );
		header( 'Cache-Control: public, s-maxage=' . (int) $cache_seconds . ', stale-while-revalidate=' . (int) $swr );
		// Defensive canonical back to the apex path (the renderer should already
		// emit one, but a proxied response should never point at the origin host).
		if ( '' !== $host ) {
			$canonical = ( is_ssl() ? 'https://' : 'http://' ) . $host . $path;
			header( 'Link: <' . esc_url_raw( $canonical ) . '>; rel="canonical"', false );
		}
		header( 'X-Xpay-Proxied: 1' );

		if ( 'HEAD' !== $method ) {
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- upstream-rendered trusted HTML echoed verbatim (reverse-proxy body).
		}
		exit;
	}

	/**
	 * The set of URL paths the tenant renderer publishes under the prefix, read
	 * from its own sitemap (`{originBase}{prefix}/sitemap.xml`) and cached. Paths
	 * are normalised (lower-cased, no trailing slash) so they compare directly
	 * against the request path. Returns array() on any fetch failure — the gate
	 * then fails open (serves WP normally) rather than proxying blindly.
	 */
	private static function owned_paths( $origin_base, $prefix ) {
		$key    = self::PATHS_PREFIX . md5( $origin_base . '|' . $prefix );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$sitemap = rtrim( $origin_base, '/' ) . $prefix . '/sitemap.xml';
		$resp    = wp_remote_get(
			$sitemap,
			array(
				'timeout' => self::FETCH_TIMEOUT,
				'headers' => array( 'X-Xpay-Proxy' => '1' ),
			)
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return array(); // fail-open
		}

		$body  = (string) wp_remote_retrieve_body( $resp );
		$paths = array();
		if ( preg_match_all( '#<loc>\s*([^<]+?)\s*</loc>#i', $body, $m ) ) {
			foreach ( $m[1] as $loc ) {
				$p = (string) wp_parse_url( html_entity_decode( $loc ), PHP_URL_PATH );
				if ( '' === $p ) {
					continue;
				}
				$p = strtolower( rtrim( $p, '/' ) );
				$paths[] = ( '' === $p ) ? '/' : $p;
			}
		}
		$paths = array_values( array_unique( $paths ) );
		set_transient( $key, $paths, self::PATHS_TTL );
		return $paths;
	}
}
