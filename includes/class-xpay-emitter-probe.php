<?php
/**
 * Detects whether another plugin / theme / web-server layer is already
 * serving `/llms.txt` or the `/.well-known/*` discovery files. Result
 * shapes how Xpay_Rest::serve_*() behaves:
 *
 *   - /llms.txt (Markdown)  — append our additions to upstream content.
 *   - /.well-known/* (JSON) — skip serving entirely (don't fight, don't
 *                             attempt to deep-merge competing schemas).
 *
 * Detection method: HTTP self-fetch to home_url($path) with a probe
 * header `X-Xpay-Probe: 1`. Our own serve handler short-circuits when
 * that header is present, so the request continues to whatever rewrite
 * resolution would happen without us. If a physical file exists at the
 * webroot, the web server short-circuits before PHP — we'd see the file
 * content. If another plugin registered a handler, we'd see its output.
 * If nothing else serves the path, we get a 404.
 *
 * Cached in a transient for 6 hours. Re-probed daily via WP-Cron so a
 * merchant installing Yoast SEO AI / RankMath AI / AIOSEO / llms-txt
 * after our plugin is auto-detected within ~24h.
 *
 * Hard guarantees:
 *   - Never blocks a merchant-facing request (probe is async-ish, capped
 *     at 3s; failures cache "no upstream" so we fall back to our default
 *     behavior without retrying every request).
 *   - Never sends PII (probe is the merchant's own URL fetched by their
 *     own server).
 *   - Never reads/writes anything outside the transient + a small option
 *     surface.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Emitter_Probe {

	const PROBE_HEADER         = 'X-Xpay-Probe';
	const PROBE_HEADER_VALUE   = '1';
	const TRANSIENT_PREFIX     = 'xpay_wc_emitter_probe_';
	const CACHE_SECONDS        = 6 * HOUR_IN_SECONDS;
	const CACHE_FAIL_SECONDS   = HOUR_IN_SECONDS;
	const PROBE_TIMEOUT        = 3;
	const MAX_UPSTREAM_BYTES   = 64 * 1024; // 64 KB; refuse to merge anything larger.
	const SELF_FINGERPRINT     = 'agent-feed.xpay.sh';
	const CRON_HOOK            = 'xpay_wc_emitter_probe_refresh';

	/**
	 * Whether the current request carries the probe header. Used by
	 * Xpay_Rest::maybe_serve() to short-circuit so the probe sees
	 * what other handlers would serve.
	 */
	public static function request_is_probe() {
		$key = 'HTTP_X_XPAY_PROBE';
		if ( ! isset( $_SERVER[ $key ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			return false;
		}
		$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
		return self::PROBE_HEADER_VALUE === $value;
	}

	/**
	 * Returns the cached probe result for the given emitter path.
	 * Shape:
	 *   array(
	 *     'has_external'  => bool,
	 *     'body'          => string (max MAX_UPSTREAM_BYTES, '' if has_external = false),
	 *     'content_type'  => string,
	 *     'checked_at'    => int (unix ts),
	 *   )
	 *
	 * Returns null if not yet probed (caller should serve the default
	 * behavior — replace — and let the cron pick it up).
	 */
	public static function get_result( $path ) {
		$key = self::TRANSIENT_PREFIX . md5( $path );
		$val = get_transient( $key );
		return is_array( $val ) ? $val : null;
	}

	/**
	 * Force a fresh probe for the given path. Always writes a transient
	 * (success or failure) so we don't hammer the merchant's own host.
	 */
	public static function probe( $path ) {
		$url = trailingslashit( home_url( '/' ) ) . ltrim( $path, '/' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => self::PROBE_TIMEOUT,
				'redirection' => 2,
				'sslverify'  => false, // Localhost dev / staging may not trust own cert; non-blocking.
				'headers'    => array(
					self::PROBE_HEADER => self::PROBE_HEADER_VALUE,
					'User-Agent'       => 'agentic-commerce-for-woocommerce/' . XPAY_WC_VERSION . ' (emitter-probe)',
				),
			)
		);

		$key = self::TRANSIENT_PREFIX . md5( $path );

		if ( is_wp_error( $response ) ) {
			set_transient(
				$key,
				array(
					'has_external' => false,
					'body'         => '',
					'content_type' => '',
					'checked_at'   => time(),
					'error'        => $response->get_error_code(),
				),
				self::CACHE_FAIL_SECONDS
			);
			return false;
		}

		$code         = (int) wp_remote_retrieve_response_code( $response );
		$body         = (string) wp_remote_retrieve_body( $response );
		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$len          = strlen( $body );

		$looks_external = (
			$code >= 200 && $code < 300
			&& $len > 0
			&& $len <= self::MAX_UPSTREAM_BYTES
			&& false === strpos( $body, self::SELF_FINGERPRINT )
		);

		$result = array(
			'has_external' => $looks_external,
			'body'         => $looks_external ? $body : '',
			'content_type' => $looks_external ? $content_type : '',
			'http_code'    => $code,
			'bytes'        => $len,
			'checked_at'   => time(),
		);
		set_transient( $key, $result, self::CACHE_SECONDS );

		return $looks_external;
	}

	/**
	 * Register a daily cron to keep the probe cache warm. Triggered from
	 * Xpay_Plugin::__construct(). Idempotent.
	 */
	public static function register_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_refresh_all' ) );
	}

	public static function unregister_cron() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback. Re-probes every registered emitter path. Cheap —
	 * 4 HTTP self-fetches with a 3s cap each, runs once per day.
	 */
	public static function cron_refresh_all() {
		$paths = self::known_paths();
		foreach ( $paths as $path ) {
			self::probe( $path );
		}
	}

	public static function known_paths() {
		return array(
			'/llms.txt',
			'/.well-known/ucp',
			'/.well-known/oauth-protected-resource',
			'/.well-known/agent-card.json',
		);
	}

	/**
	 * Convenience: prime the cache for all paths on activation so the
	 * first real request after install sees a non-null result.
	 */
	public static function prime_on_activation() {
		// Defer to a one-off cron a few seconds out so activation doesn't
		// block on the HTTP self-fetch (some hosts proxy WP through a
		// reverse proxy that's strict about same-request loops).
		wp_schedule_single_event( time() + 10, self::CRON_HOOK );
	}
}
