<?php
/**
 * AI Storefront Assistant — embeddable chat widget loader injection.
 *
 * Injects the xpay storefront widget v2 loader (widget.xpay.sh/v1/storefront.js)
 * into the storefront <footer>, gated on the merchant being entitled to the
 * "AI Storefront Assistant" add-on. The loader drops a glass AI shopping
 * assistant (bubble by default) that talks to this store's commerce MCP and
 * renders product cards + checkout deeplinks.
 *
 * Hard rules honoured (April production gotchas):
 *  - Injected on wp_footer with <script async> (never wp_enqueue ceremony).
 *  - The loader itself is addEventListener-only + credentials:'omit'.
 *  - Merchants MUST exclude widget.xpay.sh from caching-plugin JS optimization
 *    (WP Rocket / LiteSpeed / W3TC / Autoptimize) — surfaced as an admin notice.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Storefront_Widget {

	const LOADER_URL = 'https://widget.xpay.sh/v1/storefront.js';
	const HANDLE     = 'xpay-storefront-loader';

	private static $instance = null;

	/** Data-* attributes to stamp onto the loader tag (set in enqueue()). */
	private $loader_attrs = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( $this, 'filter_loader_tag' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'caching_exclusion_notice' ) );
		// Drop the cached entitlement immediately when the local toggle changes
		// so the widget appears/disappears without waiting for the 6h TTL.
		add_action( 'update_option_xpay_wc_storefront_widget_enabled', array( 'Xpay_Plugin', 'clear_storefront_entitlement_cache' ) );
	}

	/**
	 * Enqueue the loader (footer) when entitled + connected, and stash the
	 * data-* attributes for filter_loader_tag() to stamp on. Enqueuing (vs a raw
	 * wp_footer echo) is the WordPress.org-compliant way to add an external
	 * script.
	 */
	public function enqueue() {
		if ( is_admin() ) {
			return;
		}
		// Never load the floating bubble on our own full-page shopper — the chat
		// IS that page, so a FAB would be a duplicate assistant.
		if ( is_page() ) {
			$sa_page = (int) get_option( 'xpay_wc_shop_assist_page_id', 0 );
			if ( $sa_page && get_queried_object_id() === $sa_page ) {
				return;
			}
		}
		if ( ! Xpay_Plugin::is_connected() ) {
			return;
		}
		if ( ! Xpay_Plugin::is_storefront_widget_entitled() ) {
			return;
		}

		$slug = Xpay_Plugin::merchant_slug();
		if ( '' === $slug ) {
			return;
		}

		// Saved appearance config (accent + FAB position/offset live in the
		// loader, so they must be stamped here; the iframe fetches the rest).
		$cfg      = self::widget_config( $slug );
		$mode     = ! empty( $cfg['defaultMode'] ) ? $cfg['defaultMode'] : apply_filters( 'xpay_wc_storefront_widget_mode', 'bubble' );
		$name     = ! empty( $cfg['displayName'] ) ? $cfg['displayName'] : wp_strip_all_tags( get_bloginfo( 'name' ) );
		$position = ( isset( $cfg['position'] ) && 'left' === $cfg['position'] ) ? 'left' : 'right';
		$offset_x = isset( $cfg['offsetX'] ) ? max( 0, (int) $cfg['offsetX'] ) : 0;
		$offset_y = isset( $cfg['offsetY'] ) ? max( 0, (int) $cfg['offsetY'] ) : 0;

		$attrs = array(
			'data-slug'         => $slug,
			'data-track'        => 'merchant',
			'data-mode'         => $mode,
			'data-display-name' => $name,
			'data-position'     => $position,
			'data-offset-x'     => (string) $offset_x,
			'data-offset-y'     => (string) $offset_y,
		);
		if ( ! empty( $cfg['accentFrom'] ) ) {
			$attrs['data-accent-from'] = $cfg['accentFrom'];
		}
		if ( ! empty( $cfg['accentTo'] ) ) {
			$attrs['data-accent-to'] = $cfg['accentTo'];
		}
		$this->loader_attrs = $attrs;

		// null version → no ?ver= cache-buster on the external loader; footer load.
		wp_enqueue_script( self::HANDLE, self::LOADER_URL, array(), null, true );
	}

	/**
	 * Add `async` + the data-* attributes to our loader's <script> tag. Other
	 * handles pass through untouched.
	 */
	public function filter_loader_tag( $tag, $handle ) {
		if ( self::HANDLE !== $handle || ! is_array( $this->loader_attrs ) ) {
			return $tag;
		}
		$extra = ' async';
		foreach ( $this->loader_attrs as $key => $val ) {
			$extra .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $val ) );
		}
		// Literal splice (not preg_replace — attr values may contain $ or \).
		$needle = '<script ';
		if ( 0 === strpos( $tag, $needle ) ) {
			return '<script' . $extra . ' ' . substr( $tag, strlen( $needle ) );
		}
		return $tag;
	}

	/**
	 * Fetch the merchant's saved widget appearance config (public endpoint),
	 * cached 1h. Returns [] on any failure — the loader/iframe fall back to
	 * defaults, so a missing config never blocks the widget.
	 */
	public static function widget_config( $slug ) {
		$cached = get_transient( 'xpay_wc_widget_config' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$base = defined( 'XPAY_WC_MERCHANT_API' ) ? XPAY_WC_MERCHANT_API : 'https://nehl6uu58j.execute-api.us-east-1.amazonaws.com';
		$url  = trailingslashit( $base ) . 'merchant/widget-config/public/' . rawurlencode( $slug );
		$res  = wp_remote_get( $url, array( 'timeout' => 4 ) );
		$cfg  = array();
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$decoded = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( is_array( $decoded ) ) {
				$cfg = $decoded;
			}
		}
		set_transient( 'xpay_wc_widget_config', $cfg, HOUR_IN_SECONDS );
		return $cfg;
	}

	/**
	 * Admin notice on our settings screen: exclude the loader from caching
	 * plugins. Stale/minified external loaders are a known April failure mode.
	 */
	public function caching_exclusion_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'agentic-commerce-for-woocommerce' ) ) {
			return;
		}
		if ( ! Xpay_Plugin::is_storefront_widget_entitled() ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>';
		esc_html_e( 'AI Storefront Assistant is active.', 'agentic-commerce-for-woocommerce' );
		echo '</strong> ';
		echo wp_kses(
			__( 'If you use a caching/optimization plugin (WP Rocket, LiteSpeed, W3 Total Cache, Autoptimize), <strong>exclude <code>widget.xpay.sh/v1/storefront.js</code></strong> from JavaScript minify/concatenate and delay/defer so the chat loads reliably. In WP Rocket: <em>File Optimization → Excluded JavaScript Files</em> and <em>Delay JavaScript Execution → Excluded</em>.', 'agentic-commerce-for-woocommerce' ),
			array(
				'strong' => array(),
				'code'   => array(),
				'em'     => array(),
			)
		);
		echo '</p></div>';
	}
}
