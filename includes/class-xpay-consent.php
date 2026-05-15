<?php
/**
 * First-activation consent prompt for anonymous lifecycle telemetry.
 *
 * Default state: OFF. Telemetry only fires after the merchant clicks "Enable".
 * Required for WordPress.org guideline 7 (informed consent for external server
 * contact).
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Consent {

	private static $instance = null;
	const ACTION             = 'xpay_wc_consent';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_choice' ) );
	}

	public function maybe_render_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( Xpay_Telemetry::has_decided() ) {
			return;
		}
		if ( defined( 'XPAY_WC_TELEMETRY' ) && false === XPAY_WC_TELEMETRY ) {
			return;
		}

		$enable_url  = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&choice=yes' ), self::ACTION );
		$decline_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&choice=no' ), self::ACTION );

		echo '<div class="notice notice-info" style="border-left-color:#0ea5e9;">';
		echo '<p><strong>' . esc_html__( 'Agentic Commerce for WooCommerce — help us improve onboarding', 'agentic-commerce-for-woocommerce' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'May we send anonymous lifecycle events (plugin activated, store connected, audit re-run, sync errors) to help us catch broken onboarding flows? No PII or customer data is ever sent. You can change this any time under Settings → xpay.', 'agentic-commerce-for-woocommerce' ) . ' ';
		echo '<a href="https://install.xpay.sh/woocommerce/privacy.html" target="_blank" rel="noopener noreferrer">' . esc_html__( 'What gets sent', 'agentic-commerce-for-woocommerce' ) . '</a>.</p>';
		echo '<p>';
		echo '<a class="button button-primary" href="' . esc_url( $enable_url ) . '">' . esc_html__( 'Enable anonymous telemetry', 'agentic-commerce-for-woocommerce' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $decline_url ) . '">' . esc_html__( 'No thanks', 'agentic-commerce-for-woocommerce' ) . '</a>';
		echo '</p>';
		echo '</div>';
	}

	public function handle_choice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( self::ACTION );
		$choice = isset( $_GET['choice'] ) && 'yes' === $_GET['choice'] ? 'yes' : 'no';
		Xpay_Telemetry::set_opt_in( $choice );
		$ref = wp_get_referer();
		wp_safe_redirect( $ref ? $ref : admin_url( 'plugins.php' ) );
		exit;
	}
}
