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

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_footer', array( $this, 'inject' ), 50 );
		add_action( 'admin_notices', array( $this, 'caching_exclusion_notice' ) );
		// Drop the cached entitlement immediately when the local toggle changes
		// so the widget appears/disappears without waiting for the 6h TTL.
		add_action( 'update_option_xpay_wc_storefront_widget_enabled', array( 'Xpay_Plugin', 'clear_storefront_entitlement_cache' ) );
	}

	/**
	 * Echo the loader <script> on the storefront when entitled + connected.
	 */
	public function inject() {
		if ( is_admin() ) {
			return;
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
		$cfg      = $this->widget_config( $slug );
		$mode     = ! empty( $cfg['defaultMode'] ) ? $cfg['defaultMode'] : apply_filters( 'xpay_wc_storefront_widget_mode', 'bubble' );
		$name     = ! empty( $cfg['displayName'] ) ? $cfg['displayName'] : wp_strip_all_tags( get_bloginfo( 'name' ) );
		$position = ( isset( $cfg['position'] ) && 'left' === $cfg['position'] ) ? 'left' : 'right';
		$offset_x = isset( $cfg['offsetX'] ) ? max( 0, (int) $cfg['offsetX'] ) : 0;
		$offset_y = isset( $cfg['offsetY'] ) ? max( 0, (int) $cfg['offsetY'] ) : 0;
		$accent_a = ! empty( $cfg['accentFrom'] ) ? $cfg['accentFrom'] : '';
		$accent_b = ! empty( $cfg['accentTo'] ) ? $cfg['accentTo'] : '';

		printf(
			'<script async src="%s" data-slug="%s" data-track="merchant" data-mode="%s" data-display-name="%s" data-position="%s" data-offset-x="%d" data-offset-y="%d"%s%s></script>' . "\n",
			esc_url( self::LOADER_URL ),
			esc_attr( $slug ),
			esc_attr( $mode ),
			esc_attr( $name ),
			esc_attr( $position ),
			$offset_x,
			$offset_y,
			$accent_a ? ' data-accent-from="' . esc_attr( $accent_a ) . '"' : '',
			$accent_b ? ' data-accent-to="' . esc_attr( $accent_b ) . '"' : ''
		);
	}

	/**
	 * Fetch the merchant's saved widget appearance config (public endpoint),
	 * cached 1h. Returns [] on any failure — the loader/iframe fall back to
	 * defaults, so a missing config never blocks the widget.
	 */
	private function widget_config( $slug ) {
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
