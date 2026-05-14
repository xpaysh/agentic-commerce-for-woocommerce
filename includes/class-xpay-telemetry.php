<?php
/**
 * Lifecycle telemetry. Fires non-blocking, fire-and-forget POSTs to the xpay
 * backend so we can see install / connect / finalize / error events in the
 * funnel.
 *
 * Hard guarantees:
 *   - Never blocks the request (wp_remote_post with blocking=false, timeout=1).
 *   - Never throws. All failure paths swallow silently.
 *   - Never sends PII beyond what the merchant already shared during onboarding
 *     (site_url + admin email + plugin version + WC/WP/PHP versions).
 *   - Honours an opt-out: `define( 'XPAY_WC_TELEMETRY', false )` in wp-config.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Telemetry {

	const ENDPOINT_PATH = '/v1/events';

	public static function track( $event, $props = array() ) {
		try {
			if ( defined( 'XPAY_WC_TELEMETRY' ) && false === XPAY_WC_TELEMETRY ) {
				return;
			}
			if ( ! is_string( $event ) || '' === $event ) {
				return;
			}

			$payload = array(
				'event'           => $event,
				'site_url'        => home_url( '/' ),
				'merchant_slug'   => Xpay_Plugin::merchant_slug(),
				'plugin_version'  => XPAY_WC_VERSION,
				'wp_version'      => get_bloginfo( 'version' ),
				'wc_version'      => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'php_version'     => PHP_VERSION,
				'locale'          => get_locale(),
				'ts'              => time(),
				'props'           => is_array( $props ) ? $props : array(),
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
						'User-Agent'   => 'xpay-woocommerce/' . XPAY_WC_VERSION . '; ' . home_url( '/' ),
						'X-Xpay-Site'  => home_url( '/' ),
					),
					'body'      => wp_json_encode( $payload ),
				)
			);
		} catch ( \Throwable $e ) {
			// Telemetry must never break the host site. Swallow.
		}
	}
}
