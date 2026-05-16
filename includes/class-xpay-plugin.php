<?php
/**
 * Plugin bootstrap. Loads every subsystem and wires activation/deactivation.
 */

defined( 'ABSPATH' ) || exit;

require_once XPAY_WC_PATH . 'includes/class-xpay-client.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-telemetry.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-consent.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-rest.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-robots.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-schema.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-cart.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-webhooks.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-settings.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-widget.php';

class Xpay_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! $this->woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		Xpay_REST::instance();
		Xpay_Robots::instance();
		Xpay_Schema::instance();
		Xpay_Cart::instance();
		Xpay_Webhooks::instance();
		Xpay_Settings::instance();
		Xpay_Widget::instance();
		if ( is_admin() ) {
			Xpay_Consent::instance();
		}

		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
		$this->maybe_handle_version_bump();
	}

	private function maybe_handle_version_bump() {
		$stored = get_option( 'xpay_wc_installed_version' );
		if ( $stored === XPAY_WC_VERSION ) {
			return;
		}
		// Version changed — re-flush rewrite rules so any added or removed
		// discovery routes take effect without requiring a deactivate/reactivate.
		update_option( 'xpay_wc_flush_rewrites', 1 );
		update_option( 'xpay_wc_installed_version', XPAY_WC_VERSION );
	}

	public function maybe_redirect_after_activation() {
		if ( ! get_transient( 'xpay_wc_post_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'xpay_wc_post_activation_redirect' );
		// Don't redirect on bulk-activate (multiple plugins activated at once).
		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( wp_safe_redirect( admin_url( 'options-general.php?page=agentic-commerce-for-woocommerce' ) ) ) {
			exit;
		}
	}

	public function woocommerce_active() {
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return in_array( 'woocommerce/woocommerce.php', $active, true );
	}

	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'Agentic Commerce for WooCommerce requires WooCommerce to be installed and active.', 'agentic-commerce-for-woocommerce' );
		echo '</p></div>';
	}

	public static function on_activate() {
		if ( ! get_option( 'xpay_wc_site_token' ) ) {
			update_option( 'xpay_wc_site_token', wp_generate_password( 32, false ) );
		}
		// Force a rewrite-rule flush after we register routes on next init.
		update_option( 'xpay_wc_flush_rewrites', 1 );

		$first_time = ! (bool) get_option( 'xpay_wc_first_activated_at' );
		if ( $first_time ) {
			update_option( 'xpay_wc_first_activated_at', time() );
		}

		// Redirect to Settings → xpay on the very next admin page load if the
		// merchant hasn't connected yet. Covers both fresh installs and upgrades
		// where the merchant never got around to connecting.
		if ( ! self::is_connected() ) {
			set_transient( 'xpay_wc_post_activation_redirect', 1, 60 );
		}

		if ( class_exists( 'Xpay_Telemetry' ) ) {
			Xpay_Telemetry::track(
				'plugin_activated',
				array(
					'first_time' => $first_time,
				)
			);
		}
	}

	public static function on_deactivate() {
		flush_rewrite_rules();
		if ( class_exists( 'Xpay_Telemetry' ) ) {
			Xpay_Telemetry::track(
				'plugin_deactivated',
				array(
					'was_connected' => self::is_connected(),
				)
			);
		}
	}

	public static function is_connected() {
		return (bool) get_option( 'xpay_wc_merchant_slug' );
	}

	public static function merchant_slug() {
		return (string) get_option( 'xpay_wc_merchant_slug', '' );
	}

	public static function api_key() {
		return (string) get_option( 'xpay_wc_api_key', '' );
	}
}
