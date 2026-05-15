<?php
/**
 * Cart deeplink handler.
 *
 * The xpay agent-commerce API signs a JWT containing { items, exp, merchant }
 * and returns it as a deeplink: https://merchant.com/?xpay_cart=<jwt>
 *
 * This class:
 *   1. Detects the query var on early-init.
 *   2. Verifies the JWT against the merchant's shared api_key (HS256).
 *   3. Empties the cart, adds each line, sets an attribution flag, and 302s to
 *      wc_get_checkout_url(). Payment runs on the merchant's existing gateway.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Cart {

	private static $instance = null;
	const TOKEN_PARAM        = 'xpay_cart';
	const SESSION_KEY        = '_xpay_attribution';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// wp_loaded fires after WC has bootstrapped session/cart objects.
		add_action( 'wp_loaded', array( $this, 'maybe_handle' ), 20 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'tag_order' ), 10, 1 );
	}

	public function maybe_handle() {
		// Cart deeplink is authenticated by the signed JWT in the URL, not a WP nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET[ self::TOKEN_PARAM ] ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		if ( ! Xpay_Plugin::is_connected() ) {
			wp_die(
				esc_html__( 'This store is not connected to xpay. Ask the site owner to complete xpay setup.', 'agentic-commerce-for-woocommerce' ),
				esc_html__( 'xpay — store not connected', 'agentic-commerce-for-woocommerce' ),
				array( 'response' => 503 )
			);
		}

		$jwt     = sanitize_text_field( wp_unslash( $_GET[ self::TOKEN_PARAM ] ) );
		$payload = Xpay_Client::verify_jwt( $jwt );
		if ( ! $payload || empty( $payload['items'] ) || ! is_array( $payload['items'] ) ) {
			wp_die(
				esc_html__( 'This shopping link has expired or is invalid. Ask the agent to generate a new one.', 'agentic-commerce-for-woocommerce' ),
				esc_html__( 'xpay — invalid cart link', 'agentic-commerce-for-woocommerce' ),
				array( 'response' => 400 )
			);
		}

		WC()->cart->empty_cart();

		$added = 0;
		foreach ( $payload['items'] as $line ) {
			$sku       = isset( $line['sku'] ) ? sanitize_text_field( $line['sku'] ) : '';
			$qty       = isset( $line['qty'] ) ? max( 1, (int) $line['qty'] ) : 1;
			$variation = isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0;

			$product_id = wc_get_product_id_by_sku( $sku );
			if ( ! $product_id && ctype_digit( $sku ) ) {
				$product_id = (int) $sku;
			}
			if ( ! $product_id ) {
				continue;
			}

			$result = WC()->cart->add_to_cart( $product_id, $qty, $variation );
			if ( $result ) {
				++$added;
			}
		}

		if ( ! $added ) {
			wp_die(
				esc_html__( 'None of the items in this shopping link are available right now.', 'agentic-commerce-for-woocommerce' ),
				esc_html__( 'xpay — items unavailable', 'agentic-commerce-for-woocommerce' ),
				array( 'response' => 410 )
			);
		}

		WC()->session->set(
			self::SESSION_KEY,
			array(
				'cart_id'    => isset( $payload['cart_id'] ) ? sanitize_text_field( $payload['cart_id'] ) : '',
				'agent'      => isset( $payload['agent'] ) ? sanitize_text_field( $payload['agent'] ) : '',
				'surface'    => isset( $payload['surface'] ) ? sanitize_text_field( $payload['surface'] ) : '',
				'created_at' => time(),
			)
		);

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public function tag_order( $order ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$attribution = WC()->session->get( self::SESSION_KEY );
		if ( empty( $attribution ) ) {
			return;
		}
		$order->update_meta_data( '_xpay_agent_attribution', $attribution );
		$order->update_meta_data( '_xpay_source', 'agent_cart_deeplink' );
		WC()->session->set( self::SESSION_KEY, null );
	}
}
