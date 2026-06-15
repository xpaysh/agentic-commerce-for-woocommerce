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
			'agents_md'                => array(
				'route'        => 'agents_md',
				'path'         => '/agents.md',
				'content_type' => 'text/markdown; charset=utf-8',
				'generator'    => 'serve_agents_md',
				'default_on'   => true,
				'option_flag'  => 'xpay_wc_emit_agents_md',
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
		// Probe short-circuit. When Xpay_Emitter_Probe self-fetches our
		// own discovery URLs to detect another plugin/theme serving the
		// same path, it sets the X-Xpay-Probe: 1 header. We must not
		// answer those requests — the whole point is to see what would
		// be served *without* us in the chain.
		if ( Xpay_Emitter_Probe::request_is_probe() ) {
			return;
		}

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
		if ( ! empty( $em['default_on'] ) ) {
			// Don't-clobber gate for JSON emitters. /llms.txt is
			// Markdown — we *append* to upstream content, handled in
			// serve_llms_txt(). For the /.well-known/*.json files
			// appending isn't structurally valid; the safer answer is
			// to skip serving entirely when an external emitter is
			// already in place. The Markdown path stays enabled here
			// and merges at serve time.
			if ( 'llms' !== $key ) {
				$probe = Xpay_Emitter_Probe::get_result( $em['path'] );
				if ( is_array( $probe ) && ! empty( $probe['has_external'] ) ) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * /llms.txt — llmstxt.org Markdown convention. Lists the public catalog
	 * feed and per-protocol surfaces (ACP / UCP / AP2) hosted by xpay.
	 */
	private function serve_llms_txt() {
		// llms.txt is plain-text Markdown for LLM crawlers. We must NOT
		// HTML-escape the body (that would mangle ampersands in URLs and
		// quotes in titles). Defence-in-depth: strip any HTML that crept
		// in via blog name / description / category names at the source
		// instead, then echo the joined lines as-is.
		$site_name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$site_desc = wp_strip_all_tags( (string) get_bloginfo( 'description' ) );
		$site_url  = esc_url_raw( home_url( '/' ) );
		$slug      = Xpay_Plugin::merchant_slug();

		// Append mode. If another plugin / theme / web-server file is
		// already serving /llms.txt, render their content first then add
		// our agent-shopping sections at the end. The merchant keeps
		// every curated link they wrote (Yoast SEO AI, RankMath AI,
		// AIOSEO, hand-rolled). We never overwrite, we never reorder
		// their content — we only contribute the commerce/discovery
		// sections nobody else writes today.
		$probe    = Xpay_Emitter_Probe::get_result( '/llms.txt' );
		$external = ( is_array( $probe ) && ! empty( $probe['has_external'] ) ) ? (string) $probe['body'] : '';

		$lines = array();

		if ( '' !== $external ) {
			// Preserve upstream verbatim. Trim only trailing whitespace so
			// the join with our additions doesn't introduce a stack of
			// blank lines, but keep the body otherwise byte-identical.
			$lines[] = rtrim( $external, "\r\n\t " );
			$lines[] = '';
			$lines[] = '<!-- xpay agentic-commerce-for-woocommerce: appended sections below -->';
			$lines[] = '';
		} else {
			$lines[] = '# ' . $site_name;
			if ( $site_desc ) {
				$lines[] = '';
				$lines[] = '> ' . $site_desc;
			}
			$lines[] = '';
		}

		$lines[] = '## Store';
		$lines[] = '';
		$lines[] = sprintf( '- [Shop home](%sshop/)', $site_url );
		$lines[] = sprintf( '- [Products sitemap](%ssitemap_index.xml)', $site_url );

		if ( $slug ) {
			// Served from the merchant's sidecar (off the shared agent-feed CDN).
			// Use the always-resolving wildcard host so the link is valid the moment
			// the store connects — no CNAME required. Once the merchant connects a
			// branded agents.<domain>, the wildcard 302-redirects there.
			$lines[] = sprintf( '- [Agent-readable catalog (JSON)](https://%s.agentic-commerce.xpay.sh/catalog.json)', $slug );

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
			$cat_name = wp_strip_all_tags( (string) $cat['name'] );
			$cat_url  = esc_url_raw( $cat['url'] );
			$lines[]  = sprintf( '- [%s](%s)', $cat_name, $cat_url );
		}

		$lines[] = '';
		$lines[] = '## For AI shopping agents';
		$lines[] = '';
		$lines[] = 'This store accepts agent-initiated purchases via the open commerce protocols above. Live product data is exposed as schema.org JSON-LD on every product page; robots.txt explicitly allows GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, Google-Extended and related AI user-agents.';

		// Backend-pushed extra sections (set via /admin/refresh with action
		// `set_llms_txt_extra_sections`). Rendered at the very end so they
		// don't disturb the merchant's own upstream content (prepended at
		// the top) or our discovery scaffolding (Store / Commerce protocols
		// / Cart handoff / Top categories / For AI shopping agents).
		if ( class_exists( 'Xpay_Admin_REST' ) ) {
			$extra = Xpay_Admin_REST::get_llms_txt_extra_sections();
			foreach ( $extra as $section ) {
				$lines[] = '';
				$lines[] = '## ' . $section['heading'];
				$lines[] = '';
				$lines[] = $section['body'];
			}
		}

		// Stable self-fingerprint (Xpay_Emitter_Probe::SELF_FINGERPRINT). In append
		// mode the marker already appears near the top; this trailing copy guarantees
		// it in fresh mode too, so a cached copy of our own llms.txt is never mistaken
		// for external upstream content and re-prepended.
		$lines[] = '';
		$lines[] = '<!-- xpay agentic-commerce-for-woocommerce -->';

		// Content-Type was set to text/plain by the caller. Inputs are stripped
		// of HTML at construction time (site name, descriptions, category names
		// via wp_strip_all_tags; URLs via esc_url_raw). esc_html() is wrong
		// here — it would entity-encode characters that belong literally in
		// Markdown/URLs.
		echo implode( "\n", $lines ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * /agents.md — a real connect-and-transact SKILL for skill-using AI shopping
	 * agents (OpenClaw-style runtimes, Claude/ChatGPT skills, etc.). This is NOT
	 * a mirror of /llms.txt: llms.txt is a generic discovery surface (links +
	 * prose for crawlers); agents.md is operational instructions an agent follows
	 * to browse this store's live catalog and build a cart.
	 *
	 * It advertises ONLY surfaces the xpay backend actually serves today:
	 *   - the MCP server (search_catalog / get_product / create_cart),
	 *   - the REST catalog API,
	 *   - the bulk catalog JSON,
	 *   - the cart deeplink hand-off (the human completes payment on the
	 *     merchant's own WooCommerce checkout — there is no in-protocol payment).
	 * Unconnected stores have no agent rails, so we serve a short honest stub.
	 */
	private function serve_agents_md() {
		$site_name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$site_url  = esc_url_raw( home_url( '/' ) );
		$slug      = Xpay_Plugin::merchant_slug();

		$lines = array();

		if ( ! $slug ) {
			// No slug => the store hasn't connected to xpay, so none of the agent
			// rails exist. Be honest rather than advertise dead endpoints.
			$lines[] = '# ' . ( $site_name ? $site_name : 'This store' ) . ' — agent skill';
			$lines[] = '';
			$lines[] = 'This store is not yet connected to an agent-commerce surface, so there is no';
			$lines[] = 'live catalog API or cart endpoint to call. Browse the store at ' . $site_url . '.';
			$lines[] = '';
			// Stable self-fingerprint (Xpay_Emitter_Probe::SELF_FINGERPRINT) so a cached
			// copy of our own output is never mistaken for an external /agents.md.
			$lines[] = '<!-- xpay agentic-commerce-for-woocommerce -->';
			echo implode( "\n", $lines ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		$mcp_endpoint  = sprintf( 'https://agent-commerce.xpay.sh/mcp/%s', $slug );
		$rest_base     = sprintf( 'https://agent-commerce.xpay.sh/v1/%s', $slug );
		$catalog_url   = sprintf( 'https://%s.agentic-commerce.xpay.sh/catalog.json', $slug );

		$lines[] = '# ' . $site_name . ' — agent shopping skill';
		$lines[] = '';
		$lines[] = 'A skill for AI shopping agents to browse this store\'s live catalog and build a';
		$lines[] = 'cart. Prices and stock are live. Checkout is a hand-off: you assemble the cart,';
		$lines[] = 'then send the shopper to the store\'s own checkout to pay — no payment happens';
		$lines[] = 'inside this protocol.';
		$lines[] = '';

		$lines[] = '## Connect (recommended: MCP)';
		$lines[] = '';
		$lines[] = sprintf( 'Model Context Protocol server (JSON-RPC 2.0, no auth required):' );
		$lines[] = '';
		$lines[] = '```';
		$lines[] = $mcp_endpoint;
		$lines[] = '```';
		$lines[] = '';
		$lines[] = 'Call `initialize`, then `tools/list`. Three tools are available:';
		$lines[] = '';
		$lines[] = '- `search_catalog` — keyword search over the live product catalog. Returns name, price, currency, availability, SKU, product URL, image.';
		$lines[] = '- `get_product` — look up one product by SKU or id for full detail before adding to cart.';
		$lines[] = '- `create_cart` — pass a list of `{ sku, quantity }`; receives back a single cart deeplink (below). Validates every SKU against live stock first.';
		$lines[] = '';

		$lines[] = '## Connect (alternative: REST)';
		$lines[] = '';
		$lines[] = 'If you cannot speak MCP, the same data is on a plain REST API:';
		$lines[] = '';
		$lines[] = sprintf( '- `GET %s/products` — list products (supports `?q=` search and `?category=`).', $rest_base );
		$lines[] = sprintf( '- `GET %s/products/{sku}` — one product by SKU.', $rest_base );
		$lines[] = sprintf( '- `POST %s/cart` — body `{ "items": [{ "sku": "...", "quantity": 1 }] }` → returns a cart deeplink.', $rest_base );
		$lines[] = '';
		$lines[] = sprintf( 'Bulk catalog snapshot (all products, refreshed continuously): %s', $catalog_url );
		$lines[] = '';

		$lines[] = '## Checkout (hand-off)';
		$lines[] = '';
		$lines[] = '`create_cart` / `POST /cart` return a deeplink of the form:';
		$lines[] = '';
		$lines[] = '```';
		$lines[] = $site_url . '?xpay_cart={token}';
		$lines[] = '```';
		$lines[] = '';
		$lines[] = 'Open it for the shopper. It pre-fills the store\'s native cart and lands them on';
		$lines[] = 'the store\'s existing checkout, where the human completes payment, shipping, and';
		$lines[] = 'any account steps. Do not attempt to pay programmatically — there is no payment';
		$lines[] = 'API, x402, or mandate flow here by design; the shopper finishes on the merchant\'s';
		$lines[] = 'own checkout.';
		$lines[] = '';

		$lines[] = '## Notes';
		$lines[] = '';
		$lines[] = '- Every product page also carries schema.org JSON-LD if you prefer to read pages directly.';
		$lines[] = '- Treat the deeplink token as opaque and single-cart; build a fresh cart per shopper.';
		$lines[] = '- Realtime shopping fetchers may be transparently routed to the structured catalog surface; the endpoints above are stable regardless.';
		$lines[] = '';
		// Stable self-fingerprint (Xpay_Emitter_Probe::SELF_FINGERPRINT) so a cached
		// copy of our own output is never mistaken for an external /agents.md.
		$lines[] = '<!-- xpay agentic-commerce-for-woocommerce -->';

		echo implode( "\n", $lines ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
			: rest_url( 'xpay/ucp/v1' );

		// MCP endpoint lives on agent-commerce.xpay.sh today. We will harmonize
		// onto the per-tenant `{slug}.mcp.xpay.sh/mcp` shape once the wildcard
		// routing layer learns how to dispatch commerce slugs separately from
		// publisher MCP servers — that's queued as M4 work.
		$mcp_endpoint = $slug
			? sprintf( 'https://agent-commerce.xpay.sh/mcp/%s', $slug )
			: rest_url( 'xpay/mcp' );

		// Canonical capability map — order matches Xpay_Settings::CAPABILITIES so
		// the toggle UI and the manifest stay in sync.
		$all_capabilities = array(
			'dev.ucp.shopping.checkout'    => array(
				'cap_key' => 'checkout',
				'entry'   => array(
					'version' => $spec_version,
					'spec'    => 'https://ucp.dev/' . $spec_version . '/specification/checkout',
					'schema'  => 'https://ucp.dev/' . $spec_version . '/schemas/shopping/checkout.json',
				),
			),
			'dev.ucp.shopping.fulfillment' => array(
				'cap_key' => 'fulfillment',
				'entry'   => array(
					'version' => $spec_version,
					'spec'    => 'https://ucp.dev/' . $spec_version . '/specification/fulfillment',
					'schema'  => 'https://ucp.dev/' . $spec_version . '/schemas/shopping/fulfillment.json',
					'extends' => array( 'dev.ucp.shopping.checkout' ),
				),
			),
			'dev.ucp.shopping.discount'    => array(
				'cap_key' => 'discount',
				'entry'   => array(
					'version' => $spec_version,
					'spec'    => 'https://ucp.dev/' . $spec_version . '/specification/discount',
					'schema'  => 'https://ucp.dev/' . $spec_version . '/schemas/shopping/discount.json',
					'extends' => array( 'dev.ucp.shopping.checkout' ),
				),
			),
			'dev.ucp.shopping.order'       => array(
				'cap_key' => 'order',
				'entry'   => array(
					'version' => $spec_version,
					'spec'    => 'https://ucp.dev/' . $spec_version . '/specification/order',
					'schema'  => 'https://ucp.dev/' . $spec_version . '/schemas/shopping/order.json',
				),
			),
		);

		$capabilities = array();
		foreach ( $all_capabilities as $cap_id => $cfg ) {
			if ( class_exists( 'Xpay_Settings' ) && ! Xpay_Settings::capability_enabled( $cfg['cap_key'] ) ) {
				continue;
			}
			$capabilities[ $cap_id ] = array( $cfg['entry'] );
		}

		$payment_handlers = class_exists( 'Xpay_Settings' ) ? Xpay_Settings::payment_handlers() : array();
		$links            = class_exists( 'Xpay_Settings' ) ? Xpay_Settings::ucp_links() : array();

		$ucp = array(
			'version'          => $spec_version,
			'services'         => array(
				'dev.ucp.shopping' => array(
					array(
						'version'   => $spec_version,
						'spec'      => 'https://ucp.dev/specification/overview',
						'transport' => 'rest',
						'endpoint'  => $service_base,
						'schema'    => 'https://ucp.dev/' . $spec_version . '/services/shopping/rest.openapi.json',
					),
					array(
						'version'   => $spec_version,
						'spec'      => 'https://ucp.dev/specification/overview',
						'transport' => 'mcp',
						'endpoint'  => $mcp_endpoint,
						'schema'    => 'https://ucp.dev/' . $spec_version . '/services/shopping/mcp.json',
					),
				),
			),
			'capabilities'     => $capabilities,
			'payment_handlers' => $payment_handlers,
			'links'            => $links,
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
