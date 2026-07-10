<?php
/**
 * Plugin bootstrap. Loads every subsystem and wires activation/deactivation.
 */

defined( 'ABSPATH' ) || exit;

require_once XPAY_WC_PATH . 'includes/class-xpay-client.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-partner.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-telemetry.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-consent.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-emitter-probe.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-deflection.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-blog-proxy.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-agent-analytics.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-rest.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-admin-rest.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-robots.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-schema.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-cart.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-attribution.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-order-events.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-webhooks.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-settings.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-widget.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-storefront-widget.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-shop-assist-page.php';
require_once XPAY_WC_PATH . 'includes/class-xpay-content-pages.php';

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
		Xpay_Attribution::instance();
		Xpay_Order_Events::instance();
		Xpay_Webhooks::instance();
		Xpay_Settings::instance();
		Xpay_Emitter_Probe::register_cron();
		Xpay_Deflection::register();
		Xpay_Blog_Proxy::register();
		Xpay_Agent_Analytics::register();
		Xpay_Admin_REST::instance();
		Xpay_Widget::instance();
		Xpay_Storefront_Widget::instance();
		Xpay_Shop_Assist_Page::instance();
		Xpay_Content_Pages::instance();
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

		// Prime the emitter-probe cache shortly after activation so the
		// first /llms.txt or /.well-known/* request sees a populated
		// detection result. Skips silently if Xpay_Emitter_Probe isn't
		// loaded yet (defensive — the require_once chain in this file
		// loads it before this method runs, but staying paranoid).
		if ( class_exists( 'Xpay_Emitter_Probe' ) ) {
			Xpay_Emitter_Probe::prime_on_activation();
		}

		// Belt-and-suspenders: Xpay_Telemetry::track() already short-circuits
		// when telemetry is not opted in, but checking is_enabled() at the
		// call site makes the activation path trivially auditable as
		// network-silent on fresh installs without reading the Telemetry
		// class body.
		if ( class_exists( 'Xpay_Telemetry' ) && Xpay_Telemetry::is_enabled() ) {
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
		if ( class_exists( 'Xpay_Shop_Assist_Page' ) ) {
			Xpay_Shop_Assist_Page::clear_cron();
		}
		if ( class_exists( 'Xpay_Content_Pages' ) ) {
			Xpay_Content_Pages::clear_cron();
		}
		if ( class_exists( 'Xpay_Emitter_Probe' ) ) {
			Xpay_Emitter_Probe::unregister_cron();
		}
		if ( class_exists( 'Xpay_Deflection' ) ) {
			Xpay_Deflection::unregister_cron();
		}
		if ( class_exists( 'Xpay_Blog_Proxy' ) ) {
			Xpay_Blog_Proxy::unregister_cron();
		}
		if ( class_exists( 'Xpay_Agent_Analytics' ) ) {
			Xpay_Agent_Analytics::unregister_cron();
		}
		// Belt-and-suspenders is_enabled() guard — see on_activate() above.
		if ( class_exists( 'Xpay_Telemetry' ) && Xpay_Telemetry::is_enabled() ) {
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

	/**
	 * Should the AI Storefront Assistant render on this storefront?
	 *
	 * Two layers, both required (a merchant/a live merchant store 2026-06-25 incident: a
	 * backend pre-grant alone surfaced the chat bubble on a live store with no
	 * merchant notification — never again).
	 *
	 *   ENTITLEMENT  — is this merchant on a plan / pre-grant that includes the
	 *                  add-on? Cached backend lookup with a local-toggle fallback
	 *                  on API failure. TTL 6h.
	 *
	 *   CONSENT      — has the merchant explicitly opted in? Backend
	 *                  widgetConfig.widgetEnabled (set via xpay-app dashboard) OR
	 *                  the local wp-admin toggle xpay_wc_storefront_widget_enabled
	 *                  (manual opt-in counts as consent).
	 *
	 * The wp-config constant XPAY_WC_STOREFRONT_WIDGET still hard-overrides both
	 * layers for staging/dev.
	 */
	public static function is_storefront_widget_entitled() {
		if ( defined( 'XPAY_WC_STOREFRONT_WIDGET' ) ) {
			return (bool) XPAY_WC_STOREFRONT_WIDGET;
		}
		$slug = self::merchant_slug();
		if ( '' === $slug ) {
			return false;
		}

		if ( ! self::resolve_storefront_entitlement( $slug ) ) {
			return false;
		}

		// Local toggle = explicit consent (merchant flipped a switch in wp-admin).
		if ( (bool) get_option( 'xpay_wc_storefront_widget_enabled', 0 ) ) {
			return true;
		}
		// Backend dashboard consent — set via xpay-app's Storefront Assistant page.
		$cfg = class_exists( 'Xpay_Storefront_Widget' )
			? Xpay_Storefront_Widget::widget_config( $slug )
			: array();
		return ! empty( $cfg['widgetEnabled'] );
	}

	/**
	 * Read-only entitlement lookup with the existing 6h transient + local-toggle
	 * fallback. Split out so {@see is_storefront_widget_entitled()} can layer
	 * consent on top without duplicating the cache logic.
	 */
	private static function resolve_storefront_entitlement( $slug ) {
		$cached = get_transient( 'xpay_wc_storefront_entitlement' );
		if ( false !== $cached ) {
			return (bool) $cached;
		}
		$entitled = self::fetch_storefront_entitlement( $slug );
		set_transient( 'xpay_wc_storefront_entitlement', $entitled ? 1 : 0, 6 * HOUR_IN_SECONDS );
		return $entitled;
	}

	private static function fetch_storefront_entitlement( $slug ) {
		$url = trailingslashit( XPAY_WC_AGENT_COMMERCE_BASE ) . 'widget/entitlement?slug=' . rawurlencode( $slug );
		$res = wp_remote_get( $url, array( 'timeout' => 4 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			// Endpoint not reachable yet → fall back to the local admin toggle so
			// the widget stays controllable.
			return (bool) get_option( 'xpay_wc_storefront_widget_enabled', 0 );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return ! empty( $body['storefront_widget'] ) || ! empty( $body['entitled'] );
	}

	/**
	 * Drop the cached entitlement so a just-purchased add-on lights up without
	 * waiting for the 6h TTL. Call on settings save / add-on purchase webhook.
	 */
	public static function clear_storefront_entitlement_cache() {
		delete_transient( 'xpay_wc_storefront_entitlement' );
	}

	/**
	 * Is this merchant entitled to the "Content Engine" add-on? Same resolution
	 * order as the storefront widget:
	 *  1. XPAY_WC_CONTENT_ENGINE wp-config constant (staging/dev override).
	 *  2. Cached backend entitlement (the add-on purchase incl. free grants), 6h.
	 *  3. On backend failure, the local admin toggle xpay_wc_content_engine_enabled.
	 *
	 * NB: read the distinct `content_engine` flag from the entitlement endpoint —
	 * NOT the generic `entitled` — so a storefront-widget grant never silently
	 * publishes content pages.
	 */
	public static function is_content_engine_entitled() {
		if ( defined( 'XPAY_WC_CONTENT_ENGINE' ) ) {
			return (bool) XPAY_WC_CONTENT_ENGINE;
		}
		$slug = self::merchant_slug();
		if ( '' === $slug ) {
			return false;
		}

		$cached = get_transient( 'xpay_wc_content_engine_entitlement' );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$entitled = self::fetch_content_engine_entitlement( $slug );
		set_transient( 'xpay_wc_content_engine_entitlement', $entitled ? 1 : 0, 6 * HOUR_IN_SECONDS );
		return $entitled;
	}

	private static function fetch_content_engine_entitlement( $slug ) {
		$url = trailingslashit( XPAY_WC_AGENT_COMMERCE_BASE ) . 'widget/entitlement?slug=' . rawurlencode( $slug );
		$res = wp_remote_get( $url, array( 'timeout' => 4 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return (bool) get_option( 'xpay_wc_content_engine_enabled', 0 );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return ! empty( $body['content_engine'] );
	}

	public static function clear_content_engine_entitlement_cache() {
		delete_transient( 'xpay_wc_content_engine_entitlement' );
	}
}
