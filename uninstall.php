<?php
/**
 * Runs when the plugin is deleted from the WP admin.
 * Removes every option, transient, and rewrite rule we registered.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

( function () {
	$xpay_wc_option_keys = array(
		// Core merchant identity + connection state
		'xpay_wc_merchant_slug',
		'xpay_wc_api_key',
		'xpay_wc_site_token',
		'xpay_wc_connected_at',
		'xpay_wc_last_sync_at',
		'xpay_wc_last_audit',
		'xpay_wc_last_connect_attempt',
		'xpay_wc_connect_finalize_nonce',
		'xpay_wc_installed_version',

		// Settings / UI state
		'xpay_wc_settings',
		'xpay_wc_widget_enabled',
		'xpay_wc_links',
		'xpay_wc_payment_map',
		'xpay_wc_protocol_endpoints',

		// Discovery emission toggles
		'xpay_wc_emit_ucp_profile',
		'xpay_wc_emit_oauth_protected_resource',
		'xpay_wc_emit_agent_card',
		'xpay_wc_ucp_profile',
		'xpay_wc_ucp_signing_keys',

		// Telemetry
		'xpay_wc_telemetry_opt_in',
		'xpay_wc_telemetry_decided_at',
		'xpay_wc_telemetry_debug',

		// Agent-discovery analytics buffer
		'xpay_wc_agent_buf',
		'xpay_wc_agent_dropped',
		'xpay_wc_human_daily',

		// Lifecycle
		'xpay_wc_first_activated_at',
		'xpay_wc_flush_rewrites',
	);
	foreach ( $xpay_wc_option_keys as $xpay_wc_key ) {
		delete_option( $xpay_wc_key );
	}

	// Per-capability flags written by Xpay_Settings::handle_save_capabilities()
	// at the key shape `xpay_wc_capability_<cap>` — mirror the canonical list
	// from Xpay_Settings::CAPABILITIES to avoid orphans on delete.
	$xpay_wc_capabilities = array( 'checkout', 'fulfillment', 'discount', 'order' );
	foreach ( $xpay_wc_capabilities as $xpay_wc_cap ) {
		delete_option( 'xpay_wc_capability_' . $xpay_wc_cap );
	}

	delete_transient( 'xpay_wc_top_products' );
	delete_transient( 'xpay_wc_homepage_itemlist' );
	delete_transient( 'xpay_wc_post_activation_redirect' );

	flush_rewrite_rules();
} )();
