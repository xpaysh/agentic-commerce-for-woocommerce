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

					case 'clear_ucp_profile_cache':
						// xpay_wc_ucp_profile holds the backend-pushed UCP body.
						// Deleting it forces Xpay_REST::serve_ucp_profile() to fall
						// through to the in-plugin template until the next finalize
						// or backend-pushed update lands.
						delete_option( 'xpay_wc_ucp_profile' );
						delete_option( 'xpay_wc_ucp_signing_keys' );
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
}
