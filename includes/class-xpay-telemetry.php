<?php
/**
 * Lifecycle telemetry. Opt-in only.
 *
 * Hard guarantees:
 *   - Never blocks the request (wp_remote_post with blocking=false, timeout=1).
 *   - Never throws. All failure paths swallow silently.
 *   - Never fires until the merchant clicks "Enable" on the first-activation
 *     admin notice. (WordPress.org Guideline 7 — informed consent.)
 *   - Never sends PII beyond what the merchant already shared during onboarding
 *     (site_url + plugin version + WP / WC / PHP versions).
 *   - Sysadmins can hard-disable with `define( 'XPAY_WC_TELEMETRY', false )` in
 *     wp-config.php (this overrides any opt-in choice).
 *
 * What we send and when is documented under "External services" in readme.txt.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Telemetry {

	const ENDPOINT_PATH = '/v1/events';
	const OPTION_OPT_IN = 'xpay_wc_telemetry_opt_in'; // 'yes' | 'no' | '' (undecided)

	public static function is_enabled() {
		if ( defined( 'XPAY_WC_TELEMETRY' ) && false === XPAY_WC_TELEMETRY ) {
			return false;
		}
		return 'yes' === get_option( self::OPTION_OPT_IN, '' );
	}

	public static function set_opt_in( $value ) {
		$value = ( 'yes' === $value ) ? 'yes' : 'no';
		update_option( self::OPTION_OPT_IN, $value );
		update_option( 'xpay_wc_telemetry_decided_at', time() );
	}

	public static function has_decided() {
		$v = get_option( self::OPTION_OPT_IN, '' );
		return 'yes' === $v || 'no' === $v;
	}

	public static function track( $event, $props = array() ) {
		try {
			if ( ! self::is_enabled() ) {
				return;
			}
			if ( ! is_string( $event ) || '' === $event ) {
				return;
			}

			$props = is_array( $props ) ? $props : array();

			// First-party dogfood marker. Set `define( 'XPAY_WC_DOGFOOD', true )`
			// in wp-config.php on our own provisioned stores so the lifecycle
			// digest can segment them from real merchants WITHOUT excluding a
			// whole hosting provider (e.g. instawp.site) — which would silently
			// bury real customers who happen to host there.
			if ( defined( 'XPAY_WC_DOGFOOD' ) && true === XPAY_WC_DOGFOOD ) {
				$props['dogfood'] = true;
			}

			$payload = array(
				'event'          => $event,
				'site_url'       => home_url( '/' ),
				'merchant_slug'  => Xpay_Plugin::merchant_slug(),
				'plugin_version' => XPAY_WC_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'wc_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'php_version'    => PHP_VERSION,
				'locale'         => get_locale(),
				'ts'             => time(),
				'props'          => $props,
			);

			$url = trailingslashit( XPAY_WC_API_BASE ) . ltrim( self::ENDPOINT_PATH, '/' );

			wp_remote_post(
				$url,
				array(
					'method'    => 'POST',
					'timeout'   => 1,
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
			unset( $e ); // Telemetry must never break the host site. Swallow.
		}
	}
}
