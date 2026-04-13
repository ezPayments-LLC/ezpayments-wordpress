<?php
/**
 * Plugin Name: ezPayments for WooCommerce
 * Plugin URI: https://github.com/ezPayments-LLC/ezpayments-wordpress
 * Description: Accept payments via ezPayments payment links in your WooCommerce store. Supports test and live modes with automatic webhook registration.
 * Version: 1.1.0
 * Author: ezPayments
 * Author URI: https://ezpayments.co
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ezpayments-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 *
 * @package EzPayments_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'EZPAYMENTS_VERSION', '1.1.0' );
define( 'EZPAYMENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EZPAYMENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EZPAYMENTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active and initialize the plugin.
 */
function ezpayments_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'ezpayments_woocommerce_missing_notice' );
        return;
    }

    // Load plugin classes.
    require_once EZPAYMENTS_PLUGIN_DIR . 'includes/class-ezpayments-api.php';
    require_once EZPAYMENTS_PLUGIN_DIR . 'includes/class-ezpayments-gateway.php';
    require_once EZPAYMENTS_PLUGIN_DIR . 'includes/class-ezpayments-webhook.php';
    require_once EZPAYMENTS_PLUGIN_DIR . 'includes/class-ezpayments-updater.php';

    // Register the payment gateway.
    add_filter( 'woocommerce_payment_gateways', 'ezpayments_add_gateway' );

    // Initialize webhook handler.
    EzPayments_Webhook::init();

    // Initialize auto-updater (checks GitHub releases).
    EzPayments_Updater::init();

    // Add settings link to plugin list.
    add_filter( 'plugin_action_links_' . EZPAYMENTS_PLUGIN_BASENAME, 'ezpayments_plugin_action_links' );
}
add_action( 'plugins_loaded', 'ezpayments_init' );

/**
 * Add the ezPayments gateway to WooCommerce.
 *
 * @param array $gateways Existing gateways.
 * @return array
 */
function ezpayments_add_gateway( $gateways ) {
    $gateways[] = 'WC_Gateway_EzPayments';
    return $gateways;
}

/**
 * Display admin notice if WooCommerce is not active.
 */
function ezpayments_woocommerce_missing_notice() {
    echo '<div class="notice notice-error"><p>' .
         esc_html__( 'ezPayments for WooCommerce requires WooCommerce to be installed and active.', 'ezpayments-woocommerce' ) .
         '</p></div>';
}

/**
 * Add settings link to the plugins page.
 *
 * @param array $links Existing plugin action links.
 * @return array
 */
function ezpayments_plugin_action_links( $links ) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ezpayments' ) ),
        esc_html__( 'Settings', 'ezpayments-woocommerce' )
    );
    array_unshift( $links, $settings_link );
    return $links;
}

/**
 * Cleanup on plugin deactivation.
 */
function ezpayments_deactivate() {
    // Load required files for cleanup.
    if ( ! class_exists( 'EzPayments_API' ) ) {
        require_once EZPAYMENTS_PLUGIN_DIR . 'includes/class-ezpayments-api.php';
    }
    if ( ! class_exists( 'WC_Gateway_EzPayments' ) ) {
        require_once EZPAYMENTS_PLUGIN_DIR . 'includes/class-ezpayments-gateway.php';
    }

    WC_Gateway_EzPayments::cleanup_webhooks();
}
register_deactivation_hook( __FILE__, 'ezpayments_deactivate' );

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
