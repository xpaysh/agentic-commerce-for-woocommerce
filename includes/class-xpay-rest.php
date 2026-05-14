<?php
/**
 * Serves the public discovery files AI shoppers look for:
 *   - GET /llms.txt
 *   - GET /.well-known/agentic-commerce.json
 *
 * Uses a query-var rewrite so the routes work regardless of the active theme,
 * permalink structure (including "Plain"), or whether WP is in a subdirectory.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_REST {

	private static $instance = null;
	private const QUERY_VAR  = 'xpay_route';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 0 );
	}

	public function register_rewrite() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=llms', 'top' );
		add_rewrite_rule( '^\.well-known/agentic-commerce\.json$', 'index.php?' . self::QUERY_VAR . '=acp', 'top' );

		if ( get_option( 'xpay_wc_flush_rewrites' ) ) {
			flush_rewrite_rules( false );
			delete_option( 'xpay_wc_flush_rewrites' );
		}
	}

	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public function maybe_serve() {
		$route = get_query_var( self::QUERY_VAR );
		if ( ! $route ) {
			// Plain-permalinks fallback: also match against REQUEST_URI directly so
			// /llms.txt and /.well-known/agentic-commerce.json work without rewrites.
			$path = isset( $_SERVER['REQUEST_URI'] ) ? strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' ) : '';
			if ( '/llms.txt' === $path ) {
				$route = 'llms';
			} elseif ( '/.well-known/agentic-commerce.json' === $path ) {
				$route = 'acp';
			}
		}

		switch ( $route ) {
			case 'llms':
				$this->serve_llms_txt();
				exit;
			case 'acp':
				$this->serve_acp_json();
				exit;
		}
	}

	private function serve_llms_txt() {
		$site_name = get_bloginfo( 'name' );
		$site_desc = get_bloginfo( 'description' );
		$site_url  = home_url( '/' );
		$slug      = Xpay_Plugin::merchant_slug();

		$lines   = array();
		$lines[] = '# ' . $site_name;
		if ( $site_desc ) {
			$lines[] = '';
			$lines[] = '> ' . $site_desc;
		}
		$lines[] = '';
		$lines[] = '## Store';
		$lines[] = '';
		$lines[] = sprintf( '- [Shop home](%sshop/)', $site_url );
		$lines[] = sprintf( '- [Products sitemap](%ssitemap_index.xml)', $site_url );
		$lines[] = sprintf( '- [Agent commerce discovery](%s.well-known/agentic-commerce.json)', $site_url );

		if ( $slug ) {
			$lines[] = sprintf( '- [Agent-readable catalog](https://agent-feed.xpay.sh/catalog/%s.json)', $slug );
		}

		$lines[] = '';
		$lines[] = '## Top categories';
		foreach ( $this->top_categories() as $cat ) {
			$lines[] = sprintf( '- [%s](%s)', $cat['name'], $cat['url'] );
		}

		$lines[] = '';
		$lines[] = '## For AI shopping agents';
		$lines[] = '';
		$lines[] = 'This store accepts agent-initiated purchases. Cart deep-links land at the site checkout pre-filled.';
		$lines[] = 'See `/.well-known/agentic-commerce.json` for the structured endpoint.';

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( implode( "\n", $lines ) ) . "\n";
	}

	private function serve_acp_json() {
		$slug    = Xpay_Plugin::merchant_slug();
		$payload = array(
			'version'      => '1.0',
			'merchant'     => array(
				'name' => get_bloginfo( 'name' ),
				'url'  => home_url( '/' ),
				'slug' => $slug ? $slug : null,
			),
			'platform'     => array(
				'kind'    => 'woocommerce',
				'plugin'  => 'xpay-for-woocommerce',
				'version' => XPAY_WC_VERSION,
			),
			'catalog'      => array(
				'feed_url' => $slug ? sprintf( 'https://agent-feed.xpay.sh/catalog/%s.json', $slug ) : null,
				'shape'    => 'acp-v1',
			),
			'endpoints'    => $slug ? array(
				'product_list' => trailingslashit( XPAY_WC_AGENT_COMMERCE_BASE ) . 'v1/' . $slug . '/products',
				'product_get'  => trailingslashit( XPAY_WC_AGENT_COMMERCE_BASE ) . 'v1/' . $slug . '/products/{sku}',
				'cart_create'  => trailingslashit( XPAY_WC_AGENT_COMMERCE_BASE ) . 'v1/' . $slug . '/cart',
			) : null,
			'checkout'     => array(
				'deeplink_template' => home_url( '/?xpay_cart={token}' ),
				'gateway'           => 'merchant_managed',
			),
			'capabilities' => array(
				'cart_deeplink'  => true,
				'agent_checkout' => (bool) $slug,
				'live_inventory' => true,
				'live_pricing'   => true,
			),
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	private function top_categories() {
		$out = array();
		if ( ! function_exists( 'get_terms' ) ) {
			return $out;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 10,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $t ) {
			$out[] = array(
				'name' => $t->name,
				'url'  => get_term_link( $t ),
			);
		}
		return $out;
	}
}
