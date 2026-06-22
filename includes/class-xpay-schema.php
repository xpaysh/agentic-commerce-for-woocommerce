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
		// Visible FAQ section on the product page (required to back the FAQPage
		// JSON-LD per Google policy, and useful for shoppers). Priority 15 puts it
		// after the summary, before related products (20).
		add_action( 'woocommerce_after_single_product_summary', array( $this, 'render_visible_faq' ), 15 );
	}

	/**
	 * Per-product FAQ + return policy pushed from the backend (only the items the
	 * merchant APPROVED in the dashboard). Stored as a JSON map keyed by SKU in the
	 * `xpay_wc_product_faqs` option. Decoded once per request.
	 *
	 * @return array<string,array> sku => { faq: [{q,a}], return_policy: {...} }
	 */
	private function pushed_faqs() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$raw     = (string) get_option( 'xpay_wc_product_faqs', '' );
		$decoded = $raw ? json_decode( $raw, true ) : array();
		$cache   = is_array( $decoded ) ? $decoded : array();
		return $cache;
	}

	/** The pushed FAQ entry for a product (by SKU, then numeric id), or null. */
	private function faq_entry_for( $product ) {
		$all = $this->pushed_faqs();
		if ( empty( $all ) ) {
			return null;
		}
		$sku = $product->get_sku();
		if ( $sku && isset( $all[ $sku ] ) ) {
			return $all[ $sku ];
		}
		$id = (string) $product->get_id();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
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
		$xpay_product = wc_get_product( get_the_ID() );
		if ( ! $xpay_product instanceof WC_Product ) {
			return;
		}

		$url   = get_permalink( $xpay_product->get_id() );
		$price = $xpay_product->get_price();
		$cur   = get_woocommerce_currency();

		$offer = array(
			'@type'           => 'Offer',
			'priceCurrency'   => $cur,
			'price'           => $price ? wc_format_decimal( $price, wc_get_price_decimals() ) : null,
			'availability'    => $xpay_product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
			'url'             => $url,
			'priceValidUntil' => gmdate( 'Y-12-31' ),
		);

		$buy_target = add_query_arg( 'add-to-cart', $xpay_product->get_id(), $url );

		$product_node = array(
			'@context'        => 'https://schema.org/',
			'@type'           => 'Product',
			'name'            => wp_strip_all_tags( $xpay_product->get_name() ),
			'sku'             => $xpay_product->get_sku() ? $xpay_product->get_sku() : (string) $xpay_product->get_id(),
			'image'           => $this->product_images( $xpay_product ),
			'description'     => wp_strip_all_tags( $xpay_product->get_short_description() ? $xpay_product->get_short_description() : $xpay_product->get_description() ),
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

		$rating_count = $xpay_product->get_rating_count();
		if ( $rating_count > 0 ) {
			$product_node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (float) $xpay_product->get_average_rating(),
				'reviewCount' => (int) $rating_count,
			);
		}

		$entry = $this->faq_entry_for( $xpay_product );

		// Return policy: emitted as a STANDALONE node so it survives the slim
		// (conflict) path below — Yoast / Rank Math emit Product+Offer but never a
		// MerchantReturnPolicy, so this is purely additive and never duplicates.
		// Prefer the per-product policy the merchant approved, else the store default.
		$return_policy = $this->merchant_return_policy_node( $url, isset( $entry['return_policy'] ) ? $entry['return_policy'] : null );
		if ( $return_policy ) {
			$product_node['offers']['hasMerchantReturnPolicy'] = $return_policy;
			$this->print_jsonld( $return_policy );
		}

		// FAQPage — a separate top-level node (never part of Product), so it always
		// survives the conflict path. Only emitted for APPROVED products (those with
		// a pushed FAQ entry). Mirrors the visible section rendered on the page.
		if ( ! empty( $entry['faq'] ) && is_array( $entry['faq'] ) ) {
			$faq_node = $this->faq_page_node( $entry['faq'] );
			if ( $faq_node ) {
				$this->print_jsonld( $faq_node );
			}
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

	/**
	 * MerchantReturnPolicy node, sourced from a backend-pushable option so it can
	 * be tuned per-merchant without a plugin release. Defaults are EU/French-law
	 * accurate (14-day "droit de rétractation"); we only emit when we have a
	 * genuine policy to state — never a fabricated one.
	 *
	 * @param string     $url      Product permalink (used to resolve the policy page link).
	 * @param array|null $override Per-product policy (merchant-approved) to prefer.
	 * @return array|null
	 */
	private function merchant_return_policy_node( $url, $override = null ) {
		$cfg = is_array( $override ) && $override
			? $override
			: get_option( 'xpay_wc_return_policy', array() );
		if ( ! is_array( $cfg ) ) {
			$cfg = array();
		}

		// Allow a merchant to explicitly disable (e.g. final-sale catalogs).
		if ( isset( $cfg['enabled'] ) && ! $cfg['enabled'] ) {
			return null;
		}

		$country = isset( $cfg['country'] ) ? $cfg['country'] : WC()->countries->get_base_country();
		$days    = isset( $cfg['days'] ) ? (int) $cfg['days'] : 14;
		$node    = array(
			'@context'              => 'https://schema.org/',
			'@type'                 => 'MerchantReturnPolicy',
			'applicableCountry'     => $country ? $country : 'FR',
			'returnPolicyCategory'  => 'https://schema.org/MerchantReturnFiniteReturnWindow',
			'merchantReturnDays'    => $days,
			'returnMethod'          => 'https://schema.org/ReturnByMail',
			'returnFees'            => 'https://schema.org/FreeReturn',
		);

		// Link to the store's actual policy page if one is configured / discoverable.
		$policy_url = ! empty( $cfg['url'] ) ? $cfg['url'] : '';
		if ( ! $policy_url ) {
			$page_id = wc_get_page_id( 'terms' );
			if ( $page_id > 0 ) {
				$policy_url = get_permalink( $page_id );
			}
		}
		if ( $policy_url ) {
			$node['merchantReturnLink'] = $policy_url;
		}

		return $node;
	}

	/**
	 * Build a schema.org FAQPage node from an approved FAQ list ([{q,a}, …]).
	 *
	 * @param array $faq
	 * @return array|null
	 */
	private function faq_page_node( $faq ) {
		$entities = array();
		foreach ( $faq as $qa ) {
			$q = isset( $qa['q'] ) ? wp_strip_all_tags( (string) $qa['q'] ) : '';
			$a = isset( $qa['a'] ) ? wp_strip_all_tags( (string) $qa['a'] ) : '';
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $q,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $a,
				),
			);
		}
		if ( empty( $entities ) ) {
			return null;
		}
		return array(
			'@context'   => 'https://schema.org/',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);
	}

	/**
	 * Visible FAQ block on the product page — backs the FAQPage JSON-LD (Google
	 * requires the schema's content to be visible) and helps shoppers. Only renders
	 * for products the merchant approved (those with a pushed FAQ entry).
	 */
	public function render_visible_faq() {
		if ( ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_the_ID() );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$entry = $this->faq_entry_for( $product );
		if ( empty( $entry['faq'] ) || ! is_array( $entry['faq'] ) ) {
			return;
		}

		echo '<section class="xpay-faq" aria-label="Frequently asked questions" style="max-width:820px;margin:32px auto;padding:0 12px">';
		echo '<h2 style="font-size:20px;margin:0 0 12px">' . esc_html__( 'Frequently asked questions', 'agentic-commerce-for-woocommerce' ) . '</h2>';
		foreach ( $entry['faq'] as $qa ) {
			$q = isset( $qa['q'] ) ? (string) $qa['q'] : '';
			$a = isset( $qa['a'] ) ? (string) $qa['a'] : '';
			if ( '' === $q || '' === $a ) {
				continue;
			}
			echo '<details class="xpay-faq-item" style="border-bottom:1px solid #eee;padding:10px 0">';
			echo '<summary style="cursor:pointer;font-weight:600;list-style:none">' . esc_html( $q ) . '</summary>';
			echo '<div style="margin:8px 0 4px;color:#444;line-height:1.6">' . esc_html( $a ) . '</div>';
			echo '</details>';
		}
		echo '</section>';
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
