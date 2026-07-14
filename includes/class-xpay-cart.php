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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_side_cart_bridge' ) );
	}

	/**
	 * Side-cart bridge: turn an `#open-cart` URL hash into the same-origin
	 * postMessage that side-cart plugins listen for, so an off-site link can open
	 * the merchant's drawer.
	 *
	 * WHY: stores running a side cart (Caddy and friends) routinely abandon the
	 * /cart/ page entirely, so a cart link pointing there is a dead end — and the
	 * drawer CANNOT be opened from off-site, because its markup is rendered
	 * server-side by WordPress and driven by the Interactivity API. Caddy does
	 * listen for a same-origin `postMessage("open_caddy_cart")`, so a link to
	 * `/shop/#open-cart` can hand off to it once the page is on their origin.
	 *
	 * Deliberately NOT gated on detecting a side-cart plugin:
	 *   - `is_plugin_active()` is a wp-admin function and is UNDEFINED on the front
	 *     end — calling it here would fatal every storefront page view.
	 *   - Sniffing `active_plugins` instead silently misses network-activated and
	 *     must-use plugins, and any renamed folder.
	 *   - And it buys nothing: a postMessage nobody listens for is a no-op, on
	 *     every site in the world. The bridge is inert by construction.
	 * So it ships to everyone, costs ~1.3 KB inline (no extra HTTP request), and
	 * does nothing at all unless the shopper actually arrives on `#open-cart`.
	 */
	public function enqueue_side_cart_bridge() {
		if ( is_admin() ) {
			return;
		}
		/**
		 * Opt out of the side-cart bridge entirely.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'xpay_wc_side_cart_bridge', true ) ) {
			return;
		}

		// An inline script needs a REGISTERED, ENQUEUED handle to hang off, or it
		// silently never prints. A `false` src registers a handle with no file.
		wp_register_script( 'xpay-side-cart', false, array(), XPAY_WC_VERSION, true );
		wp_enqueue_script( 'xpay-side-cart' );
		wp_add_inline_script( 'xpay-side-cart', $this->side_cart_bridge_js() );
	}

	/**
	 * The bridge itself.
	 *
	 * Two shots, no polling loop. Caddy registers its listener from an
	 * Interactivity API module (`type="module"`), and modules execute BEFORE
	 * DOMContentLoaded, so the first shot lands. `load` is a backstop for a slow
	 * module fetch.
	 *
	 * ⛔ The backstop must never fight the shopper. Watching for the drawer's open
	 * class alone is not enough: the class is a POSITIVE signal only, so "opened
	 * then the user closed it" is indistinguishable from "never opened" — and a
	 * naive retry would re-open the drawer on top of someone who just dismissed it.
	 * Hence `touched`: any pointer/key input means the page is theirs now, and we
	 * stop. The hash is stripped after the first shot so a refresh or a Back
	 * doesn't re-open the drawer either.
	 */
	private function side_cart_bridge_js() {
		return <<<'JS'
(function () {
  if (window.location.hash !== '#open-cart') { return; }
  var touched = false;
  var post = function () {
    try { window.postMessage('open_caddy_cart', window.location.origin); } catch (e) {}
  };
  var isOpen = function () {
    return !!(document.body && document.body.classList.contains('cc-window-open'));
  };
  var mark = function () { touched = true; };
  window.addEventListener('pointerdown', mark, { once: true, passive: true });
  window.addEventListener('keydown', mark, { once: true });

  var fire = function () {
    post();
    // Drop the hash so a refresh / Back doesn't re-open the drawer, and so
    // #open-cart doesn't leak into analytics page paths.
    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fire, { once: true });
  } else {
    fire();
  }

  // Backstop, once: only if the drawer never opened AND the shopper hasn't
  // touched anything. If they interacted, the page is theirs — leave it alone.
  window.addEventListener('load', function () {
    window.setTimeout(function () {
      if (!isOpen() && !touched) { post(); }
    }, 400);
  }, { once: true });
})();
JS;
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
			// Variation attributes (attribute_pa_size, attribute_color, …)
			// forwarded by the agent-commerce mint when the SKU/variation_id
			// resolves to a specific variation. WC's add_to_cart() needs this
			// map as the 4th arg to find the correct variation row; without it
			// variable products are silently dropped from the cart — a bug a live
			// merchant hit before this arg was passed.
			$variation_attrs = isset( $line['attributes'] ) && is_array( $line['attributes'] )
				? array_map( 'sanitize_text_field', $line['attributes'] )
				: array();

			// Try SKU → product (could be parent OR variation), then digit fallback.
			$product_id = wc_get_product_id_by_sku( $sku );
			if ( ! $product_id && ctype_digit( $sku ) ) {
				$product_id = (int) $sku;
			}

			// If the SKU resolved straight to a variation product, swap to the
			// parent + carry the variation_id forward (agents often pass the
			// variation SKU with no separate variation_id).
			if ( $product_id ) {
				$maybe = wc_get_product( $product_id );
				if ( $maybe && $maybe->is_type( 'variation' ) ) {
					if ( $variation === 0 ) {
						$variation = $product_id;
					}
					$product_id = $maybe->get_parent_id();
				}
			}

			// Last-resort parent resolution from the variation_id alone.
			if ( ! $product_id && $variation > 0 ) {
				$variation_product = wc_get_product( $variation );
				if ( $variation_product ) {
					$product_id = (int) $variation_product->get_parent_id();
				}
			}
			if ( ! $product_id ) {
				continue;
			}

			// Authoritative attribute resolution: pull them from the actual
			// variation product, ignoring the agent-supplied map if the live
			// WC source-of-truth disagrees. The agent payload is a fallback for
			// "any" attribute variations that don't appear in get_variation_attributes().
			if ( $variation > 0 ) {
				$variation_product = wc_get_product( $variation );
				if ( $variation_product && $variation_product->is_type( 'variation' ) ) {
					$local_attrs = $variation_product->get_variation_attributes();
					if ( ! empty( $local_attrs ) ) {
						$variation_attrs = $local_attrs;
					}
				}
			}

			// Stock guard — refuse OOS items at the cart layer too. The agent surface
			// already filters by `in_stock !== false` (storefront-track.ts), but a
			// stale agent cache or a manual deeplink can still arrive after the
			// merchant marks something out of stock — and an OOS line on the cart
			// page is a worse UX than silently dropping it. The "None available"
			// branch below still fires if every line is rejected.
			$stock_target = $variation > 0 ? wc_get_product( $variation ) : wc_get_product( $product_id );
			if ( $stock_target && ! $stock_target->is_in_stock() ) {
				continue;
			}

			$result = WC()->cart->add_to_cart( $product_id, $qty, $variation, $variation_attrs );
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
