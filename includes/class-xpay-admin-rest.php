<?php
/**
 * Backend-callable refresh endpoint. Lets xpay's backend trigger
 * site-local maintenance on a single merchant without waiting for the
 * next plugin update or daily WP-Cron tick.
 *
 * The endpoint is dormant until the backend stores the site_token
 * issued during finalize. We never publish the token in URL parameters
 * (logs, referer, browser history); the finalize REST response is the
 * single, server-to-server channel where the backend learns it.
 *
 * Action vocabulary is intentionally forward-compatible: unknown action
 * names are silently skipped (recorded in the response under `skipped`)
 * so the backend can issue commands that newer plugin versions will
 * recognise without us having to gate behind plugin upgrades.
 *
 * Hard guarantees:
 *   - Constant-time token comparison (hash_equals).
 *   - No action performs an outbound HTTP request that could be coerced
 *     into SSRF — every action is local-state-only (transient flush,
 *     rewrite-rule flush, probe cache rebuild).
 *   - Endpoint returns 401 without leaking whether the site_token is
 *     unset, empty, or wrong.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Admin_REST {

	const ROUTE_NAMESPACE     = 'xpay/v1';
	const ROUTE_PATH          = '/admin/refresh';
	const SITE_TOKEN_HEADER   = 'x-xpay-site-token'; // get_header() lowercases
	const SITE_TOKEN_OPTION   = 'xpay_wc_site_token';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_refresh' ),
				'permission_callback' => array( $this, 'check_site_token' ),
			)
		);
	}

	public function check_site_token( WP_REST_Request $req ) {
		$expected = (string) get_option( self::SITE_TOKEN_OPTION, '' );
		if ( '' === $expected ) {
			return false;
		}
		$provided = (string) $req->get_header( self::SITE_TOKEN_HEADER );
		if ( '' === $provided ) {
			return false;
		}
		return hash_equals( $expected, $provided );
	}

	public function handle_refresh( WP_REST_Request $req ) {
		$body    = $req->get_json_params();
		$actions = is_array( $body ) && isset( $body['actions'] ) && is_array( $body['actions'] ) ? $body['actions'] : array();
		$params  = is_array( $body ) && isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();

		$executed = array();
		$skipped  = array();
		$errors   = array();

		foreach ( $actions as $action ) {
			if ( ! is_string( $action ) || '' === $action ) {
				continue;
			}
			try {
				switch ( $action ) {

					case 'emitter_probe_refresh':
						if ( class_exists( 'Xpay_Emitter_Probe' ) ) {
							Xpay_Emitter_Probe::cron_refresh_all();
							$executed[] = $action;
						} else {
							$skipped[] = $action;
						}
						break;

					case 'flush_rewrites':
						flush_rewrite_rules( false );
						$executed[] = $action;
						break;

					case 'reconcile_shop_assist_page':
						// Publish (or unpublish) the full-page shopper to match the
						// dashboard's shopAssistEnabled/Slug.
						//
						// The page is otherwise only reconciled on wp-admin loads and an
						// HOURLY cron, and reconcile() refuses to publish while the store
						// isn't entitled. So a store that enables the full page BEFORE
						// entitlement resolves gets a 404 on its own link, and no way for
						// us to retry. This makes the fix pushable.
						//
						// Bust the config transient first, or reconcile would read the
						// stale (pre-flip) shopAssistEnabled and no-op.
						if ( class_exists( 'Xpay_Shop_Assist_Page' ) ) {
							delete_transient( 'xpay_wc_storefront_entitlement' );
							delete_transient( 'xpay_wc_widget_config' );
							Xpay_Shop_Assist_Page::instance()->reconcile();
							// The page is new, so the cached 404 for its URL has to go.
							self::purge_site_page_cache();
							$executed[] = $action;
						} else {
							$skipped[] = $action;
						}
						break;

					case 'clear_discovery_cache':
						if ( class_exists( 'Xpay_Emitter_Probe' ) ) {
							foreach ( Xpay_Emitter_Probe::known_paths() as $path ) {
								delete_transient( Xpay_Emitter_Probe::TRANSIENT_PREFIX . md5( $path ) );
							}
							$executed[] = $action;
						} else {
							$skipped[] = $action;
						}
						break;

					case 'clear_storefront_widget_cache':
						// Flush the two transients that gate the storefront widget so
						// a backend-side flip (merchant consent toggle, entitlement
						// change, appearance edit) propagates within seconds instead
						// of waiting on the 6h/1h TTLs. The next storefront pageview
						// re-fetches both from the backend with the latest values.
						delete_transient( 'xpay_wc_storefront_entitlement' );
						delete_transient( 'xpay_wc_widget_config' );
						// …and drop the full-page cache. Clearing the transients alone
						// only fixes pages PHP actually renders — on WP Rocket /
						// LiteSpeed the cached HTML is served before PHP runs, so the
						// loader would stay missing until each page expired on its own.
						// The loader sits on every public page, so the purge is site-wide.
						self::purge_site_page_cache();
						$executed[] = $action;
						break;

					case 'diagnose_order_events':
						// Self-test the order-attribution pipeline. Reports plugin-
						// side state (connection, option, hook registrations, latest
						// order meta) and optionally replays the last completed
						// order's on_event() so we can observe the outbound POST
						// result without waiting on a new shopper.
						//
						// Params:
						//   replay      bool  — also fire on_event for the latest
						//                       completed order (clears the
						//                       _xpay_order_event_sent_at meta first
						//                       so the dedupe doesn't short-circuit).
						$report = array(
							'is_connected'                => class_exists( 'Xpay_Plugin' ) && Xpay_Plugin::is_connected(),
							'merchant_slug'               => function_exists( 'get_option' ) ? get_option( 'xpay_wc_merchant_slug', '' ) : '',
							'api_key_length'              => strlen( (string) get_option( 'xpay_wc_api_key', '' ) ),
							'order_events_enabled_option' => (bool) get_option( 'xpay_wc_order_events_enabled', 1 ),
							'hooks'                       => array(
								'woocommerce_payment_complete'      => has_action( 'woocommerce_payment_complete' ),
								'woocommerce_order_status_completed' => has_action( 'woocommerce_order_status_completed' ),
								'woocommerce_order_status_changed'  => has_action( 'woocommerce_order_status_changed' ),
							),
						);

						$latest = wc_get_orders( array(
							'limit'   => 1,
							'orderby' => 'date',
							'order'   => 'DESC',
							'status'  => array( 'completed', 'processing' ),
							'return'  => 'objects',
						) );
						if ( ! empty( $latest ) && $latest[0] instanceof WC_Order ) {
							$latest_order = $latest[0];
							$report['latest_order'] = array(
								'id'         => $latest_order->get_id(),
								'status'     => $latest_order->get_status(),
								'total'      => (string) $latest_order->get_total(),
								'currency'   => $latest_order->get_currency(),
								'placed_at'  => $latest_order->get_date_created() ? $latest_order->get_date_created()->date( 'c' ) : null,
								'meta_sent_at' => $latest_order->get_meta( '_xpay_order_event_sent_at', true ) ?: null,
								'meta_source'  => $latest_order->get_meta( '_xpay_source', true ) ?: null,
								'meta_inbound' => $latest_order->get_meta( '_xpay_ref_inbound', true ) ?: null,
							);

							if ( ! empty( $params['replay'] ) && class_exists( 'Xpay_Order_Events' ) ) {
								// Clear the dedupe meta so on_event posts even though
								// we may have already attempted on a prior trigger.
								$latest_order->delete_meta_data( '_xpay_order_event_sent_at' );
								$latest_order->save();

								$t0 = microtime( true );
								Xpay_Order_Events::instance()->on_event( $latest_order->get_id() );
								$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

								// Reload to see if the sent-at meta got stamped (i.e. POST succeeded).
								$reloaded = wc_get_order( $latest_order->get_id() );
								$report['replay'] = array(
									'duration_ms'           => $ms,
									'sent_at_after_replay'  => $reloaded ? ( $reloaded->get_meta( '_xpay_order_event_sent_at', true ) ?: null ) : null,
									'success'               => $reloaded && $reloaded->get_meta( '_xpay_order_event_sent_at', true ) ? true : false,
								);
							}
						}

						$executed[] = $action;
						$report_holder = isset( $report_holder ) ? $report_holder : array();
						$report_holder['diagnose_order_events'] = $report;
						break;

					case 'clear_ucp_profile_cache':
						// xpay_wc_ucp_profile holds the backend-pushed UCP body.
						// Deleting it forces Xpay_REST::serve_ucp_profile() to fall
						// through to the in-plugin template until the next finalize
						// or backend-pushed update lands.
						delete_option( 'xpay_wc_ucp_profile' );
						delete_option( 'xpay_wc_ucp_signing_keys' );
						$executed[] = $action;
						break;

					case 'set_llms_txt_extra_sections':
						// Backend pushes per-merchant extra Markdown sections to
						// append at the END of /llms.txt (after our existing
						// Store / Commerce protocols / Cart handoff / Top categories
						// / For AI shopping agents blocks). Sanitized + capped so a
						// hostile or misconfigured backend can't blow up the file.
						$raw = isset( $params['llms_txt_extra_sections'] ) && is_array( $params['llms_txt_extra_sections'] )
							? $params['llms_txt_extra_sections']
							: array();
						$clean = self::sanitize_llms_txt_extra_sections( $raw );
						if ( empty( $clean ) ) {
							delete_option( 'xpay_wc_llms_txt_extra_sections' );
						} else {
							$encoded = wp_json_encode( $clean );
							if ( false !== $encoded ) {
								update_option( 'xpay_wc_llms_txt_extra_sections', $encoded );
							}
						}
						$executed[] = $action;
						break;

					case 'clear_llms_txt_extra_sections':
						delete_option( 'xpay_wc_llms_txt_extra_sections' );
						$executed[] = $action;
						break;

					case 'set_product_faqs':
						// Backend pushes the APPROVED per-product FAQ + return policy as
						// a JSON map keyed by SKU. The schema emitter renders FAQPage
						// JSON-LD for these products, and — only when faq_visible is on —
						// a shopper-facing FAQ block too.
						//
						// The three display params are optional and each is only written
						// when present, so a push that carries just `product_faqs` (every
						// caller before 0.6.0) leaves the display config untouched.
						$prior = self::product_faq_skus();
						$raw   = isset( $params['product_faqs'] ) && is_array( $params['product_faqs'] ) ? $params['product_faqs'] : array();
						$clean = self::sanitize_product_faqs( $raw );
						if ( empty( $clean ) ) {
							delete_option( 'xpay_wc_product_faqs' );
						} else {
							$encoded = wp_json_encode( $clean );
							if ( false !== $encoded ) {
								update_option( 'xpay_wc_product_faqs', $encoded );
							}
						}

						// Visible, shopper-facing block. Default OFF, server-owned: this
						// is the only writer. Rendering UI on a merchant's storefront is
						// their call, and it's made in the xpay dashboard where they
						// approve the FAQs — not inferred from the fact that data exists.
						if ( isset( $params['faq_visible'] ) ) {
							update_option( 'xpay_wc_faq_visible', self::truthy( $params['faq_visible'] ) ? 1 : 0 );
						}
						if ( isset( $params['faq_placement'] ) ) {
							$placement = sanitize_key( (string) $params['faq_placement'] );
							if ( in_array( $placement, array( 'auto', 'tab', 'hook', 'shortcode' ), true ) ) {
								update_option( 'xpay_wc_faq_placement', $placement );
							}
						}
						// Heading rides the payload because the backend knows the
						// merchant's locale — a French store shouldn't wait on a WP.org
						// release plus a plugin update to stop seeing an English heading.
						if ( isset( $params['faq_heading'] ) ) {
							$heading = sanitize_text_field( (string) $params['faq_heading'] );
							$heading = mb_substr( $heading, 0, self::FAQ_MAX_HEADING_LEN );
							if ( '' === $heading ) {
								delete_option( 'xpay_wc_faq_heading' );
							} else {
								update_option( 'xpay_wc_faq_heading', $heading );
							}
						}

						// Every product whose FAQ changed, plus every product that HAD one
						// and no longer does (its block must come down).
						self::purge_product_caches( array_unique( array_merge( $prior, array_keys( $clean ) ) ) );
						$executed[] = $action;
						break;

					case 'clear_product_faqs':
						$prior = self::product_faq_skus();
						delete_option( 'xpay_wc_product_faqs' );
						self::purge_product_caches( $prior );
						$executed[] = $action;
						break;

					default:
						$skipped[] = $action;
				}
			} catch ( \Throwable $e ) {
				$errors[] = array(
					'action'  => $action,
					'message' => $e->getMessage(),
				);
			}
		}

		$payload = array(
			'ok'             => true,
			'plugin_version' => XPAY_WC_VERSION,
			'executed'       => $executed,
			'skipped'        => $skipped,
			'errors'         => $errors,
		);
		if ( isset( $report_holder ) && is_array( $report_holder ) ) {
			$payload['reports'] = $report_holder;
		}
		return rest_ensure_response( $payload );
	}

	const LLMS_TXT_MAX_SECTIONS    = 20;
	const LLMS_TXT_MAX_HEADING_LEN = 200;
	const LLMS_TXT_MAX_BODY_LEN    = 4096;
	const FAQ_MAX_HEADING_LEN      = 120;

	/** Accepts JSON true/false as well as the 1/0/"true" a hand-rolled caller might send. */
	private static function truthy( $v ) {
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( is_numeric( $v ) ) {
			return (int) $v > 0;
		}
		return in_array( strtolower( trim( (string) $v ) ), array( 'true', 'yes', 'on', '1' ), true );
	}

	/** SKUs (or numeric ids) currently carrying a pushed FAQ entry. */
	private static function product_faq_skus() {
		$raw     = (string) get_option( 'xpay_wc_product_faqs', '' );
		$decoded = $raw ? json_decode( $raw, true ) : array();
		return is_array( $decoded ) ? array_keys( $decoded ) : array();
	}

	/**
	 * Purge the full-page cache for the products we just changed.
	 *
	 * Without this, a successful push is INVISIBLE. Full-page caches (LiteSpeed, WP
	 * Rocket, WP Super Cache, W3TC, Cloudflare APO) serve stored HTML *before* PHP
	 * runs, so our option write lands, the emitter renders the new FAQ, and the
	 * shopper still gets the old page. Observed on a LiteSpeed store: the push
	 * returned ok/executed, PHP rendered the new localized FAQ, and LiteSpeed kept
	 * serving the stale HTML on the plain URL — only a cache-busting query
	 * string revealed the update. "The plugin returned 200" never meant "the page
	 * changed", and that was true of every push we make, not just FAQs.
	 *
	 * Every integration is guarded: we call into a caching plugin only if it's here.
	 * Purging is best-effort by design — a cache we don't know about is a stale page,
	 * not a failed request, so nothing below is allowed to throw the push.
	 *
	 * @param array $keys SKUs and/or numeric product ids.
	 */
	private static function purge_product_caches( $keys ) {
		if ( empty( $keys ) || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return;
		}

		$ids = array();
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( '' === $key ) {
				continue;
			}
			$id = (int) wc_get_product_id_by_sku( $key );
			if ( ! $id && ctype_digit( $key ) ) {
				// The emitter falls back to the numeric id when there's no SKU match,
				// so the purge has to resolve keys the same way the renderer does.
				$id = (int) $key;
			}
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}
		if ( empty( $ids ) ) {
			return;
		}

		foreach ( array_keys( $ids ) as $id ) {
			// WordPress object cache. Core fires the `clean_post_cache` action from
			// inside here, which is also what Cloudflare APO and several caching
			// plugins hang their own purge off — so this does double duty.
			clean_post_cache( $id );

			// LiteSpeed. Both surfaces: the documented `litespeed_purge_post` action
			// on current builds, the legacy API class on pre-3.0 ones.
			if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_post' ) ) {
				LiteSpeed_Cache_API::purge_post( $id );
			}
			// `litespeed_purge_post` is LiteSpeed Cache's OWN hook — we are the caller,
			// not the owner. Prefixing it (xpay_litespeed_purge_post) would fire a hook
			// nothing listens to and silently break the purge, which is the entire point
			// of this method. PrefixAllGlobals can't tell "inventing a global hook" from
			// "calling a third-party plugin's documented API"; this is the latter.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party (LiteSpeed Cache) hook, intentionally invoked.
			do_action( 'litespeed_purge_post', $id );

			// WP Rocket — same plugin that was swallowing our order attribution.
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}

			// WP Super Cache.
			if ( function_exists( 'wp_cache_post_change' ) ) {
				wp_cache_post_change( $id );
			}

			// W3 Total Cache.
			if ( function_exists( 'w3tc_flush_post' ) ) {
				w3tc_flush_post( $id );
			}
		}
	}

	/**
	 * Purge the FULL-PAGE cache site-wide.
	 *
	 * {@see purge_product_caches()} is per-post, which is right for a FAQ push —
	 * it touches known products. The storefront widget is different: its loader is
	 * injected into EVERY public page, so flipping it on or off invalidates the
	 * whole site, not a product list.
	 *
	 * Without this, a store on WP Rocket / LiteSpeed turns the assistant on, sees
	 * nothing, and concludes it's broken — the cached HTML is served BEFORE PHP
	 * runs, so our loader is never injected until each page's cache happens to
	 * expire. Pages that regenerate after the toggle work; the rest keep serving
	 * a copy cached before it, with no loader in it.
	 *
	 * Best-effort by design, same as the per-post purge: a cache we don't know
	 * about is a stale page, not a failed request. Nothing here may throw.
	 */
	private static function purge_site_page_cache() {
		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// LiteSpeed. API class on current builds, the plugin's own hook on older
		// ones (see the note in purge_product_caches() on why it isn't prefixed).
		if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_all' ) ) {
			LiteSpeed_Cache_API::purge_all();
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party (LiteSpeed Cache) hook, intentionally invoked.
		do_action( 'litespeed_purge_all' );

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// WP Fastest Cache.
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache( true );
		}

		// Cache Enabler.
		if ( has_action( 'cache_enabler_clear_complete_cache' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party (Cache Enabler) hook, intentionally invoked.
			do_action( 'cache_enabler_clear_complete_cache' );
		}

		// SiteGround Optimizer.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
	}

	/**
	 * Normalise + bound backend-pushed sections so we never store unbounded
	 * input. Each section is `{heading: string, body: string}`. HTML is
	 * stripped from both fields; Markdown control characters are kept since
	 * the body is rendered verbatim into a Markdown file.
	 *
	 * Silently drops malformed entries — backend gets an executed=ok response
	 * either way (an empty array is an explicit "clear" semantically).
	 */
	private static function sanitize_llms_txt_extra_sections( $raw ) {
		$clean = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$heading = isset( $entry['heading'] ) && is_string( $entry['heading'] ) ? $entry['heading'] : '';
			$body    = isset( $entry['body'] ) && is_string( $entry['body'] ) ? $entry['body'] : '';
			$heading = wp_strip_all_tags( $heading );
			$body    = wp_strip_all_tags( $body );
			$heading = mb_substr( $heading, 0, self::LLMS_TXT_MAX_HEADING_LEN );
			$body    = mb_substr( $body, 0, self::LLMS_TXT_MAX_BODY_LEN );
			if ( '' === $heading && '' === $body ) {
				continue;
			}
			$clean[] = array(
				'heading' => $heading,
				'body'    => $body,
			);
			if ( count( $clean ) >= self::LLMS_TXT_MAX_SECTIONS ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * Sanitize the pushed per-product FAQ map: { "<sku>": { faq:[{q,a}], return_policy:{…} } }.
	 * Caps count + lengths so a hostile/misconfigured backend can't bloat the option.
	 *
	 * @param array $raw
	 * @return array
	 */
	private static function sanitize_product_faqs( $raw ) {
		$clean    = array();
		$max_skus = 5000;
		$count    = 0;
		foreach ( $raw as $sku => $entry ) {
			if ( $count >= $max_skus ) {
				break;
			}
			$key = sanitize_text_field( (string) $sku );
			if ( '' === $key || ! is_array( $entry ) ) {
				continue;
			}
			$out = array();

			if ( ! empty( $entry['faq'] ) && is_array( $entry['faq'] ) ) {
				$faqs = array();
				foreach ( array_slice( $entry['faq'], 0, 10 ) as $qa ) {
					if ( ! is_array( $qa ) ) {
						continue;
					}
					$q = isset( $qa['q'] ) ? sanitize_text_field( substr( (string) $qa['q'], 0, 300 ) ) : '';
					$a = isset( $qa['a'] ) ? sanitize_textarea_field( substr( (string) $qa['a'], 0, 1200 ) ) : '';
					if ( '' !== $q && '' !== $a ) {
						$faqs[] = array( 'q' => $q, 'a' => $a );
					}
				}
				if ( $faqs ) {
					$out['faq'] = $faqs;
				}
			}

			if ( ! empty( $entry['return_policy'] ) && is_array( $entry['return_policy'] ) ) {
				$rp  = $entry['return_policy'];
				$out['return_policy'] = array(
					'country'  => isset( $rp['country'] ) ? sanitize_text_field( substr( (string) $rp['country'], 0, 4 ) ) : '',
					'days'     => isset( $rp['days'] ) ? (int) $rp['days'] : 14,
					'category' => isset( $rp['category'] ) ? esc_url_raw( (string) $rp['category'] ) : '',
					'method'   => isset( $rp['method'] ) ? esc_url_raw( (string) $rp['method'] ) : '',
					'fees'     => isset( $rp['fees'] ) ? esc_url_raw( (string) $rp['fees'] ) : '',
					'url'      => isset( $rp['url'] ) ? esc_url_raw( (string) $rp['url'] ) : '',
				);
			}

			if ( $out ) {
				$clean[ $key ] = $out;
				$count++;
			}
		}
		return $clean;
	}

	/**
	 * Reader used by Xpay_REST::serve_llms_txt() to append the backend-pushed
	 * sections at the end of the file. Returns an array of `{heading, body}`
	 * or empty array if nothing has been pushed.
	 */
	public static function get_llms_txt_extra_sections() {
		$encoded = (string) get_option( 'xpay_wc_llms_txt_extra_sections', '' );
		if ( '' === $encoded ) {
			return array();
		}
		$decoded = json_decode( $encoded, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		// Re-sanitize on read in case the option was hand-edited (defence in
		// depth — same bounds as the writer).
		return self::sanitize_llms_txt_extra_sections( $decoded );
	}
}
