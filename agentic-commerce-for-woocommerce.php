<?php
/**
 * Plugin Name:       Agentic Commerce for WooCommerce
 * Plugin URI:        https://www.xpay.sh/merchants/woocommerce/
 * Description:       Make your WooCommerce store visible to ChatGPT, Claude, Gemini and Perplexity — buyers complete checkout on your existing WooCommerce gateway. No theme changes, no replatforming, no new payment processor. By xpay.
 * Version:           0.2.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 * Author:            xpay
 * Author URI:        https://www.xpay.sh
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agentic-commerce-for-woocommerce
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'XPAY_WC_VERSION', '0.2.1' );
define( 'XPAY_WC_FILE', __FILE__ );
define( 'XPAY_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'XPAY_WC_URL', plugin_dir_url( __FILE__ ) );
define( 'XPAY_WC_API_BASE', defined( 'XPAY_WC_API_BASE_OVERRIDE' ) ? XPAY_WC_API_BASE_OVERRIDE : 'https://agent-commerce.xpay.sh' );
define( 'XPAY_WC_AGENT_COMMERCE_BASE', defined( 'XPAY_WC_AGENT_COMMERCE_OVERRIDE' ) ? XPAY_WC_AGENT_COMMERCE_OVERRIDE : 'https://agent-commerce.xpay.sh' );
define( 'XPAY_WC_ONBOARD_URL', defined( 'XPAY_WC_ONBOARD_URL_OVERRIDE' ) ? XPAY_WC_ONBOARD_URL_OVERRIDE : 'https://app.xpay.sh/onboard/woocommerce' );

require_once XPAY_WC_PATH . 'includes/class-xpay-plugin.php';

register_activation_hook( __FILE__, array( 'Xpay_Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'Xpay_Plugin', 'on_deactivate' ) );

add_action( 'plugins_loaded', array( 'Xpay_Plugin', 'instance' ) );

// Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
// and Cart/Checkout Blocks. We never read or write WC orders directly — orders
// are created natively by the merchant's existing checkout — so HPOS support is
// inherent. This declaration silences the WC "incompatible plugin" admin notice.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);
