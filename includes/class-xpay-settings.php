<?php
/**
 * Settings → xpay admin page. Handles:
 *   - "Connect store" — opens app.xpay.sh/onboard/woocommerce in a popup with a
 *     nonce; xpay calls back to /wp-json/xpay/v1/finalize to deliver
 *     merchant_slug + api_key.
 *   - Status panel — connection state, last sync, last audit score, top issues.
 *   - "Re-run audit" — calls api.xpay.sh/v1/audits/run and refreshes the panel.
 *   - "Disconnect" — clears local credentials.
 */

defined( 'ABSPATH' ) || exit;

class Xpay_Settings {

	private static $instance = null;
	const NONCE_OPTION       = 'xpay_wc_onboard_nonce';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_xpay_wc_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_xpay_wc_audit', array( $this, 'handle_audit' ) );
		add_action( 'admin_post_xpay_wc_telemetry', array( $this, 'handle_telemetry_toggle' ) );
		add_action( 'rest_api_init', array( $this, 'register_finalize_route' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( XPAY_WC_FILE ), array( $this, 'plugin_action_links' ) );
		// Beacon endpoint so the Connect button click can be tracked without blocking the redirect.
		add_action( 'wp_ajax_xpay_wc_track', array( $this, 'ajax_track' ) );
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

	public function ajax_track() {
		// Lightly authenticated — admin user only — and event name is enum-checked.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_success(); // Silent success; never reveal.
		}
		$allowed = array( 'connect_clicked', 'settings_viewed' );
		// Beacon endpoint authenticated via current_user_can(manage_woocommerce) above
		// and the event value is enum-checked; no form/nonce needed.
		$event = isset( $_POST['event'] ) ? sanitize_text_field( wp_unslash( $_POST['event'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( in_array( $event, $allowed, true ) ) {
			Xpay_Telemetry::track( $event );
		}
		wp_send_json_success();
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
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=agentic-commerce-for-woocommerce' ) ), esc_html__( 'Settings', 'agentic-commerce-for-woocommerce' ) ) );
		return $links;
	}

	public function register_finalize_route() {
		register_rest_route(
			'xpay/v1',
			'/finalize',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_finalize' ),
				'permission_callback' => '__return_true', // Validated via nonce in payload.
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

		return new WP_REST_Response( array( 'ok' => true, 'replay' => $is_replay ), 200 );
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
		wp_safe_redirect( admin_url( 'options-general.php?page=agentic-commerce-for-woocommerce&disconnected=1' ) );
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
		wp_safe_redirect( admin_url( 'options-general.php?page=agentic-commerce-for-woocommerce&audited=1' ) );
		exit;
	}

	public function render_page() {
		$connected = Xpay_Plugin::is_connected();
		$slug      = Xpay_Plugin::merchant_slug();
		$last_sync = (int) get_option( 'xpay_wc_last_sync_at', 0 );
		$audit     = get_option( 'xpay_wc_last_audit' );

		Xpay_Telemetry::track(
			'settings_viewed',
			array(
				'connected' => $connected,
			)
		);

		echo '<div class="wrap xpay-wc-settings">';
		echo '<h1>' . esc_html__( 'xpay for WooCommerce', 'agentic-commerce-for-woocommerce' ) . '</h1>';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display flags after admin-post redirect.
		if ( isset( $_GET['disconnected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Store disconnected from xpay.', 'agentic-commerce-for-woocommerce' ) . '</p></div>';
		}
		if ( isset( $_GET['audited'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Audit re-run queued. Refresh in ~30 seconds.', 'agentic-commerce-for-woocommerce' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $connected ) {
			$last_attempt = (int) get_option( 'xpay_wc_last_connect_attempt', 0 );
			// If the merchant tried to connect within the last 24 hours but we
			// still don't have an api_key locally, surface a clear apology so
			// they know the previous attempt failed and what to do next.
			if ( $last_attempt > 0 && ( time() - $last_attempt ) < DAY_IN_SECONDS ) {
				$this->render_handshake_failed_notice();
			}
			$this->render_connect_panel();
		} else {
			$this->render_status_panel( $slug, $last_sync, $audit );
		}

		$this->render_readiness_checklist();
		$this->render_privacy_panel();

		if ( Xpay_Robots::physical_robots_exists() ) {
			echo '<div class="notice notice-warning"><p>';
			echo wp_kses_post( __( '<strong>Heads up:</strong> a physical <code>robots.txt</code> file is overriding WordPress\'s dynamic robots.txt. xpay\'s shopping-agent allowlist will not apply. Delete the physical file or edit it to allow GPTBot, ClaudeBot, PerplexityBot, and OAI-SearchBot.', 'agentic-commerce-for-woocommerce' ) );
			echo '</p></div>';
		}

		echo '</div>';
	}

	private function render_connect_panel() {
		$nonce = wp_generate_password( 32, false );
		update_option( self::NONCE_OPTION, $nonce );

		$onboard_url = add_query_arg(
			array(
				'site'  => rawurlencode( home_url( '/' ) ),
				'nonce' => $nonce,
				'email' => rawurlencode( wp_get_current_user()->user_email ),
			),
			XPAY_WC_ONBOARD_URL
		);

		// Pre-register the nonce server-side so the finalize step can validate it.
		// Failures here are non-fatal — the user can still click the button and the
		// xpay app will create the nonce on demand if needed.
		Xpay_Client::post(
			'/v1/onboard/woocommerce/start',
			array(
				'site_url' => home_url( '/' ),
				'nonce'    => $nonce,
				'email'    => wp_get_current_user()->user_email,
			)
		);

		// Stamp the attempt time so we can render an apology notice if the
		// merchant returns later without having completed the handshake (i.e.
		// no api_key is stored locally yet within the next 24 hours).
		update_option( 'xpay_wc_last_connect_attempt', time() );

		$ajax_url = esc_url( admin_url( 'admin-ajax.php' ) );
		echo '<div class="card" style="padding:20px;max-width:680px;">';
		echo '<h2>' . esc_html__( 'Connect your store', 'agentic-commerce-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Connect this WooCommerce store to xpay. We provision a public agent-readable catalog feed, publish your /llms.txt + schema.org JSON-LD + AI-bot robots.txt allowlist (the real AI shopping standards), expose ACP / UCP / AP2 / MCP endpoints on xpay infra, and enable cart deeplinks from ChatGPT, Claude, Gemini, and Perplexity.', 'agentic-commerce-for-woocommerce' ) . '</p>';
		echo '<p style="font-size:13px;"><a href="https://docs.xpay.sh/merchants/woocommerce/connecting" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Step-by-step guide with screenshots →', 'agentic-commerce-for-woocommerce' ) . '</a></p>';
		echo '<p><a id="xpay-wc-connect-btn" class="button button-primary button-hero" href="' . esc_url( $onboard_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Connect store →', 'agentic-commerce-for-woocommerce' ) . '</a></p>';
		echo '<p style="color:#646970;font-size:13px;">' . esc_html__( 'No payment processor change. Payouts continue through your existing WooCommerce gateway.', 'agentic-commerce-for-woocommerce' ) . '</p>';
		echo '</div>';
		// Beacon click → admin-ajax (non-blocking, doesn't interfere with the link's target=_blank behaviour).
		echo '<script>(function(){var b=document.getElementById("xpay-wc-connect-btn");if(!b)return;b.addEventListener("click",function(){try{var fd=new FormData();fd.append("action","xpay_wc_track");fd.append("event","connect_clicked");if(navigator.sendBeacon){navigator.sendBeacon(' . wp_json_encode( $ajax_url ) . ',fd);}else{fetch(' . wp_json_encode( $ajax_url ) . ',{method:"POST",body:fd,keepalive:true,credentials:"same-origin"});}}catch(e){}});})();</script>';
	}

	private function render_status_panel( $slug, $last_sync, $audit ) {
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
		echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin-post.php?action=xpay_wc_audit&_wpnonce=' . wp_create_nonce( 'xpay_wc_audit' ) ) ) . '">' . esc_html__( 'Re-run my audit', 'agentic-commerce-for-woocommerce' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin-post.php?action=xpay_wc_disconnect&_wpnonce=' . wp_create_nonce( 'xpay_wc_disconnect' ) ) ) . '">' . esc_html__( 'Disconnect', 'agentic-commerce-for-woocommerce' ) . '</a>';
		echo '</p>';
		echo '</div>';
	}

	public function handle_telemetry_toggle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'agentic-commerce-for-woocommerce' ) );
		}
		check_admin_referer( 'xpay_wc_telemetry' );
		$choice = isset( $_GET['choice'] ) && 'yes' === $_GET['choice'] ? 'yes' : 'no';
		Xpay_Telemetry::set_opt_in( $choice );
		wp_safe_redirect( admin_url( 'options-general.php?page=agentic-commerce-for-woocommerce' ) );
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

		$url = wp_nonce_url( admin_url( 'admin-post.php?action=xpay_wc_telemetry&choice=' . $toggle_choice ), 'xpay_wc_telemetry' );
		echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html( $toggle_label ) . '</a></p>';
	}

	/**
	 * Mirrors the eight checks from scripts/seller-audit/audit.py so the merchant
	 * can see, in their own dashboard, which audit boxes the plugin is satisfying.
	 */
	private function render_readiness_checklist() {
		$slug = Xpay_Plugin::merchant_slug();
		$site = home_url( '/' );

		$rows = array(
			array( 'AI can read your full catalogue', (bool) $slug, $slug ? sprintf( 'Hosted feed live at agent-feed.xpay.sh/catalog/%s.json', $slug ) : 'Connect store to enable.' ),
			array( 'Live prices visible to AI', true, 'JSON-LD Product / Offer schema injected on product pages.' ),
			array( 'Plain-text guide for AI assistants', true, 'Served at /llms.txt.' ),
			array( 'AI assistants know where to send a buyer', (bool) $slug, $slug ? 'Per-protocol endpoints (ACP / UCP / AP2 / MCP) advertised in /llms.txt and hosted on xpay infra.' : 'Connect store to populate endpoints.' ),
			array( 'AI shoppers are allowed in', ! Xpay_Robots::physical_robots_exists(), Xpay_Robots::physical_robots_exists() ? 'Physical robots.txt detected — needs manual fix.' : 'GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot allowed in robots.txt.' ),
			array( 'Direct buy link signals', true, 'BuyAction emitted on every product page.' ),
			array( 'AI shoppers can buy without leaving the chat', (bool) $slug, $slug ? 'Cart deeplink handler active.' : 'Connect store to enable.' ),
			array( 'Stock & price kept current', (bool) $slug, $slug ? 'Webhook resync on product/stock change.' : 'Connect store to enable.' ),
		);

		echo '<h2 style="margin-top:32px;">' . esc_html__( 'Audit readiness', 'agentic-commerce-for-woocommerce' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:880px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Check', 'agentic-commerce-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'agentic-commerce-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Detail', 'agentic-commerce-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			list( $label, $pass, $detail ) = $r;
			$badge                         = $pass
				? '<span style="color:#15803d;font-weight:600;">✓ Ready</span>'
				: '<span style="color:#b91c1c;font-weight:600;">⚠ Pending</span>';
			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . wp_kses_post( $badge ) . '</td>';
			echo '<td>' . esc_html( $detail ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
