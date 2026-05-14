<?php
/**
 * Runs when the plugin is deleted from the WP admin.
 * Removes every option, transient, and rewrite rule we registered.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$option_keys = array(
	'xpay_wc_merchant_slug',
	'xpay_wc_api_key',
	'xpay_wc_site_token',
	'xpay_wc_connected_at',
	'xpay_wc_last_sync_at',
	'xpay_wc_last_audit',
	'xpay_wc_settings',
	'xpay_wc_widget_enabled',
);
foreach ( $option_keys as $key ) {
	delete_option( $key );
}

delete_transient( 'xpay_wc_top_products' );
delete_transient( 'xpay_wc_homepage_itemlist' );

flush_rewrite_rules();
