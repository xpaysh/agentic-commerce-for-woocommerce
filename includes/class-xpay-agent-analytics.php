<?php
/**
 * Agent-discovery analytics (v1 — bot crawl, non-personal).
 *
 * Records a lightweight, privacy-safe event whenever a *known AI bot* fetches a
 * front-end URL, so the merchant (and we) can see how AI agents discover and
 * crawl their store — especially whether agents actually find the discovery
 * surfaces (/llms.txt, /agents.md, /.well-known/*, /sitemap*).
 *
 * SAFETY / PERFORMANCE CONTRACT (this class must never slow a human request or
 * break a store):
 *   - ZERO synchronous network or DB work on the hot path. Capture runs on the
 *     `shutdown` hook — AFTER the response has been sent to the visitor. Bots are
 *     recorded per-event; humans are NOT — we keep only an aggregate daily
 *     pageview COUNT (the denominator for the bot-vs-human ratio), incremented in
 *     one option, no per-visit row.
 *   - Events are buffered in a single (autoload=off) option with a HARD CAP, and
 *     flushed in batches by WP-Cron. The merchant's own server never blocks on
 *     our backend.
 *   - Opt-in only: gated behind the SAME telemetry consent the merchant already
 *     granted (Xpay_Telemetry::is_enabled()). Off by default. A sysadmin can
 *     hard-disable just this subsystem with
 *     `define( 'XPAY_WC_AGENT_ANALYTICS', false )` even if telemetry is on.
 *   - No human PII, ever. Bots: UA token, bot class, request path (query string
 *     stripped), coarse path_type, HTTP status, deflected flag. Humans: a daily
 *     count only — no UA, no path, no per-visit data. Commerce-flow paths
 *     (cart/checkout/account/admin/REST) are excluded for everyone.
 *   - Never throws. Every path is wrapped so analytics can never fatal a request.
 *
 * What we send and when is documented under "External services" in readme.txt.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Agent_Analytics {

	const ENDPOINT_PATH   = '/v1/agent-analytics';
	const BUFFER_OPTION   = 'xpay_wc_agent_buf';      // array of buffered events (autoload off)
	const DROPPED_OPTION  = 'xpay_wc_agent_dropped';  // counter of events dropped at cap
	const HUMAN_OPTION    = 'xpay_wc_human_daily';    // map of YYYY-MM-DD => human pageview count (the bot-vs-human denominator)
	const IP_SALT_OPTION  = 'xpay_wc_ip_salt';        // { day, salt } — rotates daily (autoload off)
	const FLUSH_HOOK      = 'xpay_wc_agent_analytics_flush';
	const SCHEDULE_NAME   = 'xpay_wc_15min';
	const BUFFER_HARD_CAP = 200;   // max events held between flushes; overflow is dropped + counted
	const FLUSH_BATCH_MAX = 200;   // max events POSTed per flush

	/**
	 * Front-end paths we never record (commerce flow + non-public surfaces).
	 * Prefix match, lowercased. Mirrors the deflection exclude set; checkout and
	 * account flows must never be captured for any visitor.
	 */
	const EXCLUDE_PREFIXES = array(
		'/wp-admin',
		'/wp-login',
		'/wp-json',
		'/wp-content',
		'/wp-includes',
		'/cart',
		'/checkout',
		'/my-account',
		'/feed',
	);

	/**
	 * Known AI user-agents → bot class. The single source of truth for capture.
	 * Realtime shopping fetchers, training crawlers, search/index crawlers, and
	 * general crawlers. Matched case-insensitively as a substring of the UA.
	 *
	 * Buckets are realtime | training | search | crawler. Mirrors the intent
	 * split used by deflection (realtime shoppers are the ones we deflect to the
	 * structured sidecar; search/training stay on origin for citations).
	 */
	private static function bot_map() {
		return array(
			// Realtime AI shopping / assistant fetchers (user-initiated).
			'ChatGPT-User'          => 'realtime',
			'Claude-User'           => 'realtime',
			'Perplexity-User'       => 'realtime',
			'MistralAI-User'        => 'realtime',
			'DuckAssistBot'         => 'realtime',
			'Meta-ExternalFetcher'  => 'realtime',
			// Training / dataset crawlers.
			'GPTBot'                => 'training',
			'ClaudeBot'             => 'training',
			'anthropic-ai'          => 'training',
			'Google-Extended'       => 'training',
			'Applebot-Extended'     => 'training',
			'CCBot'                 => 'training',
			'Bytespider'            => 'training',
			'Amazonbot'             => 'training',
			'Meta-ExternalAgent'    => 'training',
			'cohere-ai'             => 'training',
			'Diffbot'               => 'training',
			'omgili'                => 'training',
			'Timpibot'              => 'training',
			'PanguBot'              => 'training',
			// Search / answer-engine index crawlers.
			'OAI-SearchBot'         => 'search',
			'Claude-SearchBot'      => 'search',
			'PerplexityBot'         => 'search',
			'Applebot'              => 'search',
			'YouBot'                => 'search',
			'Google-CloudVertexBot' => 'search',
		);
	}

	public static function register() {
		// Custom 15-minute cron cadence for batch flushes.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );

		// Capture on shutdown — after the response is flushed to the visitor, so
		// it can never add latency to a page load. Runs even when an emitter or
		// the deflector called exit(), because PHP still runs shutdown functions.
		add_action( 'shutdown', array( __CLASS__, 'maybe_capture' ), 0 );

		// Background batch flush — never on the hot request path.
		add_action( self::FLUSH_HOOK, array( __CLASS__, 'flush' ) );
		if ( ! wp_next_scheduled( self::FLUSH_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE_NAME, self::FLUSH_HOOK );
		}
	}

	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE_NAME ] ) ) {
			$schedules[ self::SCHEDULE_NAME ] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (xpay agent analytics)', 'agentic-commerce-for-woocommerce' ),
			);
		}
		return $schedules;
	}

	public static function unregister_cron() {
		$ts = wp_next_scheduled( self::FLUSH_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::FLUSH_HOOK );
		}
		// Best-effort: flush whatever is buffered before we go quiet.
		self::flush();
	}

	/**
	 * Whether crawl analytics may run at all. Reuses the merchant's existing
	 * telemetry opt-in; an extra constant lets a sysadmin disable just this.
	 */
	public static function is_enabled() {
		if ( defined( 'XPAY_WC_AGENT_ANALYTICS' ) && false === XPAY_WC_AGENT_ANALYTICS ) {
			return false;
		}
		return Xpay_Telemetry::is_enabled();
	}

	/**
	 * Classify a UA into a bot class, or '' if it isn't a known AI bot.
	 * Cheap substring scan; this is the gate that keeps humans/other bots free.
	 */
	public static function classify_bot( $ua ) {
		if ( ! is_string( $ua ) || '' === $ua ) {
			return '';
		}
		foreach ( self::bot_map() as $token => $class ) {
			if ( false !== stripos( $ua, $token ) ) {
				return $class;
			}
		}
		return '';
	}

	/**
	 * Coarse, non-identifying classification of a request path. Lowercased path
	 * (no query string) in; one of the path_type buckets out.
	 */
	public static function classify_path( $path ) {
		if ( '' === $path || '/' === $path ) {
			return 'home';
		}
		// Discovery surfaces we most want to know agents are reaching.
		if (
			0 === strpos( $path, '/llms.txt' )
			|| 0 === strpos( $path, '/agents.md' )
			|| 0 === strpos( $path, '/.well-known' )
		) {
			return 'discovery_file';
		}
		if ( 0 === strpos( $path, '/sitemap' ) || 0 === strpos( $path, '/robots.txt' ) ) {
			return 'sitemap';
		}
		// WooCommerce default permalinks. Works for /product/ and /product-category/
		// (and the shop archive). Custom bases are bucketed as "other" — still useful
		// as a volume signal, never misattributed.
		if ( 0 === strpos( $path, '/product/' ) ) {
			return 'product';
		}
		if ( 0 === strpos( $path, '/product-category/' ) || 0 === strpos( $path, '/product-tag/' ) ) {
			return 'category';
		}
		if ( 0 === strpos( $path, '/shop' ) ) {
			return 'category';
		}
		return 'other';
	}

	/**
	 * Shutdown capture. Post-response, so it never touches human latency.
	 * Bails fast and silently for everything that isn't a known AI bot front-end
	 * GET/HEAD on a capturable path.
	 */
	public static function maybe_capture() {
		try {
			if ( ! self::is_enabled() ) {
				return;
			}
			// Never capture admin / cron / CLI / REST requests.
			if ( is_admin() || wp_doing_cron() ) {
				return;
			}
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				return;
			}
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return;
			}
			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				return;
			}
			// Our own discovery self-probe — never count it as a bot crawl.
			if ( ! empty( $_SERVER['HTTP_X_XPAY_PROBE'] ) ) {
				return;
			}

			// Shared front-end gate (applies to both bots and humans).
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
			if ( 'GET' !== $method && 'HEAD' !== $method ) {
				return;
			}

			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path only, used for prefix match + stored, never echoed.
			$uri  = is_string( $uri ) ? $uri : '/';
			$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
			$path = ( '' === $path ) ? '/' : strtolower( $path );

			foreach ( self::EXCLUDE_PREFIXES as $ex ) {
				if ( 0 === strpos( $path, $ex ) ) {
					return; // commerce flow / non-public — never recorded.
				}
			}

			$ua        = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
			$bot_class = self::classify_bot( $ua );

			// Not a known AI bot → maybe count as an aggregate human pageview
			// (the denominator for the bot-vs-human ratio). NO per-visit row, no
			// PII — just a daily counter.
			if ( '' === $bot_class ) {
				self::maybe_count_human( $ua );
				return;
			}

			// Server-driven-ish sampling. Default 1.0 (keep everything). High-volume
			// stores can lower it via the filter to cap write load.
			$sample_rate = (float) apply_filters( 'xpay_wc_agent_analytics_sample_rate', 1.0 );
			if ( $sample_rate < 1.0 ) {
				if ( $sample_rate <= 0.0 ) {
					return;
				}
				// wp_rand(1,1000000)/1000000 in (0,1]; keep when <= rate.
				if ( ( wp_rand( 1, 1000000 ) / 1000000 ) > $sample_rate ) {
					return;
				}
			}

			$status = function_exists( 'http_response_code' ) ? (int) http_response_code() : 0;
			if ( $status <= 0 ) {
				$status = 200; // best-effort default when the SAPI doesn't expose it.
			}

			// Did WE deflect this bot to the structured sidecar? Xpay_Deflection
			// sets this in-memory immediately before its 302; the flag survives
			// into shutdown within the same PHP process.
			$deflected = ! empty( $GLOBALS['xpay_wc_deflected'] );

			// Did WE reverse-proxy this hit to the apex blog from our renderer?
			// Xpay_Blog_Proxy sets this in-memory immediately before its exit.
			$bp             = isset( $GLOBALS['xpay_wc_blog_proxied'] ) && is_array( $GLOBALS['xpay_wc_blog_proxied'] ) ? $GLOBALS['xpay_wc_blog_proxied'] : null;
			$blog_proxied   = $bp ? 1 : 0;
			$bp_cache_hit   = ( $bp && ! empty( $bp['cache_hit'] ) ) ? 1 : 0;

			$event = array(
				'ts'          => time(),
				'ua_bot'      => substr( self::matched_token( $ua ), 0, 48 ),
				'bot_class'   => $bot_class,
				'path'        => substr( $path, 0, 256 ),
				'path_type'   => self::classify_path( $path ),
				'status'      => $status,
				'deflected'   => $deflected ? 1 : 0,
				'blog_proxied' => $blog_proxied,
				'bp_cache_hit' => $bp_cache_hit,
				// A salted, truncated one-way hash — NEVER the IP itself. Without
				// it, ip_hash is NULL on 100% of `wp`-surface rows and agent
				// sessionization is impossible on the merchant's own origin (we
				// can only sessionize on the sidecar today). The salt is
				// per-store AND rotates daily, so a hash is not linkable across
				// stores or across days.
				'ip_hash'     => self::ip_hash(),
			);

			self::buffer( $event );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $e ); // Analytics must never break the host site. Swallow.
		}
	}

	/**
	 * Return the specific bot token that matched (so the dashboard shows
	 * "ChatGPT-User", not the whole UA string). Falls back to '' (shouldn't
	 * happen — caller only invokes this after classify_bot matched).
	 */
	/**
	 * Salted, truncated one-way hash of the visitor's IP — a coarse
	 * unique-session signal. The raw IP is never stored, never buffered, and
	 * never leaves the store.
	 *
	 * Privacy properties, deliberately stronger than a plain hash:
	 *   - the salt is generated PER STORE, so the same visitor on two xpay
	 *     merchants produces two unrelated hashes;
	 *   - the salt ROTATES DAILY, so a hash cannot be correlated across days —
	 *     it supports "how many distinct agents visited today" and nothing more
	 *     durable than that.
	 *
	 * Returns '' when no IP is available; the ingest stores NULL for that.
	 */
	private static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		if ( '' === $ip ) {
			return '';
		}
		return substr( hash( 'sha256', self::ip_salt() . '|' . $ip ), 0, 16 );
	}

	/** Per-store, daily-rotating salt. Autoload off — read only on capture. */
	private static function ip_salt() {
		$today  = gmdate( 'Y-m-d' );
		$stored = get_option( self::IP_SALT_OPTION );

		if ( is_array( $stored ) && isset( $stored['day'], $stored['salt'] ) && $stored['day'] === $today && '' !== $stored['salt'] ) {
			return (string) $stored['salt'];
		}

		$salt = wp_generate_password( 32, false, false );
		update_option( self::IP_SALT_OPTION, array( 'day' => $today, 'salt' => $salt ), false );
		return $salt;
	}

	private static function matched_token( $ua ) {
		foreach ( self::bot_map() as $token => $class ) {
			if ( false !== stripos( $ua, $token ) ) {
				return $token;
			}
		}
		return '';
	}

	/**
	 * Count a non-AI-bot request as a human pageview if it looks like a real
	 * browser hit. Excludes classic crawlers (Googlebot/Bingbot/etc.) and
	 * non-browser agents so the denominator is humans, not other bots. Aggregate
	 * count only — no path, no PII, no per-visit row.
	 */
	private static function maybe_count_human( $ua ) {
		if ( '' === $ua ) {
			return; // no UA → script/curl, not a human pageview.
		}
		// Drop anything that smells like a bot/crawler/library (we only have the
		// AI-bot allowlist; this catches the rest so they aren't miscounted as human).
		if ( preg_match( '#bot|crawl|spider|slurp|bingpreview|facebookexternal|feedfetcher|monitor|http[s]?://#i', $ua ) ) {
			return;
		}
		// Real page views send an HTML-preferring Accept header; assets/XHR don't.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		if ( false === stripos( $accept, 'text/html' ) ) {
			return;
		}

		// Independent human sample rate (default 1.0). On a high-traffic store,
		// lower it via the filter; we extrapolate by the inverse weight so the
		// stored count stays an unbiased estimate of true pageviews.
		$rate   = (float) apply_filters( 'xpay_wc_agent_analytics_human_sample_rate', 1.0 );
		$weight = 1;
		if ( $rate < 1.0 ) {
			if ( $rate <= 0.0 ) {
				return;
			}
			if ( ( wp_rand( 1, 1000000 ) / 1000000 ) > $rate ) {
				return;
			}
			$weight = (int) max( 1, round( 1.0 / $rate ) );
		}

		self::count_human( $weight );
	}

	/**
	 * Increment today's human pageview counter (UTC). Stored as a small
	 * day=>count map in one autoload=off option, flushed + reset with the batch.
	 */
	private static function count_human( $weight ) {
		$day    = gmdate( 'Y-m-d' );
		$counts = get_option( self::HUMAN_OPTION, array() );
		if ( ! is_array( $counts ) ) {
			$counts = array();
		}
		// Guard against unbounded growth if a flush stalls for weeks.
		if ( ! isset( $counts[ $day ] ) && count( $counts ) >= 14 ) {
			return;
		}
		$counts[ $day ] = ( isset( $counts[ $day ] ) ? (int) $counts[ $day ] : 0 ) + (int) $weight;
		update_option( self::HUMAN_OPTION, $counts, false );
	}

	/**
	 * Append one event to the buffer option, enforcing the hard cap. Some loss
	 * under heavy concurrent bot load is acceptable (this is sampled analytics,
	 * not billing) — we never block to take a lock.
	 */
	private static function buffer( $event ) {
		$buf = get_option( self::BUFFER_OPTION, array() );
		if ( ! is_array( $buf ) ) {
			$buf = array();
		}
		if ( count( $buf ) >= self::BUFFER_HARD_CAP ) {
			// Overflow: drop + count it, and pull the flush forward so we drain soon.
			// WP de-dups identical (hook, args) single events within ~10 min, so we
			// don't need our own guard against piling these up.
			$dropped = (int) get_option( self::DROPPED_OPTION, 0 );
			update_option( self::DROPPED_OPTION, $dropped + 1, false );
			wp_schedule_single_event( time() + 30, self::FLUSH_HOOK );
			return;
		}
		$buf[] = $event;
		update_option( self::BUFFER_OPTION, $buf, false );
	}

	/**
	 * Cron callback: drain the buffer and POST it as a single batch to the
	 * backend. Clears the buffer first so a slow/failing backend never causes
	 * the same events to be re-sent (analytics tolerates loss, not duplication).
	 */
	public static function flush() {
		try {
			if ( ! self::is_enabled() ) {
				// Opt-out happened: discard anything buffered, don't phone home.
				delete_option( self::BUFFER_OPTION );
				delete_option( self::DROPPED_OPTION );
				delete_option( self::HUMAN_OPTION );
				return;
			}

			$buf     = get_option( self::BUFFER_OPTION, array() );
			$buf     = is_array( $buf ) ? $buf : array();
			$human   = get_option( self::HUMAN_OPTION, array() );
			$human   = is_array( $human ) ? $human : array();
			$dropped = (int) get_option( self::DROPPED_OPTION, 0 );

			// Nothing to send (no bot events AND no human counts).
			if ( empty( $buf ) && empty( $human ) ) {
				return;
			}

			// Take the buffers and clear them immediately.
			delete_option( self::BUFFER_OPTION );
			delete_option( self::DROPPED_OPTION );
			delete_option( self::HUMAN_OPTION );

			$events = array_slice( $buf, 0, self::FLUSH_BATCH_MAX );

			$payload = array(
				'site_url'       => home_url( '/' ),
				'merchant_slug'  => Xpay_Plugin::merchant_slug(),
				'plugin_version' => XPAY_WC_VERSION,
				'surface'        => 'wp',
				'dropped'        => $dropped,
				'events'         => $events,
				'human_daily'    => (object) $human,
			);

			if ( defined( 'XPAY_WC_DOGFOOD' ) && true === XPAY_WC_DOGFOOD ) {
				$payload['dogfood'] = true;
			}

			$url = trailingslashit( XPAY_WC_API_BASE ) . ltrim( self::ENDPOINT_PATH, '/' );

			wp_remote_post(
				$url,
				array(
					'method'    => 'POST',
					'timeout'   => 4,
					'blocking'  => false,
					'sslverify' => true,
					'headers'   => array(
						'Content-Type' => 'application/json',
						'User-Agent'   => 'agentic-commerce-for-woocommerce/' . XPAY_WC_VERSION . '; ' . home_url( '/' ),
						'X-Xpay-Site'  => home_url( '/' ),
					),
					'body'      => wp_json_encode( $payload ),
				)
			);
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $e ); // Never let a flush failure surface to the merchant.
		}
	}
}
