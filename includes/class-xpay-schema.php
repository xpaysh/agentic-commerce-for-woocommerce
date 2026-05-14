<?php
/**
 * Emits JSON-LD that AI shopping agents look for:
 *   - PDP: Product + Offer + BuyAction (the audit's `live_pricing` + `direct_buy`)
 *   - Shop archive / homepage: ItemList of products with embedded Offers
 *
 * Conflict-safe: detects pre-existing <script type="application/ld+json">
 * Product blocks emitted by Yoast / Rank Math / WooCommerce core and either
 * suppresses our Product block or only adds the missing fields (BuyAction).
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Schema {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Late-priority head hook so we land after Yoast/Rank Math.
		add_action( 'wp_head', array( $this, 'render' ), 99 );
	}

	public function render() {
		if ( is_admin() ) {
			return;
		}
		if ( is_product() ) {
			$this->render_product();
			return;
		}
		if ( is_shop() || is_product_category() || is_product_tag() ) {
			$this->render_item_list( 'archive' );
			return;
		}
		if ( is_front_page() || is_home() ) {
			$this->render_item_list( 'home' );
		}
	}

	private function render_product() {
		global $product;
		if ( ! ( $product instanceof WC_Product ) ) {
			$product = wc_get_product( get_the_ID() );
		}
		if ( ! $product ) {
			return;
		}

		$url   = get_permalink( $product->get_id() );
		$price = $product->get_price();
		$cur   = get_woocommerce_currency();

		$offer = array(
			'@type'           => 'Offer',
			'priceCurrency'   => $cur,
			'price'           => $price ? wc_format_decimal( $price, wc_get_price_decimals() ) : null,
			'availability'    => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
			'url'             => $url,
			'priceValidUntil' => gmdate( 'Y-12-31' ),
		);

		$buy_target = add_query_arg( 'add-to-cart', $product->get_id(), $url );

		$product_node = array(
			'@context'        => 'https://schema.org/',
			'@type'           => 'Product',
			'name'            => wp_strip_all_tags( $product->get_name() ),
			'sku'             => $product->get_sku() ? $product->get_sku() : (string) $product->get_id(),
			'image'           => $this->product_images( $product ),
			'description'     => wp_strip_all_tags( $product->get_short_description() ? $product->get_short_description() : $product->get_description() ),
			'url'             => $url,
			'offers'          => $offer,
			'potentialAction' => array(
				'@type'               => 'BuyAction',
				'target'              => $buy_target,
				'expectsAcceptanceOf' => array(
					'@type'         => 'Offer',
					'price'         => $offer['price'],
					'priceCurrency' => $cur,
				),
			),
		);

		$rating_count = $product->get_rating_count();
		if ( $rating_count > 0 ) {
			$product_node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (float) $product->get_average_rating(),
				'reviewCount' => (int) $rating_count,
			);
		}

		// If another plugin already emitted a Product schema, only add the BuyAction
		// (which Yoast / Rank Math / WC don't emit) and the agent-feed URL.
		if ( $this->already_emitted_product_schema() ) {
			$slim = array(
				'@context'        => 'https://schema.org/',
				'@type'           => 'Product',
				'@id'             => $url . '#xpay-buyaction',
				'sku'             => $product_node['sku'],
				'url'             => $url,
				'potentialAction' => $product_node['potentialAction'],
			);
			$this->print_jsonld( $slim );
			return;
		}

		$this->print_jsonld( $product_node );
	}

	private function render_item_list( $context ) {
		$cache_key = 'xpay_wc_homepage_itemlist';
		$cached    = 'home' === $context ? get_transient( $cache_key ) : false;
		if ( $cached ) {
			$this->print_raw( $cached );
			return;
		}

		$query    = new WC_Product_Query(
			array(
				'limit'   => 20,
				'status'  => 'publish',
				'orderby' => 'popularity',
				'return'  => 'objects',
			)
		);
		$products = $query->get_products();
		if ( empty( $products ) ) {
			return;
		}

		$items = array();
		$pos   = 1;
		foreach ( $products as $p ) {
			$url     = get_permalink( $p->get_id() );
			$price   = $p->get_price();
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'item'     => array(
					'@type'  => 'Product',
					'name'   => wp_strip_all_tags( $p->get_name() ),
					'sku'    => $p->get_sku() ? $p->get_sku() : (string) $p->get_id(),
					'url'    => $url,
					'image'  => wp_get_attachment_image_url( $p->get_image_id(), 'medium' ),
					'offers' => array(
						'@type'         => 'Offer',
						'price'         => $price ? wc_format_decimal( $price, wc_get_price_decimals() ) : null,
						'priceCurrency' => get_woocommerce_currency(),
						'availability'  => $p->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
						'url'           => $url,
					),
				),
			);
		}

		$node = array(
			'@context'        => 'https://schema.org/',
			'@type'           => 'ItemList',
			'name'            => 'home' === $context ? get_bloginfo( 'name' ) . ' — featured products' : null,
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);

		$rendered = '<script type="application/ld+json" data-emitter="xpay">' .
			wp_json_encode( $node, JSON_UNESCAPED_SLASHES ) .
			'</script>' . "\n";

		if ( 'home' === $context ) {
			set_transient( $cache_key, $rendered, 15 * MINUTE_IN_SECONDS );
		}
		$this->print_raw( $rendered );
	}

	private function product_images( $product ) {
		$ids    = array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
		$images = array();
		foreach ( $ids as $id ) {
			$src = wp_get_attachment_image_url( $id, 'large' );
			if ( $src ) {
				$images[] = $src;
			}
		}
		return $images ? $images : null;
	}

	/**
	 * Cheap heuristic: look at the buffered <head> output so far for a Product
	 * JSON-LD block. We call ob_get_contents() — if output buffering isn't on,
	 * we fall back to "false" (safe: we'll just emit our own).
	 */
	private function already_emitted_product_schema() {
		if ( ! ob_get_level() ) {
			return false;
		}
		$buffer = ob_get_contents();
		if ( ! $buffer ) {
			return false;
		}
		return (bool) preg_match( '#<script[^>]*application/ld\+json[^>]*>[^<]*"@type"\s*:\s*"Product"#i', $buffer );
	}

	private function print_jsonld( array $node ) {
		echo '<script type="application/ld+json" data-emitter="xpay">';
		echo wp_json_encode( $node, JSON_UNESCAPED_SLASHES );
		echo "</script>\n";
	}

	private function print_raw( $html ) {
		// Safe: $html is a previously wp_json_encode()'d JSON-LD string we wrapped
		// in <script type="application/ld+json"> tags ourselves. Escaping it would
		// double-encode and break the schema. phpcs gets a false positive here.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
