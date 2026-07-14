<?php
/**
 * Inbound attribution classifier.
 *
 * Captures the channel that referred a shopper to the storefront so the WC
 * order can carry that signal through to `Xpay_Order_Events`. Four input
 * signals, in priority order:
 *
 *   1. `?xpay_ref=<source>`   — deterministic Layer 1 stamp from links we
 *                               control (sidecar / MCP / widget). Highest
 *                               confidence; method = 'stamp'.
 *   2. `?utm_source=…`        — only ChatGPT reliably adds it today
 *                               (since Jun 2025, desktop). High confidence;
 *                               method = 'utm'.
 *   3. `Referer:` host match  — covers Perplexity / Claude / Gemini /
 *                               Copilot / Meta AI / Grok / etc.
 *                               High confidence; method = 'referer'.
 *   4. `User-Agent` match     — in-app fetches by OAI-SearchBot,
 *                               PerplexityBot, ClaudeBot, etc.
 *                               High confidence; method = 'ua'.
 *   5. Heuristic              — empty referrer + `Sec-Fetch-Site:
 *                               cross-site` + direct PDP hit. Low
 *                               confidence; method = 'heuristic'.
 *
 * Storage:
 *   - First-party cookie `_xpay_ref` (30d, first-touch — never overwritten
 *     by a later equal-or-lower-confidence signal).
 *   - WC session mirror so a customer who clears cookies between PDP and
 *     checkout still gets attributed.
 *   - On `woocommerce_checkout_create_order` we copy the resolved record
 *     onto the order as `_xpay_ref_inbound` meta. Xpay_Order_Events reads
 *     it from there.
 *
 * Ruleset:
 *   Seeded from MalteBerlin/LLM-Referrer + GA4's May-2026 default channel
 *   group. Lives in `default_ruleset()` below; future enhancement is to
 *   pull a versioned ruleset from `/v1/llm-referrer-ruleset` and cache.
 *
 * No PII captured here. We never log or store the shopper's IP; the cookie
 * carries (source, confidence, method, ts, first_pdp_path).
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Attribution {

	const COOKIE_NAME      = '_xpay_ref';
	const COOKIE_TTL_DAYS  = 30;
	const SESSION_KEY      = '_xpay_ref_inbound';
	const ORDER_META_KEY   = '_xpay_ref_inbound';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	const REST_NAMESPACE = 'xpay/v1';
	const REST_ROUTE     = '/classify';
	const SCRIPT_HANDLE  = 'xpay-attribution';
	const SCRIPT_REL     = 'js/xpay-attribution.js';
	const MAX_BEACON_BYTES = 2048;

	private function __construct() {
		// Run on template_redirect so we have is_product()/is_shop() context
		// without the front-page query overhead.
		//
		// ⚠️ On a full-page-cached store this NEVER RUNS for a fresh visitor —
		// which is every AI-referred shopper. It is kept as defense-in-depth for
		// uncached stores and logged-in visitors; the JS beacon below is the
		// primary path in production.
		add_action( 'template_redirect', array( $this, 'classify' ), 5 );

		// Mirror onto the order at create-time. Priority 5 keeps it ahead of
		// Xpay_Cart::tag_order() (priority 10) so cart-deeplink attribution
		// stays the higher-precedence record.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'tag_order' ), 5, 1 );

		// The cache-immune path (Fix 3): browser collects, never-cached REST
		// endpoint persists.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_beacon' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		// Fix 5 — page-cache interop for the stamp.
		add_filter( 'rocket_cache_ignored_parameters', array( $this, 'rocket_ignore_xpay_ref' ) );
	}

	/**
	 * Kill switch for the JS beacon + its REST endpoint, independent of a plugin
	 * release. Off automatically on a disconnected store (no point classifying if
	 * we can't report), and hard-disableable via
	 * `define( 'XPAY_WC_ATTRIBUTION_BEACON', false )` or the filter below.
	 */
	private static function beacon_enabled() {
		if ( defined( 'XPAY_WC_ATTRIBUTION_BEACON' ) && false === XPAY_WC_ATTRIBUTION_BEACON ) {
			return false;
		}
		$connected = ! class_exists( 'Xpay_Plugin' ) || Xpay_Plugin::is_connected();
		return (bool) apply_filters( 'xpay_wc_attribution_beacon_enabled', $connected );
	}

	/** Per-IP throttle for the public classify endpoint. ~30 hits / 5 min / IP. */
	private function beacon_rate_ok() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return true; // can't key it — don't block legitimate traffic behind a broken proxy.
		}
		$key   = 'xpay_cls_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= 30 ) {
			return false;
		}
		set_transient( $key, $count + 1, 5 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * WP Rocket: keep ONE cache entry for a stamped URL and pass `xpay_ref`
	 * through to JS, instead of serving the cached page with the param swallowed.
	 *
	 * ⛔ Deliberately NOT `rocket_cache_query_strings`: that would fragment the
	 * page cache per ref value (a new cached copy for every surface we stamp).
	 * "Ignored parameters" is the correct hook — one entry, param still visible
	 * to `location.search`, which is all the beacon needs.
	 */
	public function rocket_ignore_xpay_ref( $params ) {
		if ( ! is_array( $params ) ) {
			return $params;
		}
		$params['xpay_ref'] = 1;
		return $params;
	}

	/**
	 * Enqueue the beacon.
	 *
	 * ⛔ Versioned with filemtime(), NOT XPAY_WC_VERSION. A JS change that ships
	 * without a version bump would otherwise be served stale from every browser
	 * and CDN cache — we lost time to exactly this on the product-images plugin.
	 * filemtime changes whenever the file does, version bump or not.
	 */
	public function enqueue_beacon() {
		if ( is_admin() || ! self::beacon_enabled() ) {
			return;
		}
		// Don't add the REST hit to the cart/checkout critical path — attribution
		// is already resolved by the time a shopper reaches those pages.
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
			return;
		}
		$file = XPAY_WC_PATH . self::SCRIPT_REL;
		if ( ! file_exists( $file ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			XPAY_WC_URL . self::SCRIPT_REL,
			array(),
			self::asset_ver( $file ),
			true // footer
		);
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'XpayAttr',
			array( 'endpoint' => esc_url_raw( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) ) )
		);
	}

	/** filemtime-based asset version, falling back to the plugin version. */
	private static function asset_ver( $file ) {
		$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- file_exists checked by caller; a stat failure must not fatal.
		return $mtime ? (string) $mtime : XPAY_WC_VERSION;
	}

	public function register_rest() {
		if ( ! self::beacon_enabled() ) {
			return; // no endpoint on a disconnected/disabled store — shrinks the attack surface.
		}
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'rest_classify' ),
				// Guest checkout is the entire point — a shopper arriving from
				// ChatGPT is not logged in. Hardening instead of auth: strict
				// same-origin check, strict input validation, a payload cap, and
				// we never store anything a visitor sends us verbatim.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * POST /wp-json/xpay/v1/classify
	 *
	 * Runs the SAME priority chain as the PHP classifier (detect_from) against
	 * signals collected in the browser. Returns 204 always — this endpoint
	 * exists to record, never to reveal. It tells a caller nothing about what
	 * was matched or stored.
	 */
	public function rest_classify( $request ) {
		// Same-origin only. A cross-site caller has no business seeding a
		// shopper's attribution record. (Header-based, so not a hard control
		// against non-browsers — the throttle below is the real DoS guard.)
		if ( ! $this->is_same_origin( $request ) ) {
			return new WP_REST_Response( null, 403 );
		}

		// Per-IP throttle: the endpoint is unauthenticated and each accepted
		// match can mint a WC session row, so cap how fast one caller can drive it.
		if ( ! $this->beacon_rate_ok() ) {
			return new WP_REST_Response( null, 429 );
		}

		$raw = (string) $request->get_body();
		if ( strlen( $raw ) > self::MAX_BEACON_BYTES ) {
			return new WP_REST_Response( null, 413 );
		}

		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$xpay_ref = isset( $body['xpay_ref'] ) ? sanitize_text_field( (string) $body['xpay_ref'] ) : '';
		$utm      = isset( $body['utm_source'] ) ? sanitize_text_field( (string) $body['utm_source'] ) : '';
		$referrer = isset( $body['referrer'] ) ? sanitize_url( (string) $body['referrer'] ) : '';
		$landing  = isset( $body['landing'] ) ? sanitize_text_field( (string) $body['landing'] ) : '';
		// The UA of the beacon request is the shopper's own browser UA.
		$ua = (string) $request->get_header( 'user_agent' );

		$candidate = $this->detect_from( $xpay_ref, $utm, $referrer, $ua, $landing );
		if ( ! $candidate ) {
			return new WP_REST_Response( null, 204 );
		}

		$this->maybe_store( $candidate );
		return new WP_REST_Response( null, 204 );
	}

	/** True when the request's Origin (or Referer) is this site. */
	private function is_same_origin( $request ) {
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$home = is_string( $home ) ? strtolower( $home ) : '';
		if ( '' === $home ) {
			return false;
		}

		$origin = (string) $request->get_header( 'origin' );
		if ( '' !== $origin ) {
			return strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) === $home;
		}

		// Some browsers omit Origin on same-origin sendBeacon; fall back to Referer.
		$referer = (string) $request->get_header( 'referer' );
		if ( '' !== $referer ) {
			return strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) ) === $home;
		}

		return false;
	}

	public function classify() {
		if ( is_admin() ) {
			return;
		}
		$candidate = $this->detect();
		if ( ! $candidate ) {
			return;
		}
		$this->maybe_store( $candidate );
	}

	/**
	 * First-touch storage with confidence ranking, shared by the PHP classifier
	 * and the REST beacon.
	 *
	 * First-touch wins: an equal-or-lower-confidence signal never displaces what
	 * we already recorded. Checks the cookie AND the session — on EU stores the
	 * cookie is off by default, so the session is the only existing record and
	 * ignoring it would let a later weak signal overwrite a strong first touch.
	 */
	private function maybe_store( $candidate ) {
		$existing = $this->read_cookie();
		if ( ! $existing ) {
			$existing = $this->read_session();
		}
		if ( $existing && $this->confidence_rank( $candidate['confidence'] ) <= $this->confidence_rank( $existing['confidence'] ) ) {
			return false;
		}

		// SESSION is always the primary store — functional, GDPR-exempt under
		// CNIL "strictly necessary" reasoning since the merchant uses it for
		// order-attribution reporting on their own checkout.
		$this->write_session( $candidate );

		// COOKIE is a 30-day persistence layer; it carries attribution across
		// sessions but is NON-ESSENTIAL under GDPR/CNIL. Default OFF for EU
		// base countries unless the merchant explicitly opts in.
		if ( $this->should_set_cookie( $candidate ) ) {
			$this->write_cookie( $candidate );
		}
		return true;
	}

	/**
	 * Resolve cookie consent. Returns true iff the store opts in.
	 *
	 * Decision order:
	 *   1. `xpay_wc_attribution_should_set_cookie` filter (merchant override).
	 *   2. `xpay_wc_attribution_cookie_enabled` option (admin toggle, if set).
	 *   3. Default: ON for non-EU base countries, OFF for EU base countries.
	 *
	 * This is intentionally conservative — for EU stores the WC session
	 * (~48h) still provides attribution within a single shopping journey;
	 * only the cross-session (30-day) memory is dropped without consent.
	 */
	private function should_set_cookie( $candidate ) {
		$option = get_option( 'xpay_wc_attribution_cookie_enabled', null );
		if ( null !== $option ) {
			$resolved = (bool) $option;
		} else {
			$resolved = ! $this->is_eu_base_country();
		}
		/**
		 * Filter the attribution-cookie decision per shopper.
		 *
		 * @param bool  $resolved Whether to write the _xpay_ref cookie.
		 * @param array $candidate The detected attribution record.
		 */
		return (bool) apply_filters( 'xpay_wc_attribution_should_set_cookie', $resolved, $candidate );
	}

	/**
	 * EU + UK base country list (CNIL/GDPR/PECR scope). Static — not worth a
	 * remote lookup; the merchant's WC base country only changes when they
	 * relocate.
	 */
	private function is_eu_base_country() {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return false;
		}
		$base = strtoupper( (string) WC()->countries->get_base_country() );
		$eu = array(
			'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
			'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
			'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'GB', 'IS', 'LI', 'NO',
		);
		return in_array( $base, $eu, true );
	}

	/**
	 * Detect the inbound channel from this request. Returns a record array
	 * or null if no signal matched (organic / direct).
	 *
	 * Thin wrapper: it pulls the signals out of the PHP superglobals and hands
	 * them to detect_from(). The REST beacon (Xpay_Attribution::rest_classify)
	 * calls detect_from() with signals collected in the BROWSER instead — same
	 * priority chain, two transports. That's the whole point: on a full-page-
	 * cached store this PHP path never runs at all.
	 */
	private function detect() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$xpay_ref = isset( $_GET['xpay_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['xpay_ref'] ) ) : '';
		$utm      = isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$referer  = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$ua       = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		// On the PHP path we have real query context, so use is_product() when
		// available; the beacon falls back to a path shape (see detect_from).
		$landing = ( function_exists( 'is_product' ) && is_product() )
			? '/__is_product__'
			: ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );

		return $this->detect_from( $xpay_ref, $utm, $referer, $ua, $landing );
	}

	/**
	 * The shared priority chain. Accepts INJECTED signals so the same rules run
	 * whether they arrived via PHP superglobals (uncached page, logged-in user)
	 * or via the JS beacon (cached page — i.e. almost always, in production).
	 *
	 * @param string $xpay_ref     `xpay_ref` query value.
	 * @param string $utm          `utm_source` query value.
	 * @param string $referrer     Full referrer URL.
	 * @param string $ua           User-Agent.
	 * @param string $landing_path Landing path (or the '/__is_product__' sentinel).
	 */
	private function detect_from( $xpay_ref, $utm, $referrer, $ua, $landing_path ) {
		$ruleset = $this->ruleset();

		// 1. xpay_ref — the deterministic stamp on links we control (sidecar,
		// MCP, widget, /go handoff). Highest confidence: nobody else emits it.
		if ( $xpay_ref && preg_match( '/^[A-Za-z0-9._:-]{1,64}$/', $xpay_ref ) ) {
			return $this->record( $xpay_ref, 'high', 'stamp' );
		}

		// 2. utm_source — only ChatGPT reliably passes one today, but the rule
		// table lets us match any future LLM that adopts the convention.
		$utm = strtolower( (string) $utm );
		if ( '' !== $utm && isset( $ruleset['utm_sources'][ $utm ] ) ) {
			return $this->record( $ruleset['utm_sources'][ $utm ], 'high', 'utm' );
		}

		// 3. Referer host match — the same matcher Xpay_Order_Events runs WC
		// core's captured referrer through, so both paths classify identically.
		if ( $referrer ) {
			$match = self::match_referrer( $referrer );
			if ( $match ) {
				return $this->record( $match, 'high', 'referer' );
			}
		}

		// 4. UA fingerprint — in-app / summary-panel fetches.
		if ( $ua ) {
			$hit = self::match_ua( $ua, $ruleset );
			if ( $hit ) {
				return $this->record( $hit, 'high', 'ua' );
			}
		}

		// 5. Heuristic — the agentic-BROWSER signature, and the honest limit of
		// what we can know. ChatGPT Atlas presents as stock Chrome and its iOS
		// app strips the referrer, so NO user-agent rule can ever catch them.
		// What's left is the shape: no referrer at all, landing cold on a deep
		// product page. On a live store we saw a substantial share of orders arrive
		// exactly like this, straight onto deep URLs nobody types from memory.
		//
		// ⛔ This is a SIGNAL, NOT A CLAIM. It is emitted at LOW confidence with
		// its own method so the dashboard can show it as a separate
		// "likely AI (unconfirmed)" band. It must NEVER be folded into
		// attributed revenue: our own sidecar check found no realtime-agent
		// trail for 7 of those orders.
		if ( '' === $referrer && $this->is_deep_product_landing( $landing_path ) ) {
			return $this->record( 'unknown-ai', 'low', 'heuristic_direct_deep_entry' );
		}

		return null;
	}

	/**
	 * UA matching, in two buckets.
	 *
	 * `uas` is substring-matched. `uas_exact` is EXACT-matched — Gemini's
	 * realtime fetch UA is the bare string "Google", and a substring test for
	 * that would also match "Googlebot" and mislabel every Google crawl as an
	 * AI referral, poisoning the merchant's SEO attribution. Exact only.
	 * (Mirrors the sidecar's EXACT_AI_REALTIME_UAS handling.)
	 */
	private static function match_ua( $ua, $ruleset ) {
		$trimmed = trim( (string) $ua );

		if ( ! empty( $ruleset['uas_exact'] ) ) {
			foreach ( $ruleset['uas_exact'] as $needle => $source ) {
				if ( $trimmed === $needle ) {
					return $source;
				}
			}
		}

		if ( ! empty( $ruleset['uas'] ) ) {
			foreach ( $ruleset['uas'] as $needle => $source ) {
				if ( false !== stripos( $trimmed, $needle ) ) {
					return $source;
				}
			}
		}

		return null;
	}

	/**
	 * Does this landing path look like a deep product page?
	 *
	 * Locale-aware: a WooCommerce store on a French permalink base serves
	 * products under /produit/, and a multilingual store additionally prefixes the
	 * locale (/it/produit/…), so an English-only `/product/` test would score ~0 on
	 * exactly the stores this heuristic exists for.
	 */
	private function is_deep_product_landing( $landing_path ) {
		$path = (string) $landing_path;
		if ( '' === $path ) {
			return false;
		}
		// PHP path passes this sentinel when WP's own is_product() said yes.
		if ( '/__is_product__' === $path ) {
			return true;
		}

		$path = strtolower( explode( '?', $path, 2 )[0] );
		$path = explode( '#', $path, 2 )[0];

		$bases = array(
			'product', 'produit', 'produits', 'producto', 'productos',
			'produkt', 'prodotto', 'produto',
		);
		foreach ( explode( '/', trim( $path, '/' ) ) as $segment ) {
			if ( in_array( $segment, $bases, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * On checkout create-order, copy the resolved attribution onto the order
	 * as meta. Xpay_Order_Events reads this on payment_complete.
	 *
	 * Cookie is the primary source; session is the fallback for cookie-blockers.
	 */
	public function tag_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// Don't clobber a cart-deeplink record — Xpay_Cart::tag_order writes
		// `_xpay_source = agent_cart_deeplink` and that's the higher-precedence
		// path. We only stamp the inbound record on this meta key, which
		// Xpay_Order_Events checks only when source meta isn't a deeplink.
		$record = $this->read_cookie() ?: $this->read_session();
		if ( ! $record ) {
			return;
		}
		$order->update_meta_data( self::ORDER_META_KEY, $record );
	}

	// ─── helpers ──────────────────────────────────────────────────────────

	private function record( $source, $confidence, $method ) {
		return array(
			'source'     => (string) $source,
			'confidence' => (string) $confidence,
			'method'     => (string) $method,
			'ts'         => time(),
		);
	}

	private function confidence_rank( $c ) {
		switch ( $c ) {
			case 'high':
				return 3;
			case 'medium':
				return 2;
			case 'low':
				return 1;
		}
		return 0;
	}

	private function read_cookie() {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw is JSON which we json_decode + structurally validate; sanitize_text_field would corrupt the JSON delimiters.
		$raw = wp_unslash( $_COOKIE[ self::COOKIE_NAME ] );
		if ( ! is_string( $raw ) ) {
			return null;
		}
		// Cap the input size as a cheap defence — a legit cookie value is ~140 bytes.
		if ( strlen( $raw ) > 1024 ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['source'] ) ) {
			return null;
		}
		return $decoded;
	}

	private function write_cookie( $record ) {
		if ( headers_sent() ) {
			return;
		}
		$value = wp_json_encode( $record );
		if ( ! $value ) {
			return;
		}
		setcookie(
			self::COOKIE_NAME,
			$value,
			array(
				'expires'  => time() + ( self::COOKIE_TTL_DAYS * DAY_IN_SECONDS ),
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		// Mirror into the current request scope so subsequent reads in the
		// same pageload see it (PHP doesn't refresh $_COOKIE after setcookie).
		$_COOKIE[ self::COOKIE_NAME ] = $value;
	}

	private function read_session() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$record = WC()->session->get( self::SESSION_KEY );
		return is_array( $record ) && ! empty( $record['source'] ) ? $record : null;
	}

	/**
	 * Persist onto the WC session.
	 *
	 * ⛔ GOTCHA: on a REST request WC()->session is NULL. WooCommerce only builds
	 * the session handler for `is_request('frontend')`, and that check explicitly
	 * EXCLUDES REST — so on the beacon path (which is the ONLY path that runs on
	 * a cached store) a naive WC()->session->set() silently no-ops and the whole
	 * fix does nothing. WooCommerce's own Store API works around this the same
	 * way: initialize the session explicitly.
	 *
	 * ⛔⛔ COST OF FORCING A SESSION COOKIE. Minting `wp_woocommerce_session_*` for
	 * a visitor who had no cart does two expensive things: (1) Cloudflare APO,
	 * Varnish and LiteSpeed all BYPASS their edge cache for any request carrying
	 * that cookie, so every subsequent pageview by that visitor pulls from origin;
	 * (2) it writes a row to `wp_woocommerce_sessions`. Both are fine for a
	 * genuine AI referral (small, high-value population). They are NOT fine for
	 * the low-confidence direct-deep-PDP heuristic, which fires for ordinary
	 * bookmark / type-in / dark-referrer traffic — a large population that would
	 * then all become uncacheable. So: only MINT a session (+ force the cookie)
	 * for a real match (stamp / utm / referer / ua). For the low-confidence
	 * heuristic we record ONLY if a session already exists — never create one.
	 * (The deterministic order-time signal for that case is WC core's own
	 * `source_type=typein` + entry_path, captured with zero cache/cookie cost.)
	 */
	private function write_session( $record ) {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$is_low = isset( $record['confidence'] ) && 'low' === $record['confidence'];

		// Only bootstrap a brand-new session for a genuine (non-heuristic) match.
		if ( ! WC()->session && ! $is_low && method_exists( WC(), 'initialize_session' ) ) {
			WC()->initialize_session();
		}
		if ( ! WC()->session ) {
			return; // low-confidence with no existing session → nothing durable, by design.
		}

		WC()->session->set( self::SESSION_KEY, $record );

		// Force the cache-busting cookie ONLY for a real match. Never for the
		// heuristic — see the cache-cost note above.
		if ( ! $is_low && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	private static function host_of( $url ) {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $h ) ? strtolower( $h ) : '';
	}

	private static function path_of( $url ) {
		$p = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $p ) ? $p : '';
	}

	/**
	 * Match a referrer URL against the host ruleset. Returns the source name
	 * ('chatgpt', 'perplexity', …) or null when nothing matches.
	 *
	 * Public + static so Xpay_Order_Events can run WooCommerce core's captured
	 * referrer (`_wc_order_attribution_referrer`) through the SAME matcher at
	 * order-event time, instead of duplicating the rules. One ruleset, one
	 * matcher, two callers — plugin and order-event classify identically.
	 */
	public static function match_referrer( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return null;
		}
		$host = self::host_of( $url );
		if ( '' === $host ) {
			return null;
		}
		$path    = self::path_of( $url );
		$ruleset = self::instance()->ruleset();
		foreach ( $ruleset['hosts'] as $pattern => $source ) {
			if ( self::host_matches( $pattern, $host, $path ) ) {
				return $source;
			}
		}
		return null;
	}

	/**
	 * Pattern formats: `host.tld` (host suffix match) or `host.tld/path-prefix`.
	 * Suffix match so `chat.openai.com` and `openai.com/share/...` both attribute
	 * to the same source without needing to enumerate every subdomain.
	 */
	private static function host_matches( $pattern, $host, $path ) {
		$pattern = strtolower( $pattern );
		if ( false !== strpos( $pattern, '/' ) ) {
			list( $p_host, $p_path ) = explode( '/', $pattern, 2 );
			if ( ! self::host_suffix( $p_host, $host ) ) {
				return false;
			}
			return 0 === strpos( ltrim( $path, '/' ), ltrim( $p_path, '/' ) );
		}
		return self::host_suffix( $pattern, $host );
	}

	private static function host_suffix( $pattern, $host ) {
		if ( $pattern === $host ) {
			return true;
		}
		$dotted = '.' . $pattern;
		return strlen( $host ) > strlen( $dotted )
			&& substr( $host, -strlen( $dotted ) ) === $dotted;
	}

	/**
	 * Allow an admin-cached/remote ruleset to win (`xpay_wc_llm_referrer_rules`
	 * transient, refreshable via `clear_llm_referrer_rules` admin/refresh
	 * action — not yet wired in v0.4.3). Falls through to the default below.
	 */
	private function ruleset() {
		$remote = get_transient( 'xpay_wc_llm_referrer_rules' );
		if ( is_array( $remote ) && ! empty( $remote['hosts'] ) ) {
			return $remote;
		}
		return self::default_ruleset();
	}

	/**
	 * Seeded list — covers ~99% of measurable AI-referral traffic per the
	 * MalteBerlin/LLM-Referrer repository + GA4's May-2026 "AI Assistant"
	 * default channel definition. Source-name strings should stay stable;
	 * dashboards may key off them.
	 */
	public static function default_ruleset() {
		return array(
			'hosts' => array(
				'chatgpt.com'           => 'chatgpt',
				'chat.openai.com'       => 'chatgpt',
				'openai.com'            => 'chatgpt',
				'perplexity.ai'         => 'perplexity',
				'claude.ai'             => 'claude',
				'gemini.google.com'     => 'gemini',
				'bard.google.com'       => 'gemini',
				'copilot.microsoft.com' => 'copilot',
				'bing.com/chat'         => 'copilot',
				'meta.ai'               => 'meta-ai',
				'you.com'               => 'you-com',
				'deepseek.com'          => 'deepseek',
				'grok.com'              => 'grok',
				'phind.com'             => 'phind',
				'poe.com'               => 'poe',
				'chat.mistral.ai'       => 'mistral',
				'huggingface.co/chat'   => 'huggingchat',
				'kagi.com'              => 'kagi',
				'duckduckgo.com/aichat' => 'ddg-ai',
				'lmarena.ai'            => 'lmarena',
				'komo.ai'               => 'komo',
			),
			// SUBSTRING-matched (see match_ua). Only put tokens here that are
			// distinctive enough that a substring hit cannot be a false positive.
			//
			// ⛔ 'Google-Extended' was REMOVED: it is a robots.txt opt-out token,
			// not a visitor user-agent. It never appears on a real request — it
			// was a dead rule matching nothing.
			'uas' => array(
				'OAI-SearchBot'  => 'chatgpt',
				'ChatGPT-User'   => 'chatgpt',
				'PerplexityBot'  => 'perplexity',
				'ClaudeBot'      => 'claude',
				'Claude-User'    => 'claude',
				'CopilotBot'     => 'copilot',
			),
			// ⛔⛔ EXACT-matched only. Gemini's realtime fetch UA is the bare
			// string "Google". Substring-matching that would also match
			// "Googlebot" — we would relabel every Google crawl as an AI referral
			// and destroy the merchant's SEO attribution. Never move this into
			// the substring bucket above.
			'uas_exact' => array(
				'Google' => 'gemini',
			),
			'utm_sources' => array(
				'chatgpt.com'    => 'chatgpt',
				'chatgpt'        => 'chatgpt',
				'perplexity'     => 'perplexity',
				'perplexity.ai'  => 'perplexity',
				'claude'         => 'claude',
				'gemini'         => 'gemini',
				'copilot'        => 'copilot',
			),
		);
	}
}
