<?php
/**
 * Version:           0.2.0
 * Plugin Name:       WooCommerce aCuotaz Apurata Payment Gateway
 * Plugin URI:        https://github.com/apurata/woocommerce-apurata-payment-gateway
 * Description:       Finance your purchases with a quick aCuotaz Apurata loan.
 * Requires at least: 5.3.2
 * Requires PHP:      7.0.25
 * Author:            Apurata
 * Author URI:        https://apurata.com/app
 * License:           GPL3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       woocommerce-apurata-payment-gateway
 *
 * WC requires at least: 3.8.1
 * WC tested up to: 4.5.1
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );
const APURATA_TEXT_DOMAIN = 'woocommerce-apurata-payment-gateway';
const PLUGIN_ID = 'apurata';

// DEVELOPMENT ONLY: UNCOMMENT TO DISPLAY ERRORS IN HTML
// $OLD_ERROR_REPORTING_LEVEL = error_reporting(E_ALL | E_NOTICE | -1);
// $OLD_DISPLAY_ERRORS = ini_set('display_errors', 'stdout');

// Domain to use in browser
$APURATA_DOMAIN = getenv('APURATA_DOMAIN');
// Domain to use in API calls.
// In docker, there is a special IP for the host network.
// We use this IP to access the local apurata server from inside the container.
$APURATA_API_DOMAIN = getenv('APURATA_API_DOMAIN');

if ($APURATA_DOMAIN == false) {
    $APURATA_DOMAIN = 'https://apurata.com';
}
if ($APURATA_API_DOMAIN == false) {
    $APURATA_API_DOMAIN = $APURATA_DOMAIN;
}
if (!defined('WC_APURATA_BASENAME')) {
    define('WC_APURATA_BASENAME', plugin_basename(__FILE__));
}

// Check if WooCommerce is active
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    add_action('plugins_loaded', 'init_apurata');
    function apurata_log($message) {
        console_log("apurata_log".$message);
        if (getenv('APURATA_DEBUG')) {
            error_log($message);
        }
    }
}
function init_apurata() {
    include_once(plugin_dir_path(__FILE__) . 'apurata-external-hooks.php');
    include_once(plugin_dir_path(__FILE__) . 'apurata-payment-gateway.php');
    include_once(plugin_dir_path(__FILE__) . 'apurata-api.php');
    include_once(plugin_dir_path(__FILE__) . 'apurata-update.php');

    $WC_apurata_external_hooks  = new WC_Apurata_External_Hooks();
    $WC_apurata_external_hooks->init_hooks();

    $WC_apurata_API = new WC_Apurata_API();
    $WC_apurata_API->init_hooks();

    $WC_apurata_payment_gateway = new WC_Apurata_Payment_Gateway();
    $WC_apurata_payment_gateway->init_hooks();

    $WC_apurata_update = new WC_Apurata_Update(__FILE__);
    $WC_apurata_update->set_username('apurata');
    $WC_apurata_update->set_repository('woocommerce-acuotaz-payment-gateway');
    $WC_apurata_update->set_repository_id('282327960');
    $WC_apurata_update->initialize();

}
?>
