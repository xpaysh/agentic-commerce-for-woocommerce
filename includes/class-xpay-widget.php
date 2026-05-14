<?php
/**
 * Optional on-site widget: shortcode + Gutenberg block.
 *
 * Renders a small "Buy through ChatGPT / Claude / Perplexity" panel under
 * the PDP. NOT load-bearing for the audit; gated by an admin toggle and
 * default OFF in v1 to minimise theme-conflict support load.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Widget {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'xpay-buy', array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'product_id' => 0 ), $atts, 'xpay-buy' );
		if ( ! get_option( 'xpay_wc_widget_enabled', 0 ) ) {
			return '';
		}
		$product_id = (int) $atts['product_id'] ?: (int) get_the_ID();
		return $this->render( $product_id );
	}

	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			'xpay/buy-in-ai-chat',
			array(
				'render_callback' => array( $this, 'render_block' ),
				'attributes'      => array(
					'productId' => array( 'type' => 'number', 'default' => 0 ),
				),
			)
		);
	}

	public function render_block( $attrs ) {
		$id = isset( $attrs['productId'] ) ? (int) $attrs['productId'] : (int) get_the_ID();
		return $this->render( $id );
	}

	private function render( $product_id ) {
		if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return '';
		}
		$name = wp_strip_all_tags( $product->get_name() );
		$q    = rawurlencode( sprintf( 'Buy "%s" from %s', $name, get_bloginfo( 'name' ) ) );

		$buttons = array(
			'ChatGPT'    => 'https://chat.openai.com/?q=' . $q,
			'Claude'     => 'https://claude.ai/new?q=' . $q,
			'Perplexity' => 'https://www.perplexity.ai/search?q=' . $q,
			'Gemini'     => 'https://gemini.google.com/app?q=' . $q,
		);

		$out  = '<div class="xpay-buy-widget" style="margin:16px 0;padding:14px 16px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb;">';
		$out .= '<div style="font-size:13px;color:#374151;font-weight:600;margin-bottom:8px;">' . esc_html__( 'Buy through your AI assistant', 'xpay-woocommerce' ) . '</div>';
		foreach ( $buttons as $label => $href ) {
			$out .= sprintf(
				'<a href="%s" rel="nofollow noopener" style="display:inline-block;margin:4px 6px 0 0;padding:6px 12px;border-radius:6px;background:#fff;border:1px solid #d1d5db;font-size:12px;color:#111;text-decoration:none;">%s →</a>',
				esc_url( $href ),
				esc_html( $label )
			);
		}
		$out .= '</div>';
		return $out;
	}
}
