<?php
/**
 * Agent-attributed order events.
 *
 * Listens on order-status transitions (payment_complete + status_completed)
 * and POSTs a NON-PII summary to the xpay backend so the merchant dashboard
 * can show "Agent-attributed orders this week" + revenue split by AI source.
 *
 * Hard rules (Sri / `docs/jun/27/plan-agent-order-attribution.md`):
 *   - No PII ever leaves the store: order_id is WC's internal id; we send
 *     amount, currency, discount, line skus, placed_at, status, and the
 *     attribution source — NOT email, address, phone, IP, or customer_id.
 *   - Uses the existing `Xpay_Client::post()` + plugin api_key; needs no
 *     additional WooCommerce REST scope, no merchant re-authorization.
 *   - Idempotent: backend rejects duplicates via PutItem condition; plugin
 *     also stamps `_xpay_order_event_sent_at` meta so status flips don't
 *     re-fire.
 *
 * Source resolution (priority order):
 *   1. `_xpay_source` order meta — cart deeplink (Layer 0, already shipped).
 *   2. `_xpay_ref` cookie / WC-session value written by Xpay_Attribution
 *      (Layer 1: deterministic stamp; Layer 2: inbound classifier).
 *   3. unattributed.
 *
 * Opt-out: the `xpay_wc_order_events_enabled` option (default true). One
 * toggle if anything goes wrong in production — see the plan's rollback
 * section.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Order_Events {

	const SENT_META_KEY  = '_xpay_order_event_sent_at';
	const SOURCE_META_KEY = '_xpay_source';
	const ATTRIBUTION_META_KEY = '_xpay_agent_attribution';
	const INBOUND_REF_META_KEY = '_xpay_ref_inbound';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Three hooks point at the same handler — the dedupe meta makes
		// double-fires safe:
		//   - woocommerce_payment_complete       : gateway-captured orders
		//                                          (Stripe / WooPayments / PayPal etc.)
		//   - woocommerce_order_status_completed : auto-completed orders +
		//                                          status transitions
		//   - woocommerce_order_status_changed   : safety net for the cases
		//                                          where _completed doesn't
		//                                          fire (e.g. wp-admin "Add
		//                                          order" with initial-state =
		//                                          Completed; some HPOS code
		//                                          paths). Filters on the new
		//                                          status inside on_status_changed().
		add_action( 'woocommerce_payment_complete', array( $this, 'on_event' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_event' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
	}

	/**
	 * Safety-net status-change hook. Only forwards to on_event() for the two
	 * "money collected" terminal states; otherwise it would fire on cancelled
	 * / refunded / failed transitions too and pollute the dashboard.
	 */
	public function on_status_changed( $order_id, $from_status, $to_status ) {
		if ( 'completed' !== $to_status && 'processing' !== $to_status ) {
			return;
		}
		$this->on_event( $order_id );
	}

	public function on_event( $order_id ) {
		// Disabled by option — the v0.4.3 rollback lever.
		if ( ! (bool) get_option( 'xpay_wc_order_events_enabled', 1 ) ) {
			return;
		}

		// Don't fire until the store is actually connected — no api_key, no
		// outbound. (Onboarding is a hard prerequisite.)
		if ( ! class_exists( 'Xpay_Plugin' ) || ! Xpay_Plugin::is_connected() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Idempotency at the plugin layer — even before the backend gets the
		// condition-check rejection, don't waste the network call.
		if ( $order->get_meta( self::SENT_META_KEY, true ) ) {
			return;
		}

		$payload = $this->build_payload( $order );
		if ( ! $payload ) {
			return;
		}

		$slug = Xpay_Plugin::merchant_slug();
		if ( '' === $slug ) {
			return;
		}

		$response = Xpay_Client::post(
			'/v1/merchants/' . rawurlencode( $slug ) . '/orders',
			$payload,
			8
		);

		// Xpay_Client::post returns array on 2xx, WP_Error on transport
		// failure, or the decoded body on 4xx/5xx. Treat anything non-WP_Error
		// as "backend received it"; the backend's idempotent rejection is OK.
		if ( is_wp_error( $response ) ) {
			// Don't stamp the meta on transport failure — let the next status
			// flip (or a manual retry) re-fire. Future enhancement: schedule a
			// WP-Cron retry instead of relying on status flips.
			return;
		}

		$order->update_meta_data( self::SENT_META_KEY, time() );
		$order->save();
	}

	/**
	 * Build the non-PII payload sent to xpay backend.
	 *
	 * Conforms exactly to the contract enforced server-side at
	 * `backend/wc-plugin-setup/src/order-ingest.ts`: any unknown top-level
	 * keys are stripped there before persistence, so any field added here
	 * needs the matching addition in ALLOWED_TOP_KEYS server-side.
	 */
	private function build_payload( $order ) {
		$total    = (string) $order->get_total();
		$discount = (string) $order->get_total_discount();
		$currency = $order->get_currency();
		$items    = $order->get_items();
		$skus     = array();

		foreach ( $items as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$sku = $product->get_sku();
			if ( '' === $sku ) {
				// Use product id as fallback (matches AcpProduct's SKU resolution).
				$sku = (string) $product->get_id();
			}
			$skus[] = $sku;
		}

		$source_resolution = $this->resolve_source( $order );

		$payload = array(
			'order_id'        => (string) $order->get_id(),
			'placed_at'       => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : gmdate( 'c' ),
			'status'          => $order->get_status(),
			'amount_total'    => $total,
			'amount_discount' => $discount,
			'currency'        => $currency,
			'line_count'      => count( $skus ),
			'skus'            => array_values( array_unique( $skus ) ),
			'source'          => $source_resolution['source'],
		);

		if ( ! empty( $source_resolution['source_detail'] ) ) {
			$payload['source_detail'] = $source_resolution['source_detail'];
		}
		if ( ! empty( $source_resolution['ref'] ) ) {
			$payload['ref'] = $source_resolution['ref'];
		}

		return $payload;
	}

	/**
	 * Resolve the order's attribution source. Returns:
	 *   [ 'source' => 'agent_cart_deeplink' | 'ref_utm' | 'ref_classified' | 'unattributed',
	 *     'source_detail' => array,  // when source = agent_cart_deeplink
	 *     'ref' => array,            // when source = ref_*
	 *   ]
	 */
	private function resolve_source( $order ) {
		// Layer 0 — the cart deeplink (`?xpay_cart=`) path already writes
		// `_xpay_source` + `_xpay_agent_attribution` in Xpay_Cart::tag_order().
		$source_meta = $order->get_meta( self::SOURCE_META_KEY, true );
		if ( 'agent_cart_deeplink' === $source_meta ) {
			$attribution = $order->get_meta( self::ATTRIBUTION_META_KEY, true );
			$detail      = array();
			if ( is_array( $attribution ) ) {
				foreach ( array( 'agent', 'surface', 'cart_id' ) as $k ) {
					if ( isset( $attribution[ $k ] ) && '' !== $attribution[ $k ] ) {
						$detail[ $k ] = (string) $attribution[ $k ];
					}
				}
			}
			return array(
				'source'        => 'agent_cart_deeplink',
				'source_detail' => $detail,
			);
		}

		// Layer 1+2 — Xpay_Attribution writes _xpay_ref_inbound on
		// woocommerce_checkout_create_order from the cookie/session.
		$inbound = $order->get_meta( self::INBOUND_REF_META_KEY, true );
		if ( is_array( $inbound ) && ! empty( $inbound['source'] ) ) {
			$method = isset( $inbound['method'] ) ? (string) $inbound['method'] : '';
			$ref    = array(
				'source'     => (string) $inbound['source'],
				'confidence' => isset( $inbound['confidence'] ) ? (string) $inbound['confidence'] : 'medium',
				'method'     => $method,
			);
			$source = ( 'utm' === $method ) ? 'ref_utm' : 'ref_classified';
			return array(
				'source' => $source,
				'ref'    => $ref,
			);
		}

		return array( 'source' => 'unattributed' );
	}
}
