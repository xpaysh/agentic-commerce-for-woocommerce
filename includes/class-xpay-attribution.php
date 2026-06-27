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

	private function __construct() {
		// Run on template_redirect so we have is_product()/is_shop() context
		// without the front-page query overhead.
		add_action( 'template_redirect', array( $this, 'classify' ), 5 );
		// Mirror onto the order at create-time. Priority 5 keeps it ahead of
		// Xpay_Cart::tag_order() (priority 10) so cart-deeplink attribution
		// stays the higher-precedence record.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'tag_order' ), 5, 1 );
	}

	public function classify() {
		if ( is_admin() ) {
			return;
		}

		// Already attributed (first-touch wins) — skip unless the new signal
		// is HIGHER confidence than what we already have.
		$existing = $this->read_cookie();

		$candidate = $this->detect();
		if ( ! $candidate ) {
			return;
		}

		if ( $existing && $this->confidence_rank( $candidate['confidence'] ) <= $this->confidence_rank( $existing['confidence'] ) ) {
			// Keep first-touch. Lower or equal confidence doesn't displace.
			return;
		}

		// SESSION is always the primary store — functional, GDPR-exempt under
		// CNIL "strictly necessary" reasoning since the merchant uses it for
		// order-attribution reporting on their own checkout.
		$this->write_session( $candidate );

		// COOKIE is a 30-day persistence layer; it carries attribution across
		// sessions but is NON-ESSENTIAL under GDPR/CNIL. Default OFF for EU
		// base countries unless the merchant explicitly opts in via filter or
		// the `xpay_wc_attribution_cookie_enabled` option. Non-EU stores keep
		// the original behaviour (cookie ON by default).
		if ( $this->should_set_cookie( $candidate ) ) {
			$this->write_cookie( $candidate );
		}
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
	 */
	private function detect() {
		$ruleset = $this->ruleset();

		// 1. xpay_ref query — deterministic stamp from links we control.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['xpay_ref'] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_GET['xpay_ref'] ) );
			if ( $raw && preg_match( '/^[A-Za-z0-9._:-]{1,64}$/', $raw ) ) {
				return $this->record( $raw, 'high', 'stamp' );
			}
		}

		// 2. utm_source — only ChatGPT-style passes one today, but the rule
		// table lets us match any future LLM that adopts the convention.
		if ( ! empty( $_GET['utm_source'] ) ) {
			$utm = strtolower( sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) );
			if ( $utm && isset( $ruleset['utm_sources'][ $utm ] ) ) {
				return $this->record( $ruleset['utm_sources'][ $utm ], 'high', 'utm' );
			}
		}

		// 3. Referer host match.
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( $referer ) {
			$host = $this->host_of( $referer );
			$path = $this->path_of( $referer );
			foreach ( $ruleset['hosts'] as $pattern => $source ) {
				if ( $this->host_matches( $pattern, $host, $path ) ) {
					return $this->record( $source, 'high', 'referer' );
				}
			}
		}

		// 4. UA fingerprint — for in-app or summary-panel embeds.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( $ua ) {
			foreach ( $ruleset['uas'] as $needle => $source ) {
				if ( false !== stripos( $ua, $needle ) ) {
					return $this->record( $source, 'high', 'ua' );
				}
			}
		}

		// 5. Heuristic — empty referrer + cross-site fetch + a direct PDP
		// landing. Marked LOW confidence so dashboards can hide it by default.
		$sec_fetch_site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';
		$sec_fetch_dest = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) : '';
		if ( '' === $referer && 'cross-site' === $sec_fetch_site && 'document' === $sec_fetch_dest && function_exists( 'is_product' ) && is_product() ) {
			return $this->record( 'unknown-ai', 'low', 'heuristic' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return null;
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

	private function write_session( $record ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		WC()->session->set( self::SESSION_KEY, $record );
	}

	private function host_of( $url ) {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $h ) ? strtolower( $h ) : '';
	}

	private function path_of( $url ) {
		$p = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $p ) ? $p : '';
	}

	/**
	 * Pattern formats: `host.tld` (host suffix match) or `host.tld/path-prefix`.
	 * Suffix match so `chat.openai.com` and `openai.com/share/...` both attribute
	 * to the same source without needing to enumerate every subdomain.
	 */
	private function host_matches( $pattern, $host, $path ) {
		$pattern = strtolower( $pattern );
		if ( false !== strpos( $pattern, '/' ) ) {
			list( $p_host, $p_path ) = explode( '/', $pattern, 2 );
			if ( ! $this->host_suffix( $p_host, $host ) ) {
				return false;
			}
			return 0 === strpos( ltrim( $path, '/' ), ltrim( $p_path, '/' ) );
		}
		return $this->host_suffix( $pattern, $host );
	}

	private function host_suffix( $pattern, $host ) {
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
			),
			'uas' => array(
				'OAI-SearchBot'  => 'chatgpt',
				'ChatGPT-User'   => 'chatgpt',
				'PerplexityBot'  => 'perplexity',
				'ClaudeBot'      => 'claude',
				'Claude-User'    => 'claude',
				'Google-Extended' => 'gemini',
				'CopilotBot'     => 'copilot',
			),
			'utm_sources' => array(
				'chatgpt.com'  => 'chatgpt',
				'chatgpt'      => 'chatgpt',
				'perplexity'   => 'perplexity',
				'claude'       => 'claude',
				'gemini'       => 'gemini',
				'copilot'      => 'copilot',
			),
		);
	}
}
