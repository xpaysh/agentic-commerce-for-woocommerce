<?php
/**
 * Tells the xpay backend when products / prices / stock change, so the hosted
 * catalog feed at agent-feed.xpay.sh/catalog/{slug}.json stays current.
 *
 * We debounce by scheduling a single resync via wp-cron 30s after the last
 * change — avoids hammering the backend during bulk edits / CSV imports.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Webhooks {

	private static $instance = null;
	const RESYNC_HOOK        = 'xpay_wc_resync_catalog';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_update_product', array( $this, 'schedule_resync' ), 10 );
		add_action( 'woocommerce_new_product', array( $this, 'schedule_resync' ), 10 );
		add_action( 'woocommerce_delete_product', array( $this, 'schedule_resync' ), 10 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'schedule_resync' ), 10 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'schedule_resync' ), 10 );

		add_action( self::RESYNC_HOOK, array( $this, 'do_resync' ) );
	}

	public function schedule_resync() {
		if ( ! Xpay_Plugin::is_connected() ) {
			return;
		}
		if ( wp_next_scheduled( self::RESYNC_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + 30, self::RESYNC_HOOK );
	}

	public function do_resync() {
		$result = Xpay_Client::post(
			'/v1/merchants/' . Xpay_Plugin::merchant_slug() . '/resync',
			array(
				'reason'    => 'product_change',
				'origin'    => home_url( '/' ),
				'timestamp' => time(),
			)
		);
		if ( is_wp_error( $result ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[xpay] resync failed: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			Xpay_Telemetry::track(
				'resync_error',
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				)
			);
			return;
		}
		update_option( 'xpay_wc_last_sync_at', time() );
		Xpay_Telemetry::track(
			'resync_success',
			array(
				'product_count' => is_array( $result ) && isset( $result['count'] ) ? (int) $result['count'] : null,
			)
		);
	}
}
