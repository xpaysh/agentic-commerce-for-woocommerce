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

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
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
		if ( wp_safe_redirect( admin_url( 'options-general.php?page=xpay-for-woocommerce' ) ) ) {
			exit;
		}
	}

	public function woocommerce_active() {
		return in_array(
			'woocommerce/woocommerce.php',
			apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) ),
			true
		) || class_exists( 'WooCommerce' );
	}

	public function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'xpay for WooCommerce requires WooCommerce to be installed and active.', 'xpay-for-woocommerce' );
		echo '</p></div>';
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'xpay-for-woocommerce', false, dirname( plugin_basename( XPAY_WC_FILE ) ) . '/languages' );
	}

	public static function on_activate() {
		if ( ! get_option( 'xpay_wc_site_token' ) ) {
			update_option( 'xpay_wc_site_token', wp_generate_password( 32, false ) );
		}
		// Force a rewrite-rule flush after we register routes on next init.
		update_option( 'xpay_wc_flush_rewrites', 1 );

		// Mark so we redirect to Settings → xpay on the next admin page load.
		if ( ! get_option( 'xpay_wc_first_activated_at' ) ) {
			update_option( 'xpay_wc_first_activated_at', time() );
			set_transient( 'xpay_wc_post_activation_redirect', 1, 60 );
		}

		if ( class_exists( 'Xpay_Telemetry' ) ) {
			Xpay_Telemetry::track(
				'plugin_activated',
				array(
					'first_time' => ! (bool) get_option( 'xpay_wc_first_activated_at' ),
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
