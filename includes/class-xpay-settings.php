<?php
/**
 * Settings → xpay admin page.
 *
 * Layout: when disconnected, a single "Connect store" panel. Once connected,
 * a tabbed UI with five tabs (general/capabilities/payments/links/tools)
 * accessible via the `?tab=` query arg. Each save action posts to
 * admin-post.php with a per-tab nonce.
 *
 * Tabs:
 *   - general:      status, slug, last sync, disconnect, telemetry opt-in
 *   - capabilities: per-UCP-capability on/off toggles (default ON)
 *   - payments:     map WC payment gateways to UCP payment_handlers[]
 *   - links:        auto-detect / override privacy, TOS, about, contact, shipping
 *   - tools:        view UCP profile, view audit, test connection,
 *                   refresh catalog, telemetry debug toggle
 *
 * The capability and payment-handler choices flow into the in-plugin UCP
 * manifest emitter (Xpay_REST::serve_ucp_profile) so toggles are reflected
 * in /.well-known/ucp.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Settings {

	private static $instance = null;
	const NONCE_OPTION       = 'xpay_wc_onboard_nonce';

	/**
	 * Canonical UCP shopping capability list. Mirrors the hardcoded map in
	 * Xpay_REST::serve_ucp_profile(). Order is the render order on the
	 * Capabilities tab.
	 */
	const CAPABILITIES = array( 'checkout', 'fulfillment', 'discount', 'order' );

	/**
	 * Page slugs auto-detected on the Links tab. Each entry is the UCP
	 * link `rel` paired with the page-slug candidates we probe in order.
	 */
	const LINK_TYPES = array(
		'privacy'  => array( 'privacy-policy', 'privacy', 'legal/privacy' ),
		'terms'    => array( 'terms-of-service', 'terms', 'tos', 'legal/terms' ),
		'about'    => array( 'about', 'about-us' ),
		'contact'  => array( 'contact', 'contact-us' ),
		'shipping' => array( 'shipping', 'shipping-policy', 'shipping-and-returns' ),
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function tab_url( $tab = 'general', $extra = array() ) {
		$args = array_merge(
			array(
				'page' => 'agentic-commerce-for-woocommerce',
				'tab'  => $tab,
			),
			$extra
		);
		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_capture_partner_from_query' ) );
		add_action( 'admin_post_xpay_wc_connect_start', array( $this, 'handle_connect_start' ) );
		add_action( 'admin_post_xpay_wc_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_xpay_wc_audit', array( $this, 'handle_audit' ) );
		add_action( 'admin_post_xpay_wc_telemetry', array( $this, 'handle_telemetry_toggle' ) );
		add_action( 'admin_post_xpay_wc_save_partner', array( $this, 'handle_save_partner' ) );
		add_action( 'admin_post_xpay_wc_save_capabilities', array( $this, 'handle_save_capabilities' ) );
		add_action( 'admin_post_xpay_wc_save_payments', array( $this, 'handle_save_payments' ) );
		add_action( 'admin_post_xpay_wc_save_links', array( $this, 'handle_save_links' ) );
		add_action( 'admin_post_xpay_wc_telemetry_debug', array( $this, 'handle_telemetry_debug_toggle' ) );
		add_action( 'admin_post_xpay_wc_refresh_catalog', array( $this, 'handle_refresh_catalog' ) );
		add_action( 'admin_post_xpay_wc_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_xpay_wc_run_diagnostics', array( $this, 'handle_run_diagnostics' ) );
		add_action( 'rest_api_init', array( $this, 'register_finalize_route' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( XPAY_WC_FILE ), array( $this, 'plugin_action_links' ) );
		add_action( 'xpay_wc_initial_resync', array( $this, 'cron_initial_resync' ), 10, 1 );
	}

	/**
	 * Cron handler — fires the initial catalog resync out-of-band so the REST
	 * finalize response stays under WordPress's external-request timeout.
	 */
	public function cron_initial_resync( $slug ) {
		if ( ! $slug || ! is_string( $slug ) ) {
			return;
		}
		Xpay_Client::post( '/v1/merchants/' . $slug . '/resync', array( 'reason' => 'initial_install' ) );
	}

	public function register_menu() {
		add_options_page(
			__( 'xpay', 'agentic-commerce-for-woocommerce' ),
			__( 'xpay', 'agentic-commerce-for-woocommerce' ),
			'manage_woocommerce',
			'agentic-commerce-for-woocommerce',
			array( $this, 'render_page' )
		);
	}

	public function plugin_action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::tab_url() ), esc_html__( 'Settings', 'agentic-commerce-for-woocommerce' ) ) );
		return $links;
	}

	public function register_finalize_route() {
		register_rest_route(
			'xpay/v1',
			'/finalize',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_finalize' ),
				// Authentication is performed inside rest_finalize() itself: the
				// payload-supplied nonce is compared via hash_equals() against the
				// single-use option set in handle_connect_start(), and the option
				// is deleted on first success. This is the OAuth-callback pattern
				// — no WP user is signed in when xpay calls back, so a capability
				// check is not possible here; the nonce IS the auth.
				'permission_callback' => '__return_true',
			)
		);
	}

	public function rest_finalize( WP_REST_Request $req ) {
		$payload = $req->get_json_params();
		$nonce   = isset( $payload['nonce'] ) ? sanitize_text_field( $payload['nonce'] ) : '';
		$slug    = isset( $payload['merchant_slug'] ) ? sanitize_title( $payload['merchant_slug'] ) : '';
		$api_key = isset( $payload['api_key'] ) ? sanitize_text_field( $payload['api_key'] ) : '';

		if ( ! $slug || ! $api_key ) {
			Xpay_Telemetry::track( 'finalize_error', array( 'reason' => 'missing_fields' ) );
			return new WP_REST_Response( array( 'error' => 'missing_fields' ), 400 );
		}

		$stored        = get_option( self::NONCE_OPTION );
		$existing_slug = get_option( 'xpay_wc_merchant_slug' );
		$existing_key  = get_option( 'xpay_wc_api_key' );

		// Idempotent replay path: the backend may re-send the same creds if its
		// first callback's HTTP response was lost (network/lambda timeout). If
		// the slug + api_key already match what we have stored, accept silently.
		$is_replay = $existing_slug && $existing_key && hash_equals( (string) $existing_slug, $slug ) && hash_equals( (string) $existing_key, $api_key );

		if ( ! $is_replay ) {
			// Concurrent-OAuth-callback race: WC's server-side wc-auth POST and
			// the browser-return path can both fire xpay's wcAuthCallback Lambda
			// within ~500ms of each other. Each invocation mints a fresh api_key
			// (pre-v0.3.2-backend behavior), so the second call arrives with a
			// different api_key + the same nonce. The first call already burned
			// the nonce; this second one fails is_replay (api_keys differ) and
			// fails nonce match (nonce gone). Without this guard it 401s and the
			// backend marks the merchant as plugin_callback_failed even though
			// the plugin actually finalized cleanly on call #1.
			//
			// If creds are already stored (any slug + any api_key), treat the
			// incoming request as a duplicate callback. Return 200 with the
			// EXISTING slug so the orchestration upstream stays consistent. We
			// never overwrite stored creds on this path — the first finalize
			// won, and that's the truth.
			if ( $existing_slug && $existing_key ) {
				Xpay_Telemetry::track( 'finalize_duplicate_callback', array( 'slug' => $existing_slug ) );
				return new WP_REST_Response(
					array(
						'ok'                => true,
						'already_finalized' => true,
						'slug'              => (string) $existing_slug,
						'site_token'        => (string) get_option( 'xpay_wc_site_token', '' ),
					),
					200
				);
			}
			if ( ! $nonce || ! $stored || ! hash_equals( $stored, $nonce ) ) {
				Xpay_Telemetry::track( 'finalize_error', array( 'reason' => 'invalid_nonce' ) );
				return new WP_REST_Response( array( 'error' => 'invalid_nonce' ), 401 );
			}
		}

		update_option( 'xpay_wc_merchant_slug', $slug );
		update_option( 'xpay_wc_api_key', $api_key );
		update_option( 'xpay_wc_connected_at', time() );
		// Clear the pending-attempt marker — the handshake succeeded.
		delete_option( 'xpay_wc_last_connect_attempt' );

		// Persist the UCP business profile body if the backend sent one.
		// Served at /.well-known/ucp by Xpay_REST::serve_ucp_profile().
		if ( isset( $payload['ucp_profile'] ) && is_array( $payload['ucp_profile'] ) ) {
			$encoded = wp_json_encode( $payload['ucp_profile'] );
			if ( false !== $encoded ) {
				update_option( 'xpay_wc_ucp_profile', $encoded );
			}
		}
		if ( isset( $payload['ucp_signing_keys'] ) && is_array( $payload['ucp_signing_keys'] ) ) {
			update_option( 'xpay_wc_ucp_signing_keys', $payload['ucp_signing_keys'] );
		}

		// Burn the local nonce only after creds are safely stored.
		// (Skipped on replay — the nonce may already be gone from a prior success.)
		if ( ! $is_replay ) {
			delete_option( self::NONCE_OPTION );
		}

		Xpay_Telemetry::track(
			'finalize_success',
			array(
				'slug'   => $slug,
				'replay' => $is_replay ? 1 : 0,
			)
		);

		// Kick the first catalog sync asynchronously (don't block the response,
		// don't fail finalize if resync errors). Scheduling a single-event cron
		// is the simplest fire-and-forget that survives request-end on most WP
		// hosts. Falls back to an inline non-blocking call if cron is disabled.
		if ( ! $is_replay ) {
			if ( ! wp_next_scheduled( 'xpay_wc_initial_resync' ) ) {
				wp_schedule_single_event( time() + 1, 'xpay_wc_initial_resync', array( $slug ) );
			}
			// Best-effort inline trigger in case wp-cron is disabled. Non-blocking
			// `wp_remote_request` returns immediately after the TCP write — perfect
			// for keeping the REST response fast.
			wp_remote_post(
				trailingslashit( XPAY_WC_API_BASE ) . 'v1/merchants/' . rawurlencode( $slug ) . '/resync',
				array(
					'timeout'  => 0.5,
					'blocking' => false,
					'headers'  => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $api_key,
						'X-Xpay-Site'   => home_url( '/' ),
					),
					'body'     => wp_json_encode( array( 'reason' => 'initial_install' ) ),
				)
			);
		}

		// Ship the site_token back as part of the finalize handshake response
		// so the backend can capture and persist it server-to-server, without
		// it ever appearing in a URL parameter, browser history, or access
		// log. The backend stores this on the merchant record and uses it as
		// the X-Xpay-Site-Token header for any future /wp-json/xpay/v1/admin/refresh
		// call. Idempotent: same value on every finalize (including replays).
		return new WP_REST_Response(
			array(
				'ok'         => true,
				'replay'     => $is_replay,
				'site_token' => (string) get_option( 'xpay_wc_site_token', '' ),
			),
			200
		);
	}

	public function handle_disconnect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_wc_disconnect' );

		$slug    = Xpay_Plugin::merchant_slug();
		$api_key = Xpay_Plugin::api_key();

		Xpay_Telemetry::track( 'disconnected', array( 'slug' => $slug ) );

		// Tell the backend BEFORE we clear local creds. Non-blocking, fire-and-forget —
		// the local disconnect must succeed even if the backend is unreachable. The
		// backend marks the merchant row status='disconnected' and archives the S3 catalog.
		if ( $slug && $api_key ) {
			wp_remote_request(
				trailingslashit( XPAY_WC_API_BASE ) . 'v1/merchants/' . rawurlencode( $slug ),
				array(
					'method'   => 'DELETE',
					'timeout'  => 0.5,
					'blocking' => false,
					'headers'  => array(
						'Accept'         => 'application/json',
						'X-Xpay-Api-Key' => $api_key,
						'X-Xpay-Site'    => home_url( '/' ),
					),
				)
			);
		}

		delete_option( 'xpay_wc_merchant_slug' );
		delete_option( 'xpay_wc_api_key' );
		delete_option( 'xpay_wc_connected_at' );
		delete_option( 'xpay_wc_last_audit' );
		delete_option( 'xpay_wc_ucp_profile' );
		delete_option( 'xpay_wc_ucp_signing_keys' );
		delete_option( 'xpay_wc_payment_map' );
		delete_option( 'xpay_wc_links' );
		delete_option( 'xpay_wc_telemetry_debug' );
		foreach ( self::CAPABILITIES as $cap ) {
			delete_option( 'xpay_wc_capability_' . $cap );
		}
		wp_safe_redirect( self::tab_url( 'general', array( 'disconnected' => 1 ) ) );
		exit;
	}

	public function handle_audit() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_wc_audit' );

		Xpay_Telemetry::track( 'audit_rerun_clicked' );

		$result = Xpay_Client::post(
			'/v1/audits/run',
			array(
				'url'   => home_url( '/' ),
				'slug'  => Xpay_Plugin::merchant_slug(),
				'email' => wp_get_current_user()->user_email,
			),
			20
		);
		if ( ! is_wp_error( $result ) ) {
			update_option( 'xpay_wc_last_audit', $result );
			Xpay_Telemetry::track(
				'audit_rerun_success',
				array(
					'score' => is_array( $result ) && isset( $result['score'] ) ? (int) $result['score'] : null,
				)
			);
		} else {
			Xpay_Telemetry::track(
				'audit_rerun_error',
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				)
			);
		}
		wp_safe_redirect( self::tab_url( 'general', array( 'audited' => 1 ) ) );
		exit;
	}

	// ---- Per-tab save handlers --------------------------------------------------

	/**
	 * Capture an agency/referral code from a settings-page deep link, e.g. an
	 * agency shares
	 * options-general.php?page=agentic-commerce-for-woocommerce&xpay_partner=acme
	 * — the merchant clicks it and is attributed with zero typing. No-op when an
	 * installer pinned the code via the XPAY_WC_PARTNER_CODE constant, or once
	 * connected (attribution is fixed at connect). The value is a non-privileged
	 * attribution token, so nonce-less GET capture is intentional and safe.
	 */
	public function maybe_capture_partner_from_query() {
		if ( Xpay_Partner::is_locked() || Xpay_Plugin::is_connected() ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['xpay_partner'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = Xpay_Partner::sanitize( sanitize_text_field( wp_unslash( $_GET['xpay_partner'] ) ) );
		if ( '' !== $code ) {
			Xpay_Partner::save_code( $code );
		}
	}

	public function handle_save_partner() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_wc_save_partner' );

		$code = isset( $_POST['xpay_wc_partner_code'] ) ? sanitize_text_field( wp_unslash( $_POST['xpay_wc_partner_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Xpay_Partner::save_code( $code );
		wp_safe_redirect( admin_url( 'options-general.php?page=agentic-commerce-for-woocommerce&partner_saved=1' ) );
		exit;
	}

	public function handle_save_capabilities() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_wc_save_capabilities' );

		// Checkbox UI: unchecked boxes don't submit. Default behaviour with no
		// option set is enabled, so persist explicit 0/1 for every cap.
		foreach ( self::CAPABILITIES as $cap ) {
			$on = isset( $_POST[ 'cap_' . $cap ] ) ? 1 : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_option( 'xpay_wc_capability_' . $cap, $on );
		}
		wp_safe_redirect( self::tab_url( 'capabilities', array( 'saved' => 1 ) ) );
		exit;
	}

	public function handle_save_payments() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_wc_save_payments' );

		$gateways = $this->available_gateways();
		$map      = array();
		foreach ( $gateways as $gw_id => $_gw ) {
			$enabled = isset( $_POST[ 'pay_' . $gw_id ] ) ? 1 : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$label   = isset( $_POST[ 'pay_label_' . $gw_id ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'pay_label_' . $gw_id ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $enabled ) {
				$map[ $gw_id ] = array(
					'enabled' => 1,
					'label'   => $label,
				);
			}
		}
		update_option( 'xpay_wc_payment_map', $map );
		wp_safe_redirect( self::tab_url( 'payments', array( 'saved' => 1 ) ) );
		exit;
	}

	public function handle_save_links() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_wc_save_links' );

		$links = array();
		foreach ( array_keys( self::LINK_TYPES ) as $type ) {
			$key = 'link_' . $type;
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			// Sanitize at the boundary (PCP rule WordPress.Security.ValidatedSanitizedInput.InputNotSanitized),
			// then validate as a URL with esc_url_raw().
			$raw = sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
			$url = esc_url_raw( $raw );
			if ( $url ) {
				$links[ $type ] = $url;
			}
		}
		update_option( 'xpay_wc_links', $links );
		wp_safe_redirect( self::tab_url( 'links', array( 'saved' => 1 ) ) );
		exit;
	}

	public function handle_telemetry_debug_toggle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_wc_telemetry_debug' );
		$current = (bool) get_option( 'xpay_wc_telemetry_debug', 0 );
		update_option( 'xpay_wc_telemetry_debug', $current ? 0 : 1 );
		wp_safe_redirect( self::tab_url( 'tools', array( 'saved' => 1 ) ) );
		exit;
	}

	public function handle_refresh_catalog() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_wc_refresh_catalog' );
		$slug   = Xpay_Plugin::merchant_slug();
		$status = 'noop';
		if ( $slug ) {
			$result = Xpay_Client::post( '/v1/merchants/' . rawurlencode( $slug ) . '/resync', array( 'reason' => 'merchant_tools_tab' ) );
			$status = is_wp_error( $result ) ? 'error' : 'queued';
		}
		wp_safe_redirect( self::tab_url( 'tools', array( 'resync' => $status ) ) );
		exit;
	}

	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_wc_test_connection' );
		$slug   = Xpay_Plugin::merchant_slug();
		$status = 'no_slug';
		if ( $slug ) {
			$result = Xpay_Client::get( '/v1/merchants/' . rawurlencode( $slug ) );
			if ( is_wp_error( $result ) ) {
				$status = 'error';
			} else {
				$status = 'ok';
			}
		}
		wp_safe_redirect( self::tab_url( 'tools', array( 'tested' => $status ) ) );
		exit;
	}

	/**
	 * Self-diagnostics. Loopback-probes the three things that silently break a
	 * merchant connection at the web-server layer before WordPress ever runs:
	 * Pretty Permalinks (the WP REST API), our own REST namespace, and the
	 * /.well-known/ discovery files (Apache/ACME shadowing on shared hosting).
	 * Network-only on this explicit click — never on settings render.
	 */
	public function handle_run_diagnostics() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_wc_run_diagnostics' );

		$checks = array(
			// rest_url() — never hardcode /wp-json (configurable + absent on
			// Plain permalinks). A non-2xx here means the WP REST API is
			// unreachable, which blocks the entire connect handshake.
			'rest_api'       => $this->probe_loopback( rest_url() ),
			// Our namespace index. 2xx ⇒ the plugin's routes are registered
			// and reachable; the finalize callback can land.
			'xpay_routes'    => $this->probe_loopback( rest_url( 'xpay/v1' ) ),
			// Discovery file at the literal web-server path. A 404 here while
			// rest_api passes is the classic Apache-reserves-/.well-known case.
			'well_known_ucp' => $this->probe_loopback( home_url( '/.well-known/ucp' ) ),
		);

		set_transient( 'xpay_wc_diagnostics', array( 'checks' => $checks, 'at' => time() ), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::tab_url( 'tools', array( 'diagnosed' => 1 ) ) );
		exit;
	}

	/**
	 * Single loopback GET. Returns ['ok'=>bool, 'code'=>int] (code 0 on a
	 * transport error). Mirrors Xpay_Emitter_Probe's self-fetch posture:
	 * short timeout, follow a couple redirects, don't fail on a self-signed
	 * cert (common on staging).
	 */
	private function probe_loopback( $url ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 6,
				'redirection' => 2,
				'sslverify'   => false,
				'headers'     => array(
					'User-Agent' => 'agentic-commerce-for-woocommerce/' . XPAY_WC_VERSION . ' (self-diagnostics)',
					'Accept'     => 'application/json',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'   => false,
				'code' => 0,
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return array(
			'ok'   => ( $code >= 200 && $code < 300 ),
			'code' => $code,
		);
	}

	// ---- Render ----------------------------------------------------------------

	public function render_page() {
		$connected = Xpay_Plugin::is_connected();
		$slug      = Xpay_Plugin::merchant_slug();
		$last_sync = (int) get_option( 'xpay_wc_last_sync_at', 0 );
		$audit     = get_option( 'xpay_wc_last_audit' );

		// Intentionally no telemetry on settings-page render. The
		// `Xpay_Telemetry::is_enabled()` gate would short-circuit when the
		// merchant has not opted in, but emitting any outbound HTTP from a
		// settings render path is a perception risk under the WP.org
		// "no phoning home" guideline. Lifecycle events fire from explicit
		// admin-post handlers only (Connect, Disconnect, telemetry toggle).

		echo '<div class="wrap xpay-wc-settings">';
		echo '<h1>' . esc_html__( 'Agentic Commerce for WooCommerce', 'agentic-commerce-for-woocommerce' ) . '</h1>';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display flags after admin-post redirect.
		if ( isset( $_GET['disconnected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Store disconnected from xpay.', 'agentic-commerce-for-woocommerce' ) . '</p></div>';
		}
		if ( isset( $_GET['audited'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Audit re-run queued. Refresh in ~30 seconds.', 'agentic-commerce-for-woocommerce' ) . '</p></div>';
		}
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'agentic-commerce-for-woocommerce' ) . '</p></div>';
		}
		if ( isset( $_GET['resync'] ) ) {
			$rs = sanitize_key( wp_unslash( $_GET['resync'] ) );
			$msg = 'queued' === $rs ? __( 'Catalog refresh queued — feed updates in ~30s.', 'agentic-commerce-for-woocommerce' )
				: ( 'error' === $rs ? __( 'Catalog refresh failed. Try again or check Tools → Test connection.', 'agentic-commerce-for-woocommerce' )
				: __( 'Connect the store before refreshing the catalog.', 'agentic-commerce-for-woocommerce' ) );
			$cls = 'queued' === $rs ? 'notice-success' : ( 'error' === $rs ? 'notice-error' : 'notice-warning' );
			echo '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( isset( $_GET['tested'] ) ) {
			$ts  = sanitize_key( wp_unslash( $_GET['tested'] ) );
			$msg = 'ok' === $ts ? __( 'Connection OK — xpay backend reachable.', 'agentic-commerce-for-woocommerce' )
				: ( 'error' === $ts ? __( 'Backend unreachable. Check network or contact support@xpay.sh.', 'agentic-commerce-for-woocommerce' )
				: __( 'Connect the store first.', 'agentic-commerce-for-woocommerce' ) );
			$cls = 'ok' === $ts ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $cls ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $connected ) {
			$last_attempt = (int) get_option( 'xpay_wc_last_connect_attempt', 0 );
			if ( $last_attempt > 0 && ( time() - $last_attempt ) < DAY_IN_SECONDS ) {
				$this->render_handshake_failed_notice();
			}
			$this->render_connect_panel();
			echo '</div>';
			return;
		}

		// Connected: render tab nav + active tab body.
		$tab = $this->current_tab();
		$this->render_tab_nav( $tab );
		echo '<div class="xpay-wc-tab-body" style="margin-top:20px;">';
		switch ( $tab ) {
			case 'capabilities':
				$this->render_tab_capabilities();
				break;
			case 'payments':
				$this->render_tab_payments();
				break;
			case 'links':
				$this->render_tab_links();
				break;
			case 'tools':
				$this->render_tab_tools( $slug );
				break;
			case 'general':
			default:
				$this->render_tab_general( $slug, $last_sync, $audit );
				break;
		}
		echo '</div>';

		if ( Xpay_Robots::physical_robots_exists() ) {
			echo '<div class="notice notice-warning"><p>';
			echo wp_kses_post( __( '<strong>Heads up:</strong> a physical <code>robots.txt</code> file is overriding WordPress\'s dynamic robots.txt. xpay\'s shopping-agent allowlist will not apply. Delete the physical file or edit it to allow GPTBot, ClaudeBot, PerplexityBot, and OAI-SearchBot.', 'agentic-commerce-for-woocommerce' ) );
			echo '</p></div>';
		}

		echo '</div>';
	}

	private function current_tab() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only tab selector.
		$raw = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$allowed = array( 'general', 'capabilities', 'payments', 'links', 'tools' );
		return in_array( $raw, $allowed, true ) ? $raw : 'general';
	}

	private function render_tab_nav( $active ) {
		$tabs = array(
			'general'      => __( 'General', 'agentic-commerce-for-woocommerce' ),
			'capabilities' => __( 'Capabilities', 'agentic-commerce-for-woocommerce' ),
			'payments'     => __( 'Payments', 'agentic-commerce-for-woocommerce' ),
			'links'        => __( 'Links', 'agentic-commerce-for-woocommerce' ),
			'tools'        => __( 'Tools', 'agentic-commerce-for-woocommerce' ),
		);
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$cls = 'nav-tab' . ( $slug === $active ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( self::tab_url( $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';
	}

	private function render_connect_panel() {
		// No outbound network calls and no persistent side-effects on render —
		// nonce generation, attempt-stamping and telemetry happen only when the
		// merchant clicks Connect (routed through handle_connect_start()).
		$start_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=xpay_wc_connect_start' ),
			'xpay_wc_connect_start'
		);

		echo '<div class="card" style="padding:20px;max-width:680px;">';
		echo '<h2>' . esc_html__( 'Connect your store', 'agentic-commerce-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Make your products discoverable to AI shoppers. Connecting lets ChatGPT, Claude, Gemini and Perplexity find your catalog and send buyers to your existing checkout — no code, and no change to how you get paid.', 'agentic-commerce-for-woocommerce' ) . '</p>';
		echo '<p style="color:#1d2327;background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 12px;margin:14px 0;">' . esc_html__( 'Safe to try: the plugin never writes files to your site and doesn\'t touch your theme or payments. If you already have an /llms.txt, we add to it rather than replace it. Deactivate anytime and your site is exactly as it was.', 'agentic-commerce-for-woocommerce' ) . '</p>';
		echo '<p style="font-size:13px;"><a href="https://docs.xpay.sh/merchants/woocommerce/connecting" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Step-by-step guide with screenshots →', 'agentic-commerce-for-woocommerce' ) . '</a></p>';
		echo '<p><a id="xpay-wc-connect-btn" class="button button-primary button-hero" href="' . esc_url( $start_url ) . '">' . esc_html__( 'Connect store →', 'agentic-commerce-for-woocommerce' ) . '</a></p>';
		echo '<p style="color:#646970;font-size:13px;">' . esc_html__( 'No payment processor change. Payouts continue through your existing WooCommerce gateway.', 'agentic-commerce-for-woocommerce' ) . '</p>';
		echo '<p style="color:#8c8f94;font-size:12px;margin-top:16px;">' . esc_html__( 'Technical details: publishes /llms.txt, schema.org JSON-LD and an AI-bot robots.txt allowlist, and exposes ACP / UCP / AP2 / MCP endpoints on xpay infrastructure.', 'agentic-commerce-for-woocommerce' ) . '</p>';
		echo '</div>';

		$this->render_partner_field();
	}

	/**
	 * Optional agency/referral code. Hidden complexity for solo merchants
	 * (small, secondary card), but the attribution hook agencies need. When an
	 * installer pins XPAY_WC_PARTNER_CODE in wp-config the field is read-only.
	 */
	private function render_partner_field() {
		$code   = Xpay_Partner::code();
		$locked = Xpay_Partner::is_locked();

		echo '<div class="card" style="padding:16px 20px;max-width:680px;margin-top:16px;">';
		echo '<h2 style="font-size:14px;margin:0 0 6px;">' . esc_html__( 'Agency / referral code', 'agentic-commerce-for-woocommerce' ) . '</h2>';
		echo '<p style="color:#646970;font-size:13px;margin-top:0;">' . esc_html__( 'Were you set up by an agency or partner? Enter their referral code so your store is credited to them. Leave blank if you came on your own.', 'agentic-commerce-for-woocommerce' ) . '</p>';

		if ( $locked ) {
			echo '<p><code>' . esc_html( $code ) . '</code> <span style="color:#646970;font-size:12px;">' . esc_html__( '(set by your installer in wp-config.php)', 'agentic-commerce-for-woocommerce' ) . '</span></p>';
			echo '</div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'xpay_wc_save_partner' );
		echo '<input type="hidden" name="action" value="xpay_wc_save_partner" />';
		echo '<input type="text" name="xpay_wc_partner_code" value="' . esc_attr( $code ) . '" class="regular-text" placeholder="' . esc_attr__( 'e.g. 3f9a2c7b', 'agentic-commerce-for-woocommerce' ) . '" /> ';
		submit_button( __( 'Save code', 'agentic-commerce-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Connect-button click handler. This is the first point at which the
	 * merchant has affirmatively asked us to contact xpay, so all outbound
	 * effects live here — never on settings-page render.
	 */
	public function handle_connect_start() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_wc_connect_start' );

		$nonce = wp_generate_password( 32, false );
		update_option( self::NONCE_OPTION, $nonce );
		update_option( 'xpay_wc_last_connect_attempt', time() );

		// Defence-in-depth: do not call into Telemetry at all unless the merchant
		// has explicitly opted in. Telemetry::track() also checks this internally,
		// but this guard makes handle_connect_start() trivially auditable as
		// network-silent when telemetry is OFF — a reviewer can verify the
		// no-phone-home guarantee without reading the Telemetry class body.
		if ( Xpay_Telemetry::is_enabled() ) {
			Xpay_Telemetry::track( 'connect_clicked' );
		}

		// Note: add_query_arg() urlencodes values internally — do NOT pre-encode.
		$onboard_args = array(
			'site'  => home_url( '/' ),
			'nonce' => $nonce,
			'email' => wp_get_current_user()->user_email,
		);

		// Partner attribution: if this store was set up by an agency, carry
		// their referral code plus store-level anti-fraud signals into the
		// onboard handoff. Counts only — no product/customer/order content.
		$partner = Xpay_Partner::code();
		if ( '' !== $partner ) {
			$signals                       = Xpay_Partner::signals();
			$onboard_args['partner']       = $partner;
			$onboard_args['sku_count']     = (int) $signals['sku_count'];
			$onboard_args['order_count']   = (int) $signals['order_count'];
			$onboard_args['live_gateways'] = implode( ',', $signals['live_gateways'] );
		}

		$onboard_url = add_query_arg( $onboard_args, XPAY_WC_ONBOARD_URL );

		// Allow-list the xpay onboarding host so wp_safe_redirect() permits the
		// off-site hop. This is the WP-native pattern for a known SaaS handoff
		// and is preferred by WP.org review over a raw wp_redirect() to an
		// external URL.
		add_filter(
			'allowed_redirect_hosts',
			static function ( $hosts ) {
				$host = wp_parse_url( XPAY_WC_ONBOARD_URL, PHP_URL_HOST );
				if ( $host ) {
					$hosts[] = $host;
				}
				return $hosts;
			}
		);
		wp_safe_redirect( $onboard_url );
		exit;
	}

	// ---- Tab: General ---------------------------------------------------------

	private function render_tab_general( $slug, $last_sync, $audit ) {
		echo '<div class="card" style="padding:20px;max-width:680px;">';
		echo '<h2>' . esc_html__( 'Connected', 'agentic-commerce-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Merchant slug', 'agentic-commerce-for-woocommerce' ) . '</th><td><code>' . esc_html( $slug ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Catalog feed', 'agentic-commerce-for-woocommerce' ) . '</th><td><a href="' . esc_url( "https://agent-feed.xpay.sh/catalog/{$slug}.json" ) . '" target="_blank" rel="noopener">agent-feed.xpay.sh/catalog/' . esc_html( $slug ) . '.json</a></td></tr>';
		echo '<tr><th>' . esc_html__( 'Last sync', 'agentic-commerce-for-woocommerce' ) . '</th><td>' . ( $last_sync ? esc_html( human_time_diff( $last_sync ) . ' ' . __( 'ago', 'agentic-commerce-for-woocommerce' ) ) : esc_html__( 'pending', 'agentic-commerce-for-woocommerce' ) ) . '</td></tr>';
		if ( is_array( $audit ) && isset( $audit['score'] ) ) {
			echo '<tr><th>' . esc_html__( 'Last audit score', 'agentic-commerce-for-woocommerce' ) . '</th><td><strong>' . (int) $audit['score'] . '/100</strong></td></tr>';
		}
		echo '</tbody></table>';

		echo '<p>';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin-post.php?action=xpay_wc_disconnect&_wpnonce=' . wp_create_nonce( 'xpay_wc_disconnect' ) ) ) . '">' . esc_html__( 'Disconnect', 'agentic-commerce-for-woocommerce' ) . '</a>';
		echo '</p>';
		echo '</div>';

		$this->render_readiness_checklist();
		$this->render_privacy_panel();
		$this->render_external_services_panel();
	}

	/**
	 * Transparency card: every external host this plugin contacts and why.
	 * Mirrors the publisher plugin's "External services this plugin contacts"
	 * disclosure — a comforting, reviewer-friendly surface that makes the data
	 * flow auditable at a glance. No network calls; static copy.
	 */
	private function render_external_services_panel() {
		echo '<h2 style="margin-top:32px;">' . esc_html__( 'External services this plugin contacts', 'agentic-commerce-for-woocommerce' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:20px;max-width:680px;">';
		echo '<li><code>agent-feed.xpay.sh</code> — ' . esc_html__( 'public CDN that hosts your agent-readable catalog feed (product titles, prices, links, images). No customer or order data.', 'agentic-commerce-for-woocommerce' ) . '</li>';
		echo '<li><code>agent-commerce.xpay.sh</code> — ' . esc_html__( 'hosts the ACP / UCP / AP2 / MCP commerce endpoints agents use to build carts and hand off to your checkout.', 'agentic-commerce-for-woocommerce' ) . '</li>';
		echo '<li><code>app.xpay.sh</code> — ' . esc_html__( 'your xpay dashboard and the connect flow, opened in a new tab. Not embedded in wp-admin.', 'agentic-commerce-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'Partner attribution (optional): if a referral code is set, it is sent with the connect handoff and on backend calls so the partner who set up your store can be credited. Alongside it we send three store-level totals only — your published product count, completed-order count, and the IDs of your enabled live payment gateways. No product, customer or order details.', 'agentic-commerce-for-woocommerce' ) . '</li>';
		echo '<li><code>agent-commerce.xpay.sh/v1/agent-analytics</code> — ' . esc_html__( 'optional, opt-in only: anonymous AI-bot crawl counts (which AI crawler fetched which kind of page, the HTTP status, and whether we routed it to your catalog), plus an aggregate daily count of human pageviews (a number only — no UA, no URL, no per-visit data) so you can see the AI-vs-human split. No customer or order data, no IPs. Batched in the background; shares the Privacy opt-in below.', 'agentic-commerce-for-woocommerce' ) . '</li>';
		echo '</ul>';
		echo '<p style="font-size:13px;">' . sprintf(
			/* translators: 1: terms-of-use link, 2: privacy-policy link */
			esc_html__( 'Terms of use: %1$s · Privacy policy: %2$s', 'agentic-commerce-for-woocommerce' ),
			'<a href="https://www.xpay.sh/legal/terms-of-use/" target="_blank" rel="noopener">xpay.sh/legal/terms-of-use</a>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'<a href="https://www.xpay.sh/legal/privacy-policy/" target="_blank" rel="noopener">xpay.sh/legal/privacy-policy</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		) . '</p>';
	}

	// ---- Tab: Capabilities ----------------------------------------------------

	private function render_tab_capabilities() {
		echo '<div class="card" style="padding:20px;max-width:680px;">';
		echo '<h2>' . esc_html__( 'UCP capabilities', 'agentic-commerce-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Toggle which shopping capabilities are advertised in /.well-known/ucp. All on by default. Switching one off removes it from the manifest agents fetch.', 'agentic-commerce-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'xpay_wc_save_capabilities' );
		echo '<input type="hidden" name="action" value="xpay_wc_save_capabilities" />';
		echo '<table class="form-table"><tbody>';
		$labels = array(
			'checkout'    => __( 'Checkout — required for cart-based purchasing.', 'agentic-commerce-for-woocommerce' ),
			'fulfillment' => __( 'Fulfillment — shipping methods, rates, delivery dates.', 'agentic-commerce-for-woocommerce' ),
			'discount'    => __( 'Discount — coupons and promotional codes.', 'agentic-commerce-for-woocommerce' ),
			'order'       => __( 'Order — post-purchase order status lookups.', 'agentic-commerce-for-woocommerce' ),
		);
		foreach ( self::CAPABILITIES as $cap ) {
			$on = $this->capability_enabled( $cap );
			echo '<tr>';
			echo '<th scope="row"><label for="cap_' . esc_attr( $cap ) . '">dev.ucp.shopping.' . esc_html( $cap ) . '</label></th>';
			echo '<td><label><input type="checkbox" id="cap_' . esc_attr( $cap ) . '" name="cap_' . esc_attr( $cap ) . '" value="1"' . checked( $on, true, false ) . ' /> ';
			echo esc_html( $labels[ $cap ] ) . '</label></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save capabilities', 'agentic-commerce-for-woocommerce' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Default-on resolver. A missing option key means "enabled" so existing
	 * connected installs upgrading into 0.2.4 don't see capabilities silently
	 * disappear from their manifest.
	 */
	public static function capability_enabled( $cap ) {
		$opt = get_option( 'xpay_wc_capability_' . $cap, null );
		if ( null === $opt || '' === $opt ) {
			return true;
		}
		return (bool) (int) $opt;
	}

	// ---- Tab: Payments --------------------------------------------------------

	private function available_gateways() {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}
		$wc = WC();
		if ( ! $wc || ! method_exists( $wc, 'payment_gateways' ) ) {
			return array();
		}
		$mgr = $wc->payment_gateways();
		if ( ! $mgr ) {
			return array();
		}
		$gateways = $mgr->get_available_payment_gateways();
		return is_array( $gateways ) ? $gateways : array();
	}

	private function render_tab_payments() {
		echo '<div class="card" style="padding:20px;max-width:840px;">';
		echo '<h2>' . esc_html__( 'Payment handlers', 'agentic-commerce-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Map your enabled WooCommerce payment gateways to UCP payment_handlers[]. Agents use this list to decide which payment methods they can complete on the buyer\'s behalf.', 'agentic-commerce-for-woocommerce' ) . '</p>';

		$gateways = $this->available_gateways();
		if ( empty( $gateways ) ) {
			echo '<p><em>' . esc_html__( 'No payment gateways enabled in WooCommerce yet. Add a gateway under WooCommerce → Settings → Payments first.', 'agentic-commerce-for-woocommerce' ) . '</em></p>';
			echo '</div>';
			return;
		}

		$map = get_option( 'xpay_wc_payment_map', array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'xpay_wc_save_payments' );
		echo '<input type="hidden" name="action" value="xpay_wc_save_payments" />';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Expose to agents', 'agentic-commerce-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Gateway', 'agentic-commerce-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'UCP handler label (optional)', 'agentic-commerce-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $gateways as $gw_id => $gw ) {
			$existing = isset( $map[ $gw_id ] ) && is_array( $map[ $gw_id ] ) ? $map[ $gw_id ] : null;
			$on       = (bool) ( $existing && ! empty( $existing['enabled'] ) );
			$label    = $existing && isset( $existing['label'] ) ? (string) $existing['label'] : ( method_exists( $gw, 'get_title' ) ? $gw->get_title() : $gw_id );
			echo '<tr>';
			echo '<td><label><input type="checkbox" name="pay_' . esc_attr( $gw_id ) . '" value="1"' . checked( $on, true, false ) . ' /></label></td>';
			echo '<td><code>' . esc_html( $gw_id ) . '</code><br /><span style="color:#646970;font-size:12px;">' . esc_html( method_exists( $gw, 'get_method_title' ) ? $gw->get_method_title() : $gw_id ) . '</span></td>';
			echo '<td><input type="text" name="pay_label_' . esc_attr( $gw_id ) . '" value="' . esc_attr( $label ) . '" class="regular-text" /></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save payment handlers', 'agentic-commerce-for-woocommerce' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Public so Xpay_REST::serve_ucp_profile() can use it to populate
	 * payment_handlers[] in the manifest.
	 */
	public static function payment_handlers() {
		$map = get_option( 'xpay_wc_payment_map', array() );
		if ( ! is_array( $map ) ) {
			return array();
		}
		$out = array();
		foreach ( $map as $gw_id => $cfg ) {
			if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $gw_id,
				'label' => isset( $cfg['label'] ) ? (string) $cfg['label'] : $gw_id,
				'type'  => 'merchant_gateway',
			);
		}
		return $out;
	}

	// ---- Tab: Links -----------------------------------------------------------

	private function render_tab_links() {
		echo '<div class="card" style="padding:20px;max-width:840px;">';
		echo '<h2>' . esc_html__( 'Policy & info links', 'agentic-commerce-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'These appear in the ucp.links array so agents can surface your privacy policy, TOS, contact, etc. to the buyer. Auto-detected where possible; override any field below.', 'agentic-commerce-for-woocommerce' ) . '</p>';

		$saved      = get_option( 'xpay_wc_links', array() );
		$saved      = is_array( $saved ) ? $saved : array();
		$detected   = $this->detect_links();
		$labels     = array(
			'privacy'  => __( 'Privacy policy', 'agentic-commerce-for-woocommerce' ),
			'terms'    => __( 'Terms of service', 'agentic-commerce-for-woocommerce' ),
			'about'    => __( 'About', 'agentic-commerce-for-woocommerce' ),
			'contact'  => __( 'Contact', 'agentic-commerce-for-woocommerce' ),
			'shipping' => __( 'Shipping policy', 'agentic-commerce-for-woocommerce' ),
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'xpay_wc_save_links' );
		echo '<input type="hidden" name="action" value="xpay_wc_save_links" />';
		echo '<table class="form-table"><tbody>';
		foreach ( $labels as $type => $label ) {
			$val = isset( $saved[ $type ] ) ? $saved[ $type ] : ( isset( $detected[ $type ] ) ? $detected[ $type ] : '' );
			$ph  = isset( $detected[ $type ] ) ? $detected[ $type ] : '';
			echo '<tr>';
			echo '<th scope="row"><label for="link_' . esc_attr( $type ) . '">' . esc_html( $label ) . '</label></th>';
			echo '<td><input type="url" id="link_' . esc_attr( $type ) . '" name="link_' . esc_attr( $type ) . '" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr( $ph ) . '" class="regular-text code" />';
			if ( $ph && $val !== $ph ) {
				echo '<br /><span style="color:#646970;font-size:12px;">' . esc_html( sprintf( /* translators: %s: detected URL */ __( 'Auto-detected: %s', 'agentic-commerce-for-woocommerce' ), $ph ) ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save links', 'agentic-commerce-for-woocommerce' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Tolerant link auto-detect. Tries WP's privacy_policy_url() first, then
	 * walks LINK_TYPES candidate slugs, then probes wp_pages by title. Returns
	 * a `type => url` map; missing types simply aren't keys.
	 */
	private function detect_links() {
		$out = array();
		if ( function_exists( 'get_privacy_policy_url' ) ) {
			$pp = get_privacy_policy_url();
			if ( $pp ) {
				$out['privacy'] = $pp;
			}
		}
		foreach ( self::LINK_TYPES as $type => $slugs ) {
			if ( isset( $out[ $type ] ) ) {
				continue;
			}
			foreach ( $slugs as $slug ) {
				$page = get_page_by_path( $slug );
				if ( $page ) {
					$url = get_permalink( $page );
					if ( $url ) {
						$out[ $type ] = $url;
						break;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Public so Xpay_REST::serve_ucp_profile() can render the ucp.links array.
	 * Falls back to auto-detect so a fresh install with no overrides still
	 * emits useful links.
	 */
	public static function ucp_links() {
		$self  = self::instance();
		$saved = get_option( 'xpay_wc_links', array() );
		$saved = is_array( $saved ) ? $saved : array();
		// Use detection as fallback for unset types so merchants who never visit
		// the Links tab still ship a useful manifest.
		$detected = $self->detect_links();
		$merged   = array_filter( array_merge( $detected, $saved ) );
		$links    = array();
		foreach ( $merged as $rel => $href ) {
			if ( ! is_string( $href ) || '' === $href ) {
				continue;
			}
			$links[] = array(
				'rel'  => $rel,
				'href' => $href,
			);
		}
		return $links;
	}

	// ---- Tab: Tools -----------------------------------------------------------

	private function render_tab_tools( $slug ) {
		echo '<div class="card" style="padding:20px;max-width:840px;">';
		echo '<h2>' . esc_html__( 'Tools', 'agentic-commerce-for-woocommerce' ) . '</h2>';

		$ucp_url   = home_url( '/.well-known/ucp' );
		$audit_url = $slug ? sprintf( 'https://audit.xpay.sh/m/%s', rawurlencode( $slug ) ) : '';

		echo '<p>';
		echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( $ucp_url ) . '">' . esc_html__( 'View UCP profile', 'agentic-commerce-for-woocommerce' ) . '</a> ';
		if ( $audit_url ) {
			echo '<a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url( $audit_url ) . '">' . esc_html__( 'View full agent-readiness audit →', 'agentic-commerce-for-woocommerce' ) . '</a>';
		}
		echo '</p>';

		echo '<hr />';
		echo '<h3>' . esc_html__( 'Live actions', 'agentic-commerce-for-woocommerce' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
		wp_nonce_field( 'xpay_wc_test_connection' );
		echo '<input type="hidden" name="action" value="xpay_wc_test_connection" />';
		submit_button( __( 'Test connection', 'agentic-commerce-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
		wp_nonce_field( 'xpay_wc_refresh_catalog' );
		echo '<input type="hidden" name="action" value="xpay_wc_refresh_catalog" />';
		submit_button( __( 'Refresh catalog now', 'agentic-commerce-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
		wp_nonce_field( 'xpay_wc_run_diagnostics' );
		echo '<input type="hidden" name="action" value="xpay_wc_run_diagnostics" />';
		submit_button( __( 'Run site diagnostics', 'agentic-commerce-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';

		$this->render_diagnostics_result();

		echo '<hr />';
		echo '<h3>' . esc_html__( 'Debug', 'agentic-commerce-for-woocommerce' ) . '</h3>';
		$debug_on    = (bool) get_option( 'xpay_wc_telemetry_debug', 0 );
		$debug_label = $debug_on ? __( 'Turn off telemetry debug logging', 'agentic-commerce-for-woocommerce' ) : __( 'Turn on telemetry debug logging', 'agentic-commerce-for-woocommerce' );
		$state_label = $debug_on ? __( 'enabled', 'agentic-commerce-for-woocommerce' ) : __( 'disabled', 'agentic-commerce-for-woocommerce' );
		echo '<p>' . sprintf(
			/* translators: %s: enabled|disabled */
			esc_html__( 'Outbound HTTP request logging is %s. When enabled, every xpay backend call is logged to PHP error_log for troubleshooting. Separate from the anonymous-telemetry opt-in on the General tab.', 'agentic-commerce-for-woocommerce' ),
			'<strong>' . esc_html( $state_label ) . '</strong>'
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'xpay_wc_telemetry_debug' );
		echo '<input type="hidden" name="action" value="xpay_wc_telemetry_debug" />';
		submit_button( $debug_label, 'secondary', 'submit', false );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Render the cached result of the last "Run site diagnostics" click, with
	 * targeted remediation for whichever layer failed.
	 */
	private function render_diagnostics_result() {
		$diag = get_transient( 'xpay_wc_diagnostics' );
		if ( ! is_array( $diag ) || empty( $diag['checks'] ) ) {
			echo '<p style="color:#646970;font-size:13px;margin-top:12px;max-width:680px;">' . esc_html__( 'Run site diagnostics to confirm AI agents and the xpay backend can reach your store: WordPress Permalinks, the REST API, our plugin routes, and the /.well-known/ discovery files.', 'agentic-commerce-for-woocommerce' ) . '</p>';
			return;
		}

		$checks = $diag['checks'];
		$rest   = isset( $checks['rest_api'] ) && is_array( $checks['rest_api'] ) ? $checks['rest_api'] : array( 'ok' => false, 'code' => 0 );
		$routes = isset( $checks['xpay_routes'] ) && is_array( $checks['xpay_routes'] ) ? $checks['xpay_routes'] : array( 'ok' => false, 'code' => 0 );
		$wk     = isset( $checks['well_known_ucp'] ) && is_array( $checks['well_known_ucp'] ) ? $checks['well_known_ucp'] : array( 'ok' => false, 'code' => 0 );

		echo '<table class="widefat striped" style="max-width:680px;margin-top:14px;"><tbody>';
		$this->diag_row( __( 'WordPress REST API (Permalinks)', 'agentic-commerce-for-woocommerce' ), $rest );
		$this->diag_row( __( 'xpay plugin REST routes', 'agentic-commerce-for-woocommerce' ), $routes );
		$this->diag_row( __( '/.well-known/ discovery files', 'agentic-commerce-for-woocommerce' ), $wk );
		echo '</tbody></table>';

		if ( ! $rest['ok'] ) {
			echo '<div class="notice notice-error inline" style="max-width:680px;margin-top:10px;"><p>' . esc_html__( 'Your WordPress REST API is not reachable. Go to Settings → Permalinks, choose "Post name", and click Save Changes. This is required before connecting to xpay and for AI agents to read your catalog.', 'agentic-commerce-for-woocommerce' ) . '</p></div>';
		} elseif ( ! $routes['ok'] ) {
			echo '<div class="notice notice-warning inline" style="max-width:680px;margin-top:10px;"><p>' . esc_html__( "The plugin's REST routes are not responding even though the REST API is up. Try deactivating and reactivating the plugin. If it persists, a security plugin (Wordfence, iThemes Security) or a server firewall may be blocking /wp-json/xpay/.", 'agentic-commerce-for-woocommerce' ) . '</p></div>';
		} elseif ( ! $wk['ok'] ) {
			$fallback_url = home_url( '/?xpay_route=ucp_profile' );
			echo '<div class="notice notice-warning inline" style="max-width:680px;margin-top:10px;"><p>';
			echo esc_html__( 'Your store is connectable, but /.well-known/ files return an error at the web-server layer. Some hosts (Apache/ACME) reserve /.well-known/ and answer before WordPress runs. AI agents can still reach your discovery profile via this fallback URL:', 'agentic-commerce-for-woocommerce' );
			echo ' <a href="' . esc_url( $fallback_url ) . '" target="_blank" rel="noopener"><code>' . esc_html( $fallback_url ) . '</code></a>. ';
			echo esc_html__( 'For clean /.well-known/ URLs, ask your host to let WordPress handle /.well-known/ requests.', 'agentic-commerce-for-woocommerce' );
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-success inline" style="max-width:680px;margin-top:10px;"><p>' . esc_html__( 'All checks passed — agents and xpay can reach your store.', 'agentic-commerce-for-woocommerce' ) . '</p></div>';
		}
	}

	private function diag_row( $label, $check ) {
		$ok   = ! empty( $check['ok'] );
		$code = isset( $check['code'] ) ? (int) $check['code'] : 0;
		$mark = $ok ? '<span style="color:#008a20;font-weight:600;">&#10003;</span>' : '<span style="color:#d63638;font-weight:600;">&#10007;</span>';
		$http = $code > 0 ? sprintf( 'HTTP %d', $code ) : __( 'no response', 'agentic-commerce-for-woocommerce' );
		echo '<tr><td style="width:32px;">' . wp_kses_post( $mark ) . '</td><td>' . esc_html( $label ) . '</td><td style="color:#646970;">' . esc_html( $http ) . '</td></tr>';
	}

	public function handle_telemetry_toggle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_wc_telemetry' );
		$raw    = isset( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : '';
		$choice = ( 'yes' === $raw ) ? 'yes' : 'no';
		Xpay_Telemetry::set_opt_in( $choice );
		wp_safe_redirect( self::tab_url( 'general' ) );
		exit;
	}

	private function render_handshake_failed_notice() {
		$last_attempt = (int) get_option( 'xpay_wc_last_connect_attempt', 0 );
		$ago          = $last_attempt ? human_time_diff( $last_attempt, time() ) : '';
		echo '<div class="notice notice-warning" style="padding:14px 18px;border-left:4px solid #ea580c;background:#fff7ed;">';
		echo '<h3 style="margin:0 0 8px;font-size:15px;color:#7c2d12;">' . esc_html__( 'Apologies for the trouble — your previous connection attempt didn\'t complete.', 'agentic-commerce-for-woocommerce' ) . '</h3>';
		echo '<p style="margin:0 0 6px;color:#7c2d12;">';
		printf(
			/* translators: %s: human-readable elapsed time */
			esc_html__( 'About %s ago you tried to connect this store to xpay, but the handshake didn\'t finish. Our team has already been notified; in the meantime please click Connect store again — the link is single-use and short-lived for security, so a fresh click is the fastest path to finishing the setup.', 'agentic-commerce-for-woocommerce' ),
			esc_html( $ago )
		);
		echo '</p>';
		echo '</div>';
	}

	private function render_privacy_panel() {
		$enabled       = Xpay_Telemetry::is_enabled();
		$toggle_choice = $enabled ? 'no' : 'yes';
		$toggle_label  = $enabled ? __( 'Turn off anonymous telemetry', 'agentic-commerce-for-woocommerce' ) : __( 'Turn on anonymous telemetry', 'agentic-commerce-for-woocommerce' );
		$state_label   = $enabled ? __( 'on', 'agentic-commerce-for-woocommerce' ) : __( 'off', 'agentic-commerce-for-woocommerce' );

		echo '<h2 style="margin-top:32px;">' . esc_html__( 'Privacy', 'agentic-commerce-for-woocommerce' ) . '</h2>';
		echo '<p>' . sprintf(
			/* translators: %s: on|off */
			esc_html__( 'Anonymous lifecycle telemetry is %s. No customer data, no order data, no PII is ever sent. We only see plugin lifecycle events (activate, connect, sync errors) tied to your site URL.', 'agentic-commerce-for-woocommerce' ),
			'<strong>' . esc_html( $state_label ) . '</strong>'
		) . '</p>';
		echo '<p style="font-size:13px;color:#646970;">' . esc_html__( 'This same switch also controls anonymous AI-bot crawl analytics: when on, the plugin counts how AI crawlers (GPTBot, ClaudeBot, PerplexityBot and similar) discover your store — which pages they fetch and whether they reach your discovery files — so you can see this on your xpay dashboard. It records known AI bots plus an aggregate daily count of human pageviews (a number only — no per-visit data) as the AI-vs-human denominator, and never customer, order, or personal data. To disable just this while keeping lifecycle telemetry, add', 'agentic-commerce-for-woocommerce' ) . ' <code>define( \'XPAY_WC_AGENT_ANALYTICS\', false );</code> ' . esc_html__( 'to wp-config.php.', 'agentic-commerce-for-woocommerce' ) . '</p>';

		$url = wp_nonce_url( admin_url( 'admin-post.php?action=xpay_wc_telemetry&choice=' . $toggle_choice ), 'xpay_wc_telemetry' );
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html( $toggle_label ) . '</a></p>';
	}

	/**
	 * Mirrors the eight checks from scripts/seller-audit/audit.py so the merchant
	 * can see, in their own dashboard, which audit boxes the plugin is satisfying.
	 */
	private function render_readiness_checklist() {
		$slug = Xpay_Plugin::merchant_slug();

		$physical_robots = Xpay_Robots::physical_robots_exists();

		$rows = array(
			array(
				__( 'AI can read your full catalogue', 'agentic-commerce-for-woocommerce' ),
				(bool) $slug,
				$slug
					/* translators: %s: merchant slug */
					? sprintf( __( 'Hosted feed live at agent-feed.xpay.sh/catalog/%s.json', 'agentic-commerce-for-woocommerce' ), $slug )
					: __( 'Connect store to enable.', 'agentic-commerce-for-woocommerce' ),
			),
			array(
				__( 'Live prices visible to AI', 'agentic-commerce-for-woocommerce' ),
				true,
				__( 'JSON-LD Product / Offer schema injected on product pages.', 'agentic-commerce-for-woocommerce' ),
			),
			array(
				__( 'Plain-text guide for AI assistants', 'agentic-commerce-for-woocommerce' ),
				true,
				__( 'Served at /llms.txt.', 'agentic-commerce-for-woocommerce' ),
			),
			array(
				__( 'AI assistants know where to send a buyer', 'agentic-commerce-for-woocommerce' ),
				(bool) $slug,
				$slug
					? __( 'Per-protocol endpoints (ACP / UCP / AP2 / MCP) advertised in /llms.txt and hosted on xpay infra.', 'agentic-commerce-for-woocommerce' )
					: __( 'Connect store to populate endpoints.', 'agentic-commerce-for-woocommerce' ),
			),
			array(
				__( 'AI shoppers are allowed in', 'agentic-commerce-for-woocommerce' ),
				! $physical_robots,
				$physical_robots
					? __( 'Physical robots.txt detected — needs manual fix.', 'agentic-commerce-for-woocommerce' )
					: __( 'GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot allowed in robots.txt.', 'agentic-commerce-for-woocommerce' ),
			),
			array(
				__( 'Direct buy link signals', 'agentic-commerce-for-woocommerce' ),
				true,
				__( 'BuyAction emitted on every product page.', 'agentic-commerce-for-woocommerce' ),
			),
			array(
				__( 'AI shoppers can buy without leaving the chat', 'agentic-commerce-for-woocommerce' ),
				(bool) $slug,
				$slug
					? __( 'Cart deeplink handler active.', 'agentic-commerce-for-woocommerce' )
					: __( 'Connect store to enable.', 'agentic-commerce-for-woocommerce' ),
			),
			array(
				__( 'Stock & price kept current', 'agentic-commerce-for-woocommerce' ),
				(bool) $slug,
				$slug
					? __( 'Webhook resync on product/stock change.', 'agentic-commerce-for-woocommerce' )
					: __( 'Connect store to enable.', 'agentic-commerce-for-woocommerce' ),
			),
		);

		echo '<h2 style="margin-top:32px;">' . esc_html__( 'Audit readiness', 'agentic-commerce-for-woocommerce' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:880px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Check', 'agentic-commerce-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'agentic-commerce-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Detail', 'agentic-commerce-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';
		$ready_label   = esc_html__( '✓ Ready', 'agentic-commerce-for-woocommerce' );
		$pending_label = esc_html__( '⚠ Pending', 'agentic-commerce-for-woocommerce' );
		foreach ( $rows as $r ) {
			list( $label, $pass, $detail ) = $r;
			$badge                         = $pass
				? '<span style="color:#15803d;font-weight:600;">' . $ready_label . '</span>'
				: '<span style="color:#b91c1c;font-weight:600;">' . $pending_label . '</span>';
			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . wp_kses_post( $badge ) . '</td>';
			echo '<td>' . esc_html( $detail ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
