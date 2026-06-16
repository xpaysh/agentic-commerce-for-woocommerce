<?php
/**
 * Agent deflection — route realtime AI *shopping* fetchers (ChatGPT-User,
 * Claude-User, Perplexity-User, …) to the merchant's structured sidecar, while
 * leaving humans, logged-in users, search/index crawlers, and everything else
 * completely untouched.
 *
 * SAFETY CONTRACT (this class must never break a store):
 *   - Writes ZERO files. It is a pure runtime 302 for matched bot UAs only.
 *     robots.txt / .htaccess / .well-known / llms.txt are never modified.
 *   - Fail-open everywhere: no policy, policy disabled, any missing field, any
 *     doubt → it does NOTHING and the request proceeds normally.
 *   - No network on the request path. The policy is fetched in the BACKGROUND by
 *     WP-Cron and cached in a transient; `maybe_deflect()` only reads that cache.
 *   - Only ever acts on front-end GET/HEAD requests from a matched bot UA. Never
 *     admin, REST, cron, CLI, logged-in users, or non-GET (checkout POSTs).
 *   - Policy is server-driven (the xpay backend), so the UA list / target / on-off
 *     can change without a plugin update. Default is OFF until the backend enables
 *     a given merchant.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Deflection {

	const CRON_HOOK     = 'xpay_wc_refresh_deflection_policy';
	const TRANSIENT     = 'xpay_wc_deflection_policy';
	const CACHE_SECONDS = 6 * HOUR_IN_SECONDS;
	const FETCH_TIMEOUT = 4;

	/**
	 * Hard, non-negotiable path prefixes that are NEVER deflected, regardless of
	 * what the backend policy says. Belt-and-suspenders so a bad policy can't
	 * break discovery files, the admin, or the checkout funnel.
	 */
	const ALWAYS_EXCLUDE = array(
		'/robots.txt',
		'/sitemap',
		'/llms.txt',
		'/agents.md',
		'/.well-known',
		'/wp-admin',
		'/wp-login',
		'/wp-json',
		'/wp-content',
		'/wp-includes',
		'/feed',
		'/cart',
		'/checkout',
		'/my-account',
	);

	public static function register() {
		// Front-end enforcement runs AFTER the discovery emitters (priority 0):
		// if an emitter already served + exited (e.g. /llms.txt), we never run.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_deflect' ), 1 );

		// Background policy refresh — never on the hot request path.
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh_policy' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unregister_cron() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Pull the policy from the backend and cache it. Runs in the background
	 * (WP-Cron). On any failure we LEAVE the existing cached policy in place —
	 * a single failed fetch must not flip a working deflection off (or on).
	 */
	public static function refresh_policy() {
		$slug = Xpay_Plugin::merchant_slug();
		if ( ! $slug ) {
			return;
		}
		$res = Xpay_Client::get( 'v1/merchants/' . rawurlencode( $slug ) . '/deflection-policy', array(), self::FETCH_TIMEOUT );
		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			return; // keep whatever we had
		}
		set_transient( self::TRANSIENT, $res, self::CACHE_SECONDS );
	}

	private static function policy() {
		$p = get_transient( self::TRANSIENT );
		return is_array( $p ) ? $p : null;
	}

	public static function maybe_deflect() {
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
			return; // unconnected stores never deflect
		}
		if ( is_user_logged_in() ) {
			return; // never reroute staff / admins / logged-in shoppers
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return; // never touch POST/PUT/etc (checkout, forms)
		}

		// Our own self-probe header — never redirect it.
		if ( ! empty( $_SERVER['HTTP_X_XPAY_PROBE'] ) ) {
			return;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua ) {
			return;
		}

		$policy = self::policy();
		if ( ! $policy || empty( $policy['enabled'] ) ) {
			return; // no policy / explicitly disabled => no-op
		}
		// Backend can hold deflection back until the sidecar is ready.
		if ( array_key_exists( 'agentSurfaceReady', $policy ) && ! $policy['agentSurfaceReady'] ) {
			return;
		}

		$target = isset( $policy['target'] ) ? (string) $policy['target'] : '';
		if ( '' === $target || ( 0 !== strpos( $target, 'https://' ) && 0 !== strpos( $target, 'http://' ) ) ) {
			return; // no / malformed target => no-op
		}

		$agents = ( isset( $policy['deflectAgents'] ) && is_array( $policy['deflectAgents'] ) ) ? $policy['deflectAgents'] : array();
		if ( empty( $agents ) ) {
			return;
		}

		// Loop guard: if we're already on the target host, do nothing.
		$host        = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$target_host = (string) wp_parse_url( $target, PHP_URL_HOST );
		if ( '' !== $host && '' !== $target_host && strtolower( $host ) === strtolower( $target_host ) ) {
			return;
		}

		// Path excludes — hard-coded safety set + any extra from the policy.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only for prefix match + redirect; not echoed.
		$uri  = is_string( $uri ) ? $uri : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = ( '' === $path ) ? '/' : strtolower( $path );

		$policy_excludes = ( isset( $policy['excludePaths'] ) && is_array( $policy['excludePaths'] ) ) ? $policy['excludePaths'] : array();
		foreach ( array_merge( self::ALWAYS_EXCLUDE, $policy_excludes ) as $ex ) {
			$ex = strtolower( (string) $ex );
			if ( '' !== $ex && 0 === strpos( $path, $ex ) ) {
				return;
			}
		}

		// UA match — only the realtime shopping fetchers in the policy list.
		$matched = false;
		foreach ( $agents as $token ) {
			$token = (string) $token;
			if ( '' !== $token && false !== stripos( $ua, $token ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched ) {
			return;
		}

		// All clear: 302 (temporary) to the sidecar, preserving the path + query.
		$location = rtrim( $target, '/' ) . $uri;
		// In-memory marker so Xpay_Agent_Analytics (which runs on `shutdown`, after
		// this exit) can record deflected=true for this request. Purely a runtime
		// flag — writes nothing, changes no behaviour here.
		$GLOBALS['xpay_wc_deflected'] = true;
		nocache_headers();
		// External host by design (the merchant's sidecar) — wp_redirect, not
		// wp_safe_redirect (which would strip the off-site host).
		wp_redirect( $location, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- intentional off-site deflection to the merchant's own sidecar.
		exit;
	}
}
