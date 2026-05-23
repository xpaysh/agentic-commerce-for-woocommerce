<?php
/**
 * Tells the xpay backend when products / prices / stock change, so the hosted
 * catalog feed at agent-feed.xpay.sh/catalog/{slug}.json stays current.
 *
 * Two paths:
 *
 *   - Delta (M4.2): each WC product hook captures the product_id, debounces via
 *     a single-event cron keyed on the product_id, then POSTs a single
 *     normalized AcpProduct to PATCH /v1/merchants/{slug}/products/{sku}.
 *     Variation stock changes roll up to the parent product. Deletes /
 *     visibility flips are sent as `{deleted: true}`.
 *
 *   - Full resync (legacy): kept as a fallback (e.g. when the PATCH endpoint
 *     returns a non-recoverable error) and as the bulk-import path. Hourly
 *     scheduledResync on the backend covers anything we miss.
 *
 * The debounce window keeps a bulk edit of N products to N (not N²) backend
 * writes, and rapid edits to the same product coalesce.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Webhooks {

	private static $instance = null;
	const RESYNC_HOOK             = 'xpay_wc_resync_catalog';
	const PRODUCT_DELTA_HOOK      = 'xpay_wc_product_delta';
	const DEBOUNCE_SECONDS        = 30;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_update_product', array( $this, 'on_product_change' ), 10, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'on_product_change' ), 10, 1 );
		add_action( 'woocommerce_delete_product', array( $this, 'on_product_delete' ), 10, 1 );
		add_action( 'woocommerce_trash_product', array( $this, 'on_product_delete' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_change_product' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_change_variation' ), 10, 1 );

		add_action( self::PRODUCT_DELTA_HOOK, array( $this, 'do_product_delta' ), 10, 2 );
		add_action( self::RESYNC_HOOK, array( $this, 'do_resync' ) );
	}

	// ---- Hook handlers --------------------------------------------------------

	public function on_product_change( $product_id ) {
		$this->schedule_product_delta( (int) $product_id, 'update' );
	}

	public function on_product_delete( $product_id ) {
		$this->schedule_product_delta( (int) $product_id, 'delete' );
	}

	public function on_stock_change_product( $product ) {
		$id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : (int) $product;
		$this->schedule_product_delta( $id, 'update' );
	}

	public function on_stock_change_variation( $variation_id ) {
		// Variation stock changes propagate to the parent product's
		// aggregate availability + stock_quantity. The variation_id itself
		// has no standalone catalog entry.
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$variation = wc_get_product( (int) $variation_id );
		if ( ! $variation || ! method_exists( $variation, 'get_parent_id' ) ) {
			return;
		}
		$parent_id = (int) $variation->get_parent_id();
		if ( $parent_id > 0 ) {
			$this->schedule_product_delta( $parent_id, 'update' );
		}
	}

	private function schedule_product_delta( $product_id, $op ) {
		if ( ! Xpay_Plugin::is_connected() ) {
			return;
		}
		if ( $product_id <= 0 ) {
			return;
		}
		$args = array( $product_id, $op );
		if ( wp_next_scheduled( self::PRODUCT_DELTA_HOOK, $args ) ) {
			// Same (product, op) already queued — debounce.
			return;
		}
		// If a delete is queued, don't also queue an update for the same id.
		// Wp-cron looks at the literal args tuple so we check the inverse op.
		$inverse = 'delete' === $op ? 'update' : 'delete';
		$inverse_scheduled = wp_next_scheduled( self::PRODUCT_DELTA_HOOK, array( $product_id, $inverse ) );
		if ( 'delete' === $op && $inverse_scheduled ) {
			// A pending update is now superseded by a delete — drop it.
			wp_unschedule_event( $inverse_scheduled, self::PRODUCT_DELTA_HOOK, array( $product_id, $inverse ) );
		} elseif ( 'update' === $op && $inverse_scheduled ) {
			// A pending delete wins. Drop the redundant update request.
			return;
		}
		wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, self::PRODUCT_DELTA_HOOK, $args );
	}

	// ---- Cron callbacks -------------------------------------------------------

	public function do_product_delta( $product_id, $op ) {
		if ( ! Xpay_Plugin::is_connected() ) {
			return;
		}
		$slug = Xpay_Plugin::merchant_slug();
		if ( ! $slug ) {
			return;
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;

		// Determine the SKU we PATCH against. The S3 catalog row keys on
		// p.sku || String(p.id) — mirror that fallback so paths match.
		$sku = '';
		if ( $product && method_exists( $product, 'get_sku' ) ) {
			$sku = (string) $product->get_sku();
		}
		if ( '' === $sku ) {
			$sku = (string) ( (int) $product_id );
		}

		if ( 'delete' === $op || ! $product || ( method_exists( $product, 'get_status' ) && in_array( $product->get_status(), array( 'trash', 'draft', 'pending', 'auto-draft' ), true ) ) || ( method_exists( $product, 'get_catalog_visibility' ) && 'hidden' === $product->get_catalog_visibility() ) ) {
			// Treat hidden / unpublished products as deletes — mirror the
			// resync.ts normalize() filter so the delta path doesn't leave
			// stale products in the catalog.
			$this->send_delta( $slug, $sku, array( 'deleted' => true ), $product_id );
			return;
		}

		$acp = $this->normalize_product( $product );
		if ( null === $acp ) {
			return;
		}
		$this->send_delta( $slug, $sku, array( 'product' => $acp ), $product_id );
	}

	private function send_delta( $slug, $sku, $body, $product_id ) {
		$path = '/v1/merchants/' . rawurlencode( $slug ) . '/products/' . rawurlencode( $sku );
		$result = Xpay_Client::patch( $path, $body, 10 );
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			Xpay_Telemetry::track(
				'product_delta_error',
				array(
					'sku'       => $sku,
					'pid'       => (int) $product_id,
					'message'   => $result->get_error_message(),
					'code'      => $code,
				)
			);
			// Hard failure (auth / 5xx). Fall back to a full resync so the
			// edit isn't lost. Hourly scheduledResync also covers this, but
			// a same-day fallback feels safer for the merchant.
			$this->schedule_resync();
			return;
		}
		update_option( 'xpay_wc_last_sync_at', time() );
		Xpay_Telemetry::track(
			'product_delta_success',
			array(
				'sku' => $sku,
				'pid' => (int) $product_id,
				'op'  => isset( $body['deleted'] ) ? 'delete' : 'update',
			)
		);
	}

	/**
	 * Mirror of backend/wc-plugin-setup/src/resync.ts normalize(). Keep these
	 * two functions in sync — the delta path must produce the same shape as a
	 * full resync, or the next full rebuild would silently change row contents.
	 */
	private function normalize_product( $product ) {
		if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
			return null;
		}
		$id    = (int) $product->get_id();
		$sku   = (string) $product->get_sku();
		$name  = (string) $product->get_name();
		$price = (string) $product->get_price();
		$short = method_exists( $product, 'get_short_description' ) ? (string) $product->get_short_description() : '';
		$long  = method_exists( $product, 'get_description' ) ? (string) $product->get_description() : '';
		$desc  = '' !== $short ? $short : $long;
		$url   = method_exists( $product, 'get_permalink' ) ? (string) $product->get_permalink() : '';

		$images = array();
		if ( method_exists( $product, 'get_gallery_image_ids' ) ) {
			$ids = array_merge( array( (int) $product->get_image_id() ), $product->get_gallery_image_ids() );
			foreach ( array_unique( array_filter( $ids ) ) as $img_id ) {
				$src = wp_get_attachment_url( $img_id );
				if ( $src ) {
					$images[] = $src;
				}
			}
		}

		$stock_status   = method_exists( $product, 'get_stock_status' ) ? $product->get_stock_status() : 'instock';
		$availability   = 'OutOfStock';
		if ( 'instock' === $stock_status ) {
			$availability = 'InStock';
		} elseif ( 'onbackorder' === $stock_status ) {
			$availability = 'LimitedAvailability';
		}
		$stock_quantity = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;

		$categories = array();
		if ( method_exists( $product, 'get_category_ids' ) ) {
			foreach ( $product->get_category_ids() as $cat_id ) {
				$term = get_term( (int) $cat_id, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$categories[] = $term->name;
				}
			}
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';

		$out = array(
			'sku'          => '' !== $sku ? $sku : (string) $id,
			'id'           => $id,
			'name'         => $name,
			'url'          => $url,
			'price'        => $price,
			'currency'     => $currency,
			'availability' => $availability,
		);
		if ( '' !== $desc ) {
			$out['description'] = wp_strip_all_tags( $desc );
		}
		if ( ! empty( $images ) ) {
			$out['image']  = $images[0];
			$out['images'] = $images;
		}
		if ( null !== $stock_quantity ) {
			$out['stock_quantity'] = (int) $stock_quantity;
		}
		if ( ! empty( $categories ) ) {
			$out['categories'] = $categories;
		}
		return $out;
	}

	// ---- Full resync fallback -------------------------------------------------

	public function schedule_resync() {
		if ( ! Xpay_Plugin::is_connected() ) {
			return;
		}
		if ( wp_next_scheduled( self::RESYNC_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + self::DEBOUNCE_SECONDS, self::RESYNC_HOOK );
	}

	public function do_resync() {
		$result = Xpay_Client::post(
			'/v1/merchants/' . Xpay_Plugin::merchant_slug() . '/resync',
			array(
				'reason'    => 'fallback_after_delta_error',
				'origin'    => home_url( '/' ),
				'timestamp' => time(),
			)
		);
		if ( is_wp_error( $result ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[xpay] resync failed: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			Xpay_Telemetry::track(
				'resync_error',
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				)
			);
			return;
		}
		update_option( 'xpay_wc_last_sync_at', time() );
		Xpay_Telemetry::track(
			'resync_success',
			array(
				'product_count' => is_array( $result ) && isset( $result['count'] ) ? (int) $result['count'] : null,
			)
		);
	}
}
