<?php
/**
 * Discovery surface for AI shopping agents.
 *
 * Implements an extensible emitter registry so each commerce standard (real
 * today, watchlist tomorrow) plugs in without touching the rest of the plugin.
 * The default config emits only standards that are *real and adopted*:
 *
 *   - GET /llms.txt                                 (llmstxt.org)
 *   - GET /.well-known/ucp                          (UCP business profile,
 *                                                    spec 2026-04-08 — Google
 *                                                    + Shopify + Etsy + Wayfair
 *                                                    + Target + Walmart fetch
 *                                                    this for capability
 *                                                    negotiation; default-on
 *                                                    once merchant is connected)
 *   - GET /.well-known/oauth-protected-resource     (RFC 9728, when UCP OAuth
 *                                                    identity linking is on)
 *   - GET /.well-known/agent-card.json              (A2A 1.0, IANA-registered
 *                                                    2025-08-01 — watchlist,
 *                                                    off by default)
 *
 * Schema.org JSON-LD is emitted by Xpay_Schema; the robots.txt allowlist is
 * emitted by Xpay_Robots. Together these three classes form the discovery
 * surface this plugin exposes on the merchant's own domain. Per-protocol
 * endpoints (ACP `POST /checkout_sessions`, UCP REST, AP2 mandates) live on
 * xpay's hosted infrastructure; the plugin advertises them in /llms.txt.
 *
 * Adding a new emitter: register it in self::$emitters and implement the
 * generator method. Rewrite rules + the literal-URL fallback pick it up
 * automatically.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_REST {

	private static $instance = null;
	private const QUERY_VAR  = 'xpay_route';

	/**
	 * Discovery emitter registry. Each entry:
	 *   route          — query-var value
	 *   path           — literal URL path (used for rewrites + REQUEST_URI fallback)
	 *   content_type   — response Content-Type header
	 *   generator      — method name producing the response body
	 *   default_on     — whether the emitter is enabled in stock config
	 *   option_flag    — wp_option key that overrides default_on (optional)
	 */
	private static function emitters() {
		return array(
			'llms'                     => array(
				'route'        => 'llms',
				'path'         => '/llms.txt',
				'content_type' => 'text/plain; charset=utf-8',
				'generator'    => 'serve_llms_txt',
				'default_on'   => true,
			),
			'ucp_profile'              => array(
				'route'        => 'ucp_profile',
				'path'         => '/.well-known/ucp',
				'content_type' => 'application/json; charset=utf-8',
				'generator'    => 'serve_ucp_profile',
				'default_on'   => true,
				'option_flag'  => 'xpay_wc_emit_ucp_profile',
			),
			'oauth_protected_resource' => array(
				'route'        => 'oauth_protected_resource',
				'path'         => '/.well-known/oauth-protected-resource',
				'content_type' => 'application/json; charset=utf-8',
				'generator'    => 'serve_oauth_protected_resource',
				'default_on'   => false,
				'option_flag'  => 'xpay_wc_emit_oauth_protected_resource',
			),
			'agent_card'               => array(
				'route'        => 'agent_card',
				'path'         => '/.well-known/agent-card.json',
				'content_type' => 'application/json; charset=utf-8',
				'generator'    => 'serve_agent_card',
				'default_on'   => false,
				'option_flag'  => 'xpay_wc_emit_agent_card',
			),
		);
	}

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
		foreach ( self::emitters() as $key => $em ) {
			if ( ! $this->is_enabled( $key, $em ) ) {
				continue;
			}
			$pattern = '^' . ltrim( preg_quote( $em['path'], '#' ), '/' ) . '$';
			$pattern = str_replace( '\\.', '\\.', $pattern ); // explicit
			add_rewrite_rule( $pattern, 'index.php?' . self::QUERY_VAR . '=' . $em['route'], 'top' );
		}

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
		$route    = get_query_var( self::QUERY_VAR );
		$emitters = self::emitters();

		// Query-arg fallback for hosts that intercept /.well-known/ at the
		// web-server layer (some shared hosts + ACME-handling environments).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! $route && isset( $_GET['xpay_route'] ) ) {
			$candidate = sanitize_key( wp_unslash( $_GET['xpay_route'] ) );
			if ( isset( $emitters[ $candidate ] ) ) {
				$route = $candidate;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $route ) {
			// Literal-URL fallback: REQUEST_URI match for hosts that don't
			// honour our rewrite rules (Plain permalinks, subdir installs).
			$path = isset( $_SERVER['REQUEST_URI'] ) ? strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' ) : '';
			foreach ( $emitters as $key => $em ) {
				if ( $em['path'] === $path ) {
					$route = $key;
					break;
				}
			}
		}

		if ( ! $route || ! isset( $emitters[ $route ] ) ) {
			return;
		}
		$em = $emitters[ $route ];
		if ( ! $this->is_enabled( $route, $em ) ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: ' . $em['content_type'] );
		header( 'X-Robots-Tag: noindex' );
		$this->{ $em['generator'] }();
		exit;
	}

	private function is_enabled( $key, $em ) {
		if ( ! empty( $em['option_flag'] ) ) {
			$opt = get_option( $em['option_flag'], null );
			if ( null !== $opt ) {
				return (bool) $opt;
			}
		}
		return ! empty( $em['default_on'] );
	}

	/**
	 * /llms.txt — llmstxt.org Markdown convention. Lists the public catalog
	 * feed and per-protocol surfaces (ACP / UCP / AP2) hosted by xpay.
	 */
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

		if ( $slug ) {
			$lines[] = sprintf( '- [Agent-readable catalog (JSON)](https://agent-feed.xpay.sh/catalog/%s.json)', $slug );

			// Only advertise protocol endpoints the backend has confirmed are
			// live. Backend pushes the list during the Connect flow via the
			// `xpay_wc_protocol_endpoints` option. Each entry maps a protocol
			// id to its public URL. Unset / empty => the merchant only gets
			// the catalog feed + cart deeplink advertised here, which avoids
			// 501 / 404 follow-ups for agents that try to use the protocol.
			$endpoints = $this->live_protocol_endpoints( $slug );
			if ( ! empty( $endpoints ) ) {
				$lines[] = '';
				$lines[] = '## Commerce protocols';
				$lines[] = '';
				$labels = array(
					'acp' => 'ACP — Agentic Commerce Protocol',
					'ucp' => 'UCP — Universal Commerce Protocol',
					'ap2' => 'AP2 — Agent Payments Protocol',
					'mcp' => 'MCP — Model Context Protocol server',
				);
				foreach ( $endpoints as $proto => $url ) {
					$label = $labels[ $proto ] ?? strtoupper( $proto );
					$lines[] = sprintf( '- [%s](%s)', $label, $url );
				}
			}

			$lines[] = '';
			$lines[] = '## Cart handoff';
			$lines[] = '';
			$lines[] = sprintf( '- Cart deeplink: `%s?xpay_cart={token}` — pre-fills the merchant cart and lands the buyer on the existing checkout.', $site_url );
		}

		$lines[] = '';
		$lines[] = '## Top categories';
		foreach ( $this->top_categories() as $cat ) {
			$lines[] = sprintf( '- [%s](%s)', $cat['name'], $cat['url'] );
		}

		$lines[] = '';
		$lines[] = '## For AI shopping agents';
		$lines[] = '';
		$lines[] = 'This store accepts agent-initiated purchases via the open commerce protocols above. Live product data is exposed as schema.org JSON-LD on every product page; robots.txt explicitly allows GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, Google-Extended and related AI user-agents.';

		echo esc_html( implode( "\n", $lines ) ) . "\n";
	}

	/**
	 * /.well-known/ucp — UCP business profile (Google's Universal Commerce
	 * Protocol). Documented at https://developers.google.com/merchant/ucp/guides/ucp-profile
	 * and https://ucp.dev/latest/specification/overview/. Spec rev 2026-04-08.
	 *
	 * Google + Shopify + Etsy + Wayfair + Target + Walmart fetch this file for
	 * capability negotiation. The profile must be publicly accessible and
	 * unauthenticated — the spec is explicit about this.
	 *
	 * The plugin generates a sensible default profile pointing at xpay-hosted
	 * UCP service endpoints. Merchants on xpay's commercial tier can override
	 * the entire body via the `xpay_wc_ucp_profile` option (populated during
	 * Connect from the merchant's per-store config in the xpay backend) — that
	 * is where signing keys, custom payment handlers, and capability extensions
	 * are injected.
	 */
	private function serve_ucp_profile() {
		$override = get_option( 'xpay_wc_ucp_profile' );
		if ( is_string( $override ) && '' !== trim( $override ) ) {
			$decoded = json_decode( $override, true );
			if ( is_array( $decoded ) ) {
				echo wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				return;
			}
		}

		$slug         = Xpay_Plugin::merchant_slug();
		$spec_version = '2026-04-08';
		$service_base = $slug
			? sprintf( 'https://agent-commerce.xpay.sh/ucp/v1/%s', $slug )
			: home_url( '/wp-json/xpay/ucp/v1' );

		$ucp = array(
			'version'      => $spec_version,
			'services'     => array(
				'dev.ucp.shopping' => array(
					array(
						'version'   => $spec_version,
						'spec'      => 'https://ucp.dev/specification/overview',
						'transport' => 'rest',
						'endpoint'  => $service_base,
						'schema'    => 'https://ucp.dev/' . $spec_version . '/services/shopping/rest.openapi.json',
					),
				),
			),
			'capabilities' => array(
				'dev.ucp.shopping.checkout'    => array(
					array(
						'version' => $spec_version,
						'spec'    => 'https://ucp.dev/specification/checkout',
						'schema'  => 'https://ucp.dev/' . $spec_version . '/schemas/shopping/checkout.json',
					),
				),
				'dev.ucp.shopping.fulfillment' => array(
					array(
						'version' => $spec_version,
						'spec'    => 'https://ucp.dev/specification/fulfillment',
						'schema'  => 'https://ucp.dev/' . $spec_version . '/schemas/shopping/fulfillment.json',
						'extends' => 'dev.ucp.shopping.checkout',
					),
				),
				'dev.ucp.shopping.discount'    => array(
					array(
						'version' => $spec_version,
						'spec'    => 'https://ucp.dev/specification/discount',
						'schema'  => 'https://ucp.dev/' . $spec_version . '/schemas/shopping/discount.json',
						'extends' => 'dev.ucp.shopping.checkout',
					),
				),
				'dev.ucp.shopping.order'       => array(
					array(
						'version' => $spec_version,
						'spec'    => 'https://ucp.dev/latest/specification/order',
						'schema'  => 'https://ucp.dev/' . $spec_version . '/schemas/shopping/order.json',
					),
				),
			),
		);

		$signing_keys_opt = get_option( 'xpay_wc_ucp_signing_keys' );
		$signing_keys     = is_array( $signing_keys_opt ) ? $signing_keys_opt : array();

		$payload = array(
			'ucp'          => $ucp,
			'signing_keys' => $signing_keys,
		);

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * /.well-known/oauth-protected-resource — RFC 9728 metadata. Emitted only
	 * when UCP OAuth Identity Linking is enabled for this merchant.
	 */
	private function serve_oauth_protected_resource() {
		$slug    = Xpay_Plugin::merchant_slug();
		$payload = array(
			'resource'              => home_url( '/' ),
			'authorization_servers' => array( 'https://auth.xpay.sh' ),
			'scopes_supported'      => array( 'catalog.read', 'cart.write', 'order.read' ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_documentation' => 'https://docs.xpay.sh/merchants/woocommerce/',
			'resource_signing_alg_values_supported' => array( 'ES256', 'RS256' ),
		);
		if ( $slug ) {
			$payload['resource_name'] = $slug;
		}
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * /.well-known/agent-card.json — A2A 1.0 agent-card metadata. IANA
	 * registered 2025-08-01. Watchlist emitter: off by default, opt-in via
	 * the `xpay_wc_emit_agent_card` option once A2A adoption matures.
	 */
	private function serve_agent_card() {
		$slug    = Xpay_Plugin::merchant_slug();
		$payload = array(
			'name'         => get_bloginfo( 'name' ),
			'description'  => get_bloginfo( 'description' ),
			'url'          => home_url( '/' ),
			'version'      => XPAY_WC_VERSION,
			'capabilities' => array(
				'shopping'    => true,
				'cart'        => true,
				'inventory'   => true,
			),
			'skills'       => array(
				array(
					'id'          => 'browse_catalog',
					'name'        => 'Browse catalog',
					'description' => 'List and search products in the merchant catalog.',
				),
				array(
					'id'          => 'create_cart',
					'name'        => 'Create cart',
					'description' => 'Build a cart and obtain a signed checkout deeplink.',
				),
			),
		);
		if ( $slug ) {
			$payload['provider'] = array(
				'name' => 'xpay',
				'url'  => sprintf( 'https://agent-commerce.xpay.sh/v1/%s', $slug ),
			);
		}
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Resolve the protocols the xpay backend has confirmed live for this
	 * merchant. Returns an ordered map of `protocol_id => endpoint_url`.
	 *
	 * Two sources, in order:
	 *  1. `xpay_wc_protocol_endpoints` wp_option — backend-pushed during
	 *     Connect. May be a JSON string or a PHP array. Each value is a
	 *     fully-qualified URL the agent can hit. Unknown protocol ids are
	 *     preserved so future protocols don't need plugin updates.
	 *  2. Filter `xpay_wc_protocol_endpoints` — for power users overriding
	 *     in code (mu-plugin etc.).
	 *
	 * If neither yields anything, returns an empty array — `/llms.txt` will
	 * advertise only the catalog feed + cart deeplink, both of which are
	 * actually live today on agent-feed.xpay.sh + the merchant's own domain.
	 */
	private function live_protocol_endpoints( $slug ) {
		$out = array();
		$raw = get_option( 'xpay_wc_protocol_endpoints' );
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}
		if ( is_array( $raw ) ) {
			foreach ( $raw as $k => $v ) {
				if ( is_string( $k ) && is_string( $v ) && '' !== trim( $v ) ) {
					$out[ strtolower( $k ) ] = $v;
				}
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'xpay_wc_protocol_endpoints', $out, $slug );
			if ( is_array( $filtered ) ) {
				$out = $filtered;
			}
		}
		return $out;
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
