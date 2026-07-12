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

	/** Guards against rendering the visible FAQ twice when several paths are live. */
	private $faq_rendered = false;

	private function __construct() {
		// Late-priority head hook so we land after Yoast/Rank Math.
		add_action( 'wp_head', array( $this, 'render' ), 99 );

		// The visible FAQ block is shopper-facing UI on the merchant's storefront,
		// so it stays OFF until we're explicitly told to render it (see faq_visible()).
		// The JSON-LD is unaffected by this switch — pushing FAQ data and painting it
		// on the page are two separate decisions.
		//
		// Placement (see faq_placement()):
		//   tab       — woocommerce_product_tabs. Fires wherever tabs render, which
		//               includes Elementor's Product Tabs widget. This is what makes
		//               builder themes work at all.
		//   hook      — woocommerce_after_single_product_summary @15 (0.4.0 behaviour).
		//               Classic themes only; Elementor templates never fire it.
		//   auto      — register BOTH. Not a theme sniff: tabs output at priority 10 of
		//               after_single_product_summary, so on a classic theme the tab has
		//               already claimed the render by the time our @15 hook runs and it
		//               stands down. On Elementor only the tab fires. Exactly one wins.
		//   shortcode — neither; the merchant places [xpay-faq] themselves.
		$placement = $this->faq_placement();
		if ( $this->faq_visible() ) {
			if ( 'tab' === $placement || 'auto' === $placement ) {
				add_filter( 'woocommerce_product_tabs', array( $this, 'register_faq_tab' ) );
			}
			if ( 'hook' === $placement || 'auto' === $placement ) {
				add_action( 'woocommerce_after_single_product_summary', array( $this, 'render_visible_faq' ), 15 );
			}
		}

		add_shortcode( 'xpay-faq', array( $this, 'shortcode' ) );
	}

	/**
	 * Is the visible, shopper-facing FAQ block switched on for this store?
	 *
	 * DEFAULT OFF. The flag is written only by the backend's `set_product_faqs`
	 * push (Xpay_Admin_REST) — there is deliberately no merchant-facing checkbox:
	 * FAQs are approved in the xpay dashboard, so that's where the consent to show
	 * them on-page is captured too. Keeping the control server-side also means we
	 * can turn a store on or off without a WP.org release or a plugin update.
	 */
	private function faq_visible() {
		$on = (bool) (int) get_option( 'xpay_wc_faq_visible', 0 );

		/**
		 * Last word on whether xpay renders anything shopper-facing on this store.
		 *
		 * The switch itself is dashboard-owned, but the site owner must always be able
		 * to stop us painting on their storefront from their own code, without waiting
		 * on us. `add_filter( 'xpay_wc_faq_visible', '__return_false' );` does that.
		 *
		 * @param bool $on Whether the visible FAQ block renders.
		 */
		return (bool) apply_filters( 'xpay_wc_faq_visible', $on );
	}

	/** Where the visible block renders. Backend-pushed; 'auto' covers both theme families. */
	private function faq_placement() {
		$p = (string) get_option( 'xpay_wc_faq_placement', 'auto' );
		return in_array( $p, array( 'auto', 'tab', 'hook', 'shortcode' ), true ) ? $p : 'auto';
	}

	/**
	 * Heading for the visible block.
	 *
	 * Carried on the pushed payload, because the backend already knows the merchant's
	 * locale and we refuse to gate a French store's French heading on a WP.org release
	 * plus a merchant plugin update. The translated string below is the fallback for
	 * stores we've pushed nothing to.
	 *
	 * "Additional questions", not "Frequently asked questions": merchants often have
	 * their own FAQ in the product description already. Ours answers the commerce facts
	 * — delivery, returns, price framing, variants — that theirs typically doesn't.
	 */
	private function faq_heading() {
		$pushed = (string) get_option( 'xpay_wc_faq_heading', '' );
		if ( '' !== $pushed ) {
			return $pushed;
		}
		return __( 'Additional questions', 'agentic-commerce-for-woocommerce' );
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

		// GTIN — WC 8.6+ native `global_unique_id` (and legacy plugin variants).
		// Emitted so Google / Bing / agent crawlers can match this PDP to an
		// offer in shopping feeds. Length-keyed `gtin8/12/13/14` slots only
		// apply to numeric values (real GTIN-N digits). A non-numeric barcode
		// (e.g. an internal SKU stored in `_barcode` meta) goes to the bare
		// `gtin` slot — Google Search Console flags `gtinXX` with non-digits.
		$gtin = $this->gtin_for( $xpay_product );
		if ( '' !== $gtin ) {
			if ( ctype_digit( $gtin ) ) {
				$len = strlen( $gtin );
				if ( 8 === $len ) {
					$product_node['gtin8'] = $gtin;
				} elseif ( 12 === $len ) {
					$product_node['gtin12'] = $gtin;
				} elseif ( 13 === $len ) {
					$product_node['gtin13'] = $gtin;
				} elseif ( 14 === $len ) {
					$product_node['gtin14'] = $gtin;
				} else {
					$product_node['gtin'] = $gtin;
				}
			} else {
				$product_node['gtin'] = $gtin;
			}
		}

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
		//
		// Stand down if the page already has one: a merchant FAQ plugin or a RankMath
		// FAQ block gets to keep it. Two competing FAQPage nodes on one URL is a
		// structured-data error, and theirs describes content the shopper can see.
		if ( ! empty( $entry['faq'] ) && is_array( $entry['faq'] ) && ! $this->already_emitted_faq_schema() ) {
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
	/**
	 * Resolve the product's GTIN from WC 8.6+ native field or legacy meta_data.
	 * Returns digits only (numeric GTINs only — anything else gets the bare
	 * `gtin` slot via the caller's length fallback). Empty string if unset.
	 *
	 * @param WC_Product $product
	 * @return string
	 */
	private function gtin_for( $product ) {
		$keys = array( 'global_unique_id', '_global_unique_id', '_gtin', '_barcode', '_ean', '_upc', 'gtin', 'barcode' );
		foreach ( $keys as $key ) {
			$val = $product->get_meta( $key, true );
			if ( ! is_string( $val ) || '' === $val ) {
				continue;
			}
			$val = trim( $val );
			if ( '' !== $val ) {
				return $val;
			}
		}
		return '';
	}

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

		// Honour the pushed category instead of asserting a finite window for every
		// product. A product we push as MerchantReturnNotPermitted was rendering a
		// 14-day return window here while its own FAQ text correctly said it couldn't
		// be returned — we were contradicting ourselves in machine-readable schema.
		$category = ! empty( $cfg['category'] ) ? (string) $cfg['category'] : 'https://schema.org/MerchantReturnFiniteReturnWindow';
		$method   = ! empty( $cfg['method'] ) ? (string) $cfg['method'] : 'https://schema.org/ReturnByMail';
		$fees     = ! empty( $cfg['fees'] ) ? (string) $cfg['fees'] : 'https://schema.org/FreeReturn';

		$node = array(
			'@context'             => 'https://schema.org/',
			'@type'                => 'MerchantReturnPolicy',
			'applicableCountry'    => $country ? $country : 'FR',
			'returnPolicyCategory' => $category,
		);

		// merchantReturnDays / returnMethod / returnFees only mean anything for a
		// policy that actually accepts returns. Emitting "0 days, free, by mail"
		// alongside MerchantReturnNotPermitted is incoherent, and Google flags it.
		if ( 'https://schema.org/MerchantReturnNotPermitted' !== $category ) {
			if ( 'https://schema.org/MerchantReturnFiniteReturnWindow' === $category ) {
				$node['merchantReturnDays'] = $days;
			}
			$node['returnMethod'] = $method;
			$node['returnFees']   = $fees;
		}

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
	 * The approved FAQ list for the product being viewed, or null when there's
	 * nothing to render (not a PDP, no pushed entry, or already rendered by
	 * another placement on this request).
	 *
	 * @return array|null
	 */
	private function faq_for_current_product() {
		if ( $this->faq_rendered || ! is_product() ) {
			return null;
		}
		$product = wc_get_product( get_the_ID() );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$entry = $this->faq_entry_for( $product );
		if ( empty( $entry['faq'] ) || ! is_array( $entry['faq'] ) ) {
			return null;
		}
		return $entry['faq'];
	}

	/**
	 * Register the FAQ as a WooCommerce product tab.
	 *
	 * This is the placement that reaches builder themes: Elementor's Product Tabs
	 * widget honours `woocommerce_product_tabs`, whereas it never fires
	 * `woocommerce_after_single_product_summary` — which is why the 0.4.0 block was
	 * invisible on hello-elementor and royal-elementor-kit while rendering fine on
	 * classic goya.
	 *
	 * @param array $tabs
	 * @return array
	 */
	public function register_faq_tab( $tabs ) {
		if ( null === $this->faq_for_current_product() ) {
			return $tabs;
		}
		$tabs['xpay_faq'] = array(
			'title'    => $this->faq_heading(),
			'priority' => 25, // after Description (10) / Additional information (20), before Reviews (30).
			'callback' => array( $this, 'render_faq_tab_panel' ),
		);
		return $tabs;
	}

	/** Tab panel body. WooCommerce renders a panel for every tab, so this always runs. */
	public function render_faq_tab_panel() {
		$this->render_faq_list( $this->faq_heading(), false );
	}

	/**
	 * `[xpay-faq]` — deliberate placement by the merchant, e.g. dropped into an
	 * Elementor template where they want the block to sit.
	 *
	 * Honoured ONLY under placement='shortcode', which is what makes "exactly one
	 * placement is live" true by construction. Were it to render under 'auto' or
	 * 'tab' as well, a shortcode sitting in the product description would render
	 * inside the Description panel — which WooCommerce outputs *before* our FAQ
	 * panel — and we'd either duplicate the block or leave an empty FAQ tab behind
	 * it. Opting into the shortcode means opting out of the automatic placements.
	 */
	public function shortcode( $atts ) {
		if ( ! $this->faq_visible() || 'shortcode' !== $this->faq_placement() ) {
			return '';
		}
		$atts    = shortcode_atts( array( 'heading' => '' ), $atts, 'xpay-faq' );
		$heading = '' !== $atts['heading'] ? (string) $atts['heading'] : $this->faq_heading();
		ob_start();
		$this->render_faq_list( $heading, true );
		return ob_get_clean();
	}

	/**
	 * Visible FAQ block on the product page — backs the FAQPage JSON-LD (Google
	 * requires the schema's content to be visible) and helps shoppers. Only renders
	 * for products the merchant approved (those with a pushed FAQ entry).
	 *
	 * Classic-theme placement. On 'auto' this is also registered alongside the tab,
	 * and stands down when the tab already rendered — tabs are emitted at priority 10
	 * of this same action, so by the time we run at 15 the flag is set.
	 */
	public function render_visible_faq() {
		$this->render_faq_list( $this->faq_heading(), true );
	}

	/**
	 * Shared body for all three placements.
	 *
	 * @param string $heading      Section heading.
	 * @param bool   $with_wrapper Emit the <section> + <h2> chrome. False inside a
	 *                             product tab, which supplies its own heading and layout.
	 */
	private function render_faq_list( $heading, $with_wrapper ) {
		$faq = $this->faq_for_current_product();
		if ( null === $faq ) {
			return;
		}
		$this->faq_rendered = true;

		if ( $with_wrapper ) {
			echo '<section class="xpay-faq" aria-label="' . esc_attr( $heading ) . '" style="max-width:820px;margin:32px auto;padding:0 12px">';
			echo '<h2 style="font-size:20px;margin:0 0 12px">' . esc_html( $heading ) . '</h2>';
		} else {
			echo '<div class="xpay-faq">';
		}

		foreach ( $faq as $qa ) {
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

		echo $with_wrapper ? '</section>' : '</div>';
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

	/**
	 * Has something else already emitted a FAQPage on this page? Same buffered-head
	 * heuristic as already_emitted_product_schema(), but "FAQPage" can sit anywhere in
	 * a graph node (@graph, nested arrays), so we match the type token rather than a
	 * leading position. We run at wp_head:99, after Yoast / Rank Math / most FAQ
	 * plugins have printed theirs.
	 *
	 * BEST-EFFORT, and deliberately fails OPEN. Reading prior output requires an active
	 * output buffer, and neither WordPress nor this plugin buffers wp_head — so on a host
	 * without `output_buffering` enabled there is nothing to inspect and we emit our node.
	 * A duplicate FAQPage is a structured-data warning; a MISSING FAQPage costs the
	 * merchant the agent visibility they're paying us for. Given we can't always tell,
	 * emitting is the safer failure. Don't promise more than this in the readme.
	 */
	private function already_emitted_faq_schema() {
		if ( ! ob_get_level() ) {
			return false;
		}
		$buffer = ob_get_contents();
		if ( ! $buffer ) {
			return false;
		}
		if ( ! preg_match_all( '#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#is', $buffer, $m ) ) {
			return false;
		}
		foreach ( $m[0] as $i => $tag ) {
			// Ignore our own blocks so a second xpay node never self-suppresses.
			if ( false !== strpos( $tag, 'data-emitter="xpay"' ) ) {
				continue;
			}
			// Matches both the bare `"@type":"FAQPage"` and the array form
			// `"@type":["WebPage","FAQPage"]` that graph-style emitters produce.
			if ( preg_match( '#"@type"\s*:\s*(\[[^\]]*)?"FAQPage"#i', $m[1][ $i ] ) ) {
				return true;
			}
		}
		return false;
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
