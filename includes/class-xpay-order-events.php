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

	const SCHEDULED_HOOK = 'xpay_wc_dispatch_order_event';
	const RECONCILE_HOOK = 'xpay_wc_order_events_reconcile';

	/**
	 * A `queued:<ts>` sentinel older than this is treated as STALE and
	 * overwritten rather than short-circuiting. Without this, an order whose
	 * cron job never ran is bricked forever: enqueue_event() sees a truthy
	 * SENT_META_KEY and returns early on every subsequent status touch.
	 */
	const SENTINEL_STALE_SECONDS = 900; // 15 minutes

	/** Order ids to dispatch inline on shutdown, if cron hasn't beaten us to it. */
	private $shutdown_queue = array();

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
		add_action( 'woocommerce_payment_complete', array( $this, 'enqueue_event' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'enqueue_event' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );

		// Async dispatcher — runs ~immediately via WP-Cron loopback so the
		// 4-8s outbound POST never blocks the checkout thank-you page.
		add_action( self::SCHEDULED_HOOK, array( $this, 'on_event' ), 10, 1 );

		// Daily retry sweep for orders whose cron job never woke (see Fix 4).
		add_action( self::RECONCILE_HOOK, array( $this, 'cron_reconcile' ), 10, 0 );
	}

	/**
	 * Schedule the daily reconciliation sweep. Called from Xpay_Plugin. Idempotent.
	 */
	public static function register_cron() {
		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'daily', self::RECONCILE_HOOK );
		}
	}

	public static function unregister_cron() {
		$ts = wp_next_scheduled( self::RECONCILE_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::RECONCILE_HOOK );
		}
	}

	/**
	 * Daily reconciliation sweep — the safety net under Fix 4.
	 *
	 * Finds recent PAID orders that were never delivered (no send stamp, or a
	 * stale `queued:*` sentinel) and dispatches them serially. This is also what
	 * retroactively catches the Fix 1 / Fix 2 misses: after upgrade, the last
	 * week's orders re-resolve through the new layers on the next sweep.
	 *
	 * ⛔ WP.org compliance (no autonomous calls): this runs ONLY for CONNECTED
	 * stores. Connecting is the merchant's consent, and this sends exactly the
	 * order events they already enabled — just reliably, instead of only when a
	 * page load happened to wake WP-Cron. No new data category, no new endpoint.
	 * Disconnected stores do nothing at all here.
	 */
	public function cron_reconcile() {
		if ( ! (bool) get_option( 'xpay_wc_order_events_enabled', 1 ) ) {
			return;
		}
		if ( ! class_exists( 'Xpay_Plugin' ) || ! Xpay_Plugin::is_connected() ) {
			return;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
		$statuses      = array_values( array_unique( array_merge( $paid_statuses, array( 'processing', 'completed' ) ) ) );

		$orders = wc_get_orders(
			array(
				'status'       => $statuses,
				'date_created' => '>' . ( time() - 7 * DAY_IN_SECONDS ),
				'limit'        => 100,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'return'       => 'objects',
			)
		);
		if ( empty( $orders ) || ! is_array( $orders ) ) {
			return;
		}

		// Bound BOTH the count AND the wall-clock. Each on_event() is a blocking
		// POST (up to 5s); without a time budget an unresponsive backend could
		// pin a WP-Cron worker for 15×5s. Stop at 20s elapsed and let the next
		// daily sweep pick up the remainder — nothing is lost, just deferred.
		$dispatched = 0;
		$started    = microtime( true );
		foreach ( $orders as $order ) {
			if ( $dispatched >= 15 || ( microtime( true ) - $started ) > 20 ) {
				break;
			}
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( $this->is_sent_or_in_flight( $order ) ) {
				continue;
			}
			$this->on_event( $order->get_id() );
			++$dispatched;
		}
	}

	/**
	 * Fast enqueue: stamp an in-flight sentinel + schedule the real handler.
	 * Stamping FIRST prevents the three hooks (payment_complete +
	 * order_status_completed + order_status_changed) from each enqueuing a
	 * separate cron job for the same order.
	 */
	public function enqueue_event( $order_id ) {
		if ( ! (bool) get_option( 'xpay_wc_order_events_enabled', 1 ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// In-flight or already sent — short-circuit. The sentinel ('queued:<ts>')
		// is overwritten with the unix epoch on successful send.
		//
		// EXCEPT when the sentinel is stale: `wp_schedule_single_event` only runs
		// when a LATER page load triggers WP-Cron, and on a low-traffic store the
		// next hit can be hours away — or never. a live merchant store has zero order-event
		// rows ever for exactly this reason. A stale sentinel therefore means
		// "cron never ran", not "in flight", and must not block a retry.
		if ( $this->is_sent_or_in_flight( $order ) ) {
			return;
		}

		$order->update_meta_data( self::SENT_META_KEY, 'queued:' . time() );
		$order->save();

		if ( ! wp_next_scheduled( self::SCHEDULED_HOOK, array( $order_id ) ) ) {
			wp_schedule_single_event( time(), self::SCHEDULED_HOOK, array( $order_id ) );
		}

		// Belt-and-braces: also dispatch inline on `shutdown`, but ONLY on SAPIs
		// where we can flush the response to the shopper FIRST. If we can't
		// early-flush, the inline POST would run while the checkout connection is
		// still open and add up to 5s to the shopper's thank-you page — so on
		// those SAPIs we rely solely on WP-Cron + the daily reconcile sweep (the
		// event still sends, just not inline).
		if ( self::can_early_flush() ) {
			$this->queue_for_shutdown( $order_id );
		}
	}

	/**
	 * True only when the current SAPI can return the response to the client
	 * BEFORE PHP shutdown runs. PHP-FPM exposes fastcgi_finish_request();
	 * LiteSpeed's LSAPI exposes litespeed_finish_request() (the fastcgi alias was
	 * removed from php-src). Apache mod_php / CGI / plain FastCGI expose NEITHER,
	 * so on those the client waits for the whole request including shutdown — we
	 * must not run a blocking POST there.
	 */
	private static function can_early_flush() {
		return function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
	}

	/**
	 * True when the order has already been sent, or has a FRESH in-flight
	 * sentinel. A `queued:<ts>` older than SENTINEL_STALE_SECONDS is treated as
	 * abandoned (cron never woke) and reported as not-in-flight so we re-queue.
	 */
	private function is_sent_or_in_flight( $order ) {
		$sent_at = (string) $order->get_meta( self::SENT_META_KEY, true );
		if ( '' === $sent_at ) {
			return false;
		}
		if ( 0 !== strpos( $sent_at, 'queued:' ) ) {
			return true; // an epoch → already sent.
		}
		$queued_at = (int) substr( $sent_at, strlen( 'queued:' ) );
		return ( time() - $queued_at ) < self::SENTINEL_STALE_SECONDS;
	}

	/**
	 * Register the order for an inline send on `shutdown` — i.e. AFTER the
	 * response has gone out (PHP-FPM flushes via fastcgi_finish_request), so the
	 * shopper's thank-you page never waits on our outbound POST.
	 */
	private function queue_for_shutdown( $order_id ) {
		$order_id = (int) $order_id;
		if ( in_array( $order_id, $this->shutdown_queue, true ) ) {
			return;
		}
		if ( empty( $this->shutdown_queue ) ) {
			add_action( 'shutdown', array( $this, 'dispatch_shutdown_queue' ), 100 );
		}
		$this->shutdown_queue[] = $order_id;
	}

	/**
	 * Inline dispatcher. Runs on shutdown for anything enqueued this request.
	 * on_event() re-reads the sentinel, so if WP-Cron already delivered the
	 * event this is a cheap no-op.
	 */
	public function dispatch_shutdown_queue() {
		// Flush the response to the shopper before the blocking POST. queue_for_
		// shutdown() only ran if one of these exists (see can_early_flush()), so on
		// every SAPI that reaches here the connection is closed first.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		foreach ( $this->shutdown_queue as $order_id ) {
			$this->on_event( $order_id );
		}
		$this->shutdown_queue = array();
	}

	/**
	 * Safety-net status-change hook.
	 *
	 * The old guard was a hard `completed || processing` whitelist, which
	 * silently dropped every order that (a) pays by an offline gateway
	 * (cheque/BACS/COD — no payment_complete event) and (b) is fulfilled through
	 * a CUSTOM status. La Poste routes pending → lpc_transit → lpc_delivered and
	 * never touches `completed`: on a live merchant store that was ~21% of all orders.
	 *
	 * Now: fire on any status WooCommerce considers paid (`wc_get_is_paid_statuses()`
	 * is filterable, so plugins that register their own paid statuses are covered),
	 * plus a fallback for the stacks that FORGET to register theirs — any
	 * transition on an order that has a paid date but was never sent.
	 *
	 * ⛔ The `get_date_paid()` fallback MUST exclude terminal-negative statuses.
	 * A refunded/cancelled/failed order KEEPS its date_paid — WooCommerce does not
	 * clear it on refund — so without the guard below, a refund transition would
	 * fire an event that reports the FULL amount_total with status=refunded. If
	 * that were the first successful send (e.g. the original POST failed and
	 * cleared the sentinel, or an order predates this plugin version), the backend
	 * would store it as refunded "revenue". `wc_get_is_paid_statuses()` never
	 * contains these, but the fallback fires on ANY $to_status, so it must be
	 * filtered here explicitly.
	 */
	public function on_status_changed( $order_id, $from_status, $to_status ) {
		$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );

		$fire = in_array( $to_status, $paid_statuses, true )
			|| 'completed' === $to_status
			|| 'processing' === $to_status;

		if ( ! $fire ) {
			// Money-not-collected / not-a-sale states. The date_paid fallback must
			// never fire on these even though a refunded order still has a paid date.
			$negative = array( 'refunded', 'cancelled', 'failed', 'trash', 'pending', 'checkout-draft', 'on-hold' );
			if ( ! in_array( $to_status, $negative, true ) ) {
				$order = wc_get_order( $order_id );
				$fire  = $order instanceof WC_Order
					&& $order->get_date_paid()
					&& ! $order->get_meta( self::SENT_META_KEY, true );
			}
		}

		if ( $fire ) {
			$this->enqueue_event( $order_id );
		}
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

		// Idempotency at the plugin layer. Anything OTHER than 'queued:*'
		// means a previous run already sent (or was sent and we recorded the
		// epoch). Queued = our own enqueue stamp = OK to proceed.
		$sent_at = (string) $order->get_meta( self::SENT_META_KEY, true );
		if ( '' !== $sent_at && 0 !== strpos( $sent_at, 'queued:' ) ) {
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

		// Tight timeout because we're already in the async dispatcher — the
		// payment-complete UX has long since returned. Failure here just
		// means we don't stamp the meta and a later status flip will retry.
		$response = Xpay_Client::post(
			'/v1/merchants/' . rawurlencode( $slug ) . '/orders',
			$payload,
			5
		);

		// Xpay_Client::post returns array on 2xx, WP_Error on transport
		// failure, or the decoded body on 4xx/5xx. Treat anything non-WP_Error
		// as "backend received it"; the backend's idempotent rejection is OK.
		if ( is_wp_error( $response ) ) {
			// Don't stamp the meta on transport failure — clear the queued
			// sentinel so a later status flip (or manual replay) can re-fire.
			$order->delete_meta_data( self::SENT_META_KEY );
			$order->save();
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
		if ( ! empty( $source_resolution['agentic'] ) ) {
			$payload['agentic'] = $source_resolution['agentic'];
		}

		// Channel context for EVERY order, attributed or not — the honest
		// denominator. Backend accepts it as `wc_origin` (order-ingest.ts).
		$wc_origin = $this->build_wc_origin( $order );
		if ( ! empty( $wc_origin ) ) {
			$payload['wc_origin'] = $wc_origin;
		}

		return $payload;
	}

	/**
	 * Resolve the order's attribution source. Returns:
	 *   [ 'source' => 'agentic_checkout' | 'agent_cart_deeplink' | 'ref_utm'
	 *                 | 'ref_classified' | 'unattributed',
	 *     'source_detail' => array,  // when source = agent_cart_deeplink
	 *     'ref' => array,            // when source = ref_* / agentic_checkout
	 *     'agentic' => array,        // when source = agentic_checkout
	 *   ]
	 */
	private function resolve_source( $order ) {
		// Layer 0 — WooCommerce core's own Agentic Checkout rail. Runs ahead of
		// every layer below because it is the only DETERMINISTIC one: the others
		// all infer from utm/referrer/UA. See resolve_agentic_checkout().
		$agentic = $this->resolve_agentic_checkout( $order );
		if ( $agentic ) {
			return $agentic;
		}

		// Layer 1 — the cart deeplink (`?xpay_cart=`) path already writes
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

		// Layer 2+3 — Xpay_Attribution writes _xpay_ref_inbound on
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

		// Layer 4 — WooCommerce core Order Attribution (8.5+). A client-side JS
		// stamp, so it SURVIVES the full-page caches that starve our PHP
		// classifier. On a WP Rocket store this is currently the only layer that
		// fires at all: a live merchant store has 78 order rows and exactly ONE carries a
		// `ref` map. Everything above this line has been dead there for months.
		$wc_native = $this->resolve_wc_native( $order );
		if ( $wc_native ) {
			return $wc_native;
		}

		return array( 'source' => 'unattributed' );
	}

	/**
	 * Layer 0 — WooCommerce core Agentic Checkout (Agentic routes 10.4+;
	 * `agentic_commerce` gateway 10.7). Core writes `_agentic_checkout_session_id`
	 * onto the order in StoreApi/Routes/V1/Agentic/CheckoutSessionsComplete.php.
	 *
	 * This is the ONLY deterministic agent→order link that exists on a
	 * WooCommerce store — every other layer is inference. It lives inside the
	 * merchant's own order table, where edge/CDN analytics structurally cannot
	 * reach.
	 *
	 * ⛔ Scope "deterministic" honestly: for today's ChatGPT ACP calls this is
	 * BILATERAL-CONTRACTUAL (a bearer key issued to the provider), NOT
	 * cryptographic. Only Web Bot Auth (Signature-Agent, RFC 9421) is
	 * cryptographic. Merchant-facing copy must not blur the two.
	 *
	 * ⛔ Forward-only. This fires for orders that FLOW THROUGH agentic checkout;
	 * it recovers nothing retroactively.
	 */
	private function resolve_agentic_checkout( $order ) {
		$session_id = (string) $order->get_meta( '_agentic_checkout_session_id', true );
		if ( '' === $session_id ) {
			return null;
		}

		$agentic = array( 'checkout_session_id' => $session_id );

		$provider_id = (string) $order->get_meta( '_agentic_checkout_provider_id', true );
		if ( '' !== $provider_id ) {
			$agentic['provider_id'] = $provider_id;
		}

		return array(
			'source'  => 'agentic_checkout',
			'ref'     => array(
				'source'     => $this->agentic_provider( $order, $provider_id ),
				'confidence' => 'deterministic',
				'method'     => 'wc_agentic_checkout',
			),
			'agentic' => $agentic,
		);
	}

	/**
	 * Name the agent behind an agentic-checkout order. WooCommerce doesn't
	 * mandate a provider vocabulary, so map what we can and stay honest
	 * ('unknown-agent') about what we can't — never guess 'chatgpt' just
	 * because it's the likeliest.
	 */
	private function agentic_provider( $order, $provider_id = '' ) {
		$haystack = strtolower( $provider_id . ' ' . (string) $order->get_payment_method() . ' ' . (string) $order->get_payment_method_title() );

		$known = array(
			'chatgpt'    => 'chatgpt',
			'openai'     => 'chatgpt',
			'perplexity' => 'perplexity',
			'claude'     => 'claude',
			'anthropic'  => 'claude',
			'gemini'     => 'gemini',
			'copilot'    => 'copilot',
		);
		foreach ( $known as $needle => $source ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return $source;
			}
		}
		return 'unknown-agent';
	}

	/**
	 * Layer 4 — WooCommerce core Order Attribution meta (WC 8.5+).
	 *
	 * Core ships a client-side sourcebuster tracker that stamps
	 * `_wc_order_attribution_*` on every order. Because it's JS in the buyer's
	 * browser it is immune to the page-cache break that kills our PHP
	 * classifier (which is hooked on template_redirect and never runs for a
	 * cached visitor).
	 *
	 * Graceful degradation: on WC < 8.5, or with Order Attribution disabled, the
	 * meta simply isn't there → returns null → behaviour identical to today.
	 */
	private function resolve_wc_native( $order ) {
		$ruleset = Xpay_Attribution::default_ruleset();

		// a) utm_source stamped by WC core (ChatGPT appends utm_source=chatgpt.com).
		$utm = strtolower( (string) $order->get_meta( '_wc_order_attribution_utm_source', true ) );
		if ( '' !== $utm && isset( $ruleset['utm_sources'][ $utm ] ) ) {
			return array(
				'source' => 'ref_utm',
				'ref'    => array(
					'source'     => $ruleset['utm_sources'][ $utm ],
					'confidence' => 'high',
					'method'     => 'wc_native_utm',
				),
			);
		}

		// b) referrer captured by WC core → run through the SAME host ruleset the
		// live classifier uses (Xpay_Attribution::match_referrer).
		$referrer = (string) $order->get_meta( '_wc_order_attribution_referrer', true );
		if ( '' !== $referrer ) {
			$match = Xpay_Attribution::match_referrer( $referrer );
			if ( $match ) {
				return array(
					'source' => 'ref_classified',
					'ref'    => array(
						'source'     => $match,
						'confidence' => 'high',
						'method'     => 'wc_native_referer',
					),
				);
			}
		}

		return null;
	}

	/**
	 * Channel context from WC core's tracker, sent for EVERY order — attributed
	 * or not — so the dashboard has an honest denominator instead of only ever
	 * seeing the orders we managed to claim.
	 *
	 * `entry_path` is the PATH ONLY: host, query and fragment are stripped so no
	 * token or PII can ride along. That path is what lets us spot the
	 * "typein order that landed straight on a deep PDP" signature (the mobile
	 * AI-app / ChatGPT-Atlas link-out shape) without speculating client-side.
	 */
	private function build_wc_origin( $order ) {
		$source_type = (string) $order->get_meta( '_wc_order_attribution_source_type', true );
		$entry       = (string) $order->get_meta( '_wc_order_attribution_session_entry', true );
		$device      = (string) $order->get_meta( '_wc_order_attribution_device_type', true );

		$origin = array();
		if ( '' !== $source_type ) {
			$origin['source_type'] = $source_type;
		}
		$entry_path = $this->path_only( $entry );
		if ( '' !== $entry_path ) {
			$origin['entry_path'] = $entry_path;
		}
		if ( '' !== $device ) {
			$origin['device'] = $device;
		}
		return $origin;
	}

	/** Path component of a URL — host, query and fragment discarded. */
	private function path_only( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			// Already a bare path (WC sometimes stores it that way), or unparseable.
			$path = explode( '?', $url, 2 )[0];
			$path = explode( '#', $path, 2 )[0];
			if ( '' === $path || '/' !== substr( $path, 0, 1 ) ) {
				return '';
			}
		}
		return substr( $path, 0, 256 );
	}
}
