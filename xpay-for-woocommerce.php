<?php
/**
 * Plugin Name:       xpay for WooCommerce
 * Plugin URI:        https://xpay.sh/sellers/woocommerce
 * Description:       Puts your WooCommerce catalog inside ChatGPT, Claude, Gemini, and Perplexity. Live prices, live stock, agent checkout that deep-links into your existing cart. No theme changes, no replatforming, no new payment processor.
 * Version:           0.1.3
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 * Author:            xpay
 * Author URI:        https://xpay.sh
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       xpay-for-woocommerce
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'XPAY_WC_VERSION', '0.1.3' );
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
