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
						$executed[] = $action;
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
						// JSON-LD + a visible FAQ section for these products only.
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
						$executed[] = $action;
						break;

					case 'clear_product_faqs':
						delete_option( 'xpay_wc_product_faqs' );
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

		return rest_ensure_response(
			array(
				'ok'             => true,
				'plugin_version' => XPAY_WC_VERSION,
				'executed'       => $executed,
				'skipped'        => $skipped,
				'errors'         => $errors,
			)
		);
	}

	const LLMS_TXT_MAX_SECTIONS    = 20;
	const LLMS_TXT_MAX_HEADING_LEN = 200;
	const LLMS_TXT_MAX_BODY_LEN    = 4096;

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
