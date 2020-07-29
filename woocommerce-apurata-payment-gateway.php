<?php
/**
 * Version:           0.0.3
 * Plugin Name:       WooCommerce Apurata Payment Gateway
 * Plugin URI:        https://github.com/apurata/woocommerce-apurata-payment-gateway
 * Description:       Finance your purchases with a quick Apurata loan.
 * Tested up to:      4.2.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Apurata
 * Author URI:        https://app.apurata.com/
 * License:           GPL3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       woocommerce-apurata-payment-gateway
 *
 * WC requires at least: 4.2.0
 * WC tested up to: 4.2.0
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
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

    // Add settings link on plugin page.
    add_action('plugins_loaded', 'woocommerce_mercadopago_init');
    function add_settings_link_on_plugin_page($links)
    {
        $plugin_links = array();
        $plugin_links[] = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=' . PLUGIN_ID) . '">' . __('Ajustes', 'woocommerce-mercadopago') . '</a>';
        return array_merge($plugin_links, $links);
    }
    function woocommerce_mercadopago_init() {
        add_filter('plugin_action_links_' . WC_APURATA_BASENAME, 'add_settings_link_on_plugin_page');
    }
    // End of Add settings link on plugin page.

    function init_wc_apurata_payment_gateway() {
        class WC_Apurata_Payment_Gateway extends WC_Payment_Gateway {

            public function __construct() {
                $this->id = PLUGIN_ID;

                $this->title = __('Cuotas sin tarjeta de crédito - Apurata', APURATA_TEXT_DOMAIN);
                $this->description = <<<EOF
                    <div id="apurata-pos-steps"></div>
                    <script>
                        var r = new XMLHttpRequest();
                        r.open("GET", "https://apurata.com/pos/info-steps", true);
                        r.onreadystatechange = function () {
                          if (r.readyState != 4 || r.status != 200) return;
                          var elem = document.getElementById("apurata-pos-steps");
                          elem.innerHTML = r.responseText;
                        };
                        r.send();
                    </script>
                EOF;

                $this->icon = 'https://static.apurata.com/img/logo-dark.svg';
                $this->has_fields = FALSE;

                // Shown in the admin panel:
                $this->method_title = 'Apurata';
                $this->method_description = __('Evalúa a tus clientes y financia su compra con cuotas quincenales, sin tarjeta de crédito', APURATA_TEXT_DOMAIN);

                $this->init_form_fields();
                $this->init_settings();

                // Get settings, e.g.
                $this->client_id = $this->get_option( 'client_id' );
                $this->allow_http = $this->get_option( 'allow_http' );
                $this->secret_token = $this->get_option( 'secret_token' );

                // Init vars used:
                $this->pay_with_apurata_addon = NULL;
                $this->landing_config = NULL;

                $this->gen_pay_with_apurata_html();

                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

                add_filter( 'woocommerce_available_payment_gateways', array( $this, 'hide_apurata_gateway' ) );
                add_action( 'woocommerce_api_on_new_event_from_apurata', array($this, 'on_new_event_from_apurata') );
            }

            function gen_pay_with_apurata_html() {
                if ($this->pay_with_apurata_addon) {
                    return;
                }

                if ($this->should_hide_apurata_gateway(FALSE)) {
                    return;
                }

                $ch = curl_init();

                global $APURATA_API_DOMAIN;
                $url = $APURATA_API_DOMAIN .
                         '/pos/pay-with-apurata-add-on/' . WC()->cart->total;
                curl_setopt($ch, CURLOPT_URL, $url);

                $headers = array("Authorization: Bearer " . $this->secret_token);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $this->pay_with_apurata_addon = curl_exec($ch);
                $resp_code = curl_getinfo($ch , CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($resp_code == 200) {
                    $this->pay_with_apurata_addon = str_replace(array("\r", "\n"), '', $this->pay_with_apurata_addon);
                    $this->pay_with_apurata_addon = addslashes($this->pay_with_apurata_addon);
                } else {
                    error_log("Apurata responded with code ". $resp_code);
                    $this->pay_with_apurata_addon = '';
                }
                echo(sprintf("<script>window.PAY_WITH_APURATA_ADDON_HTML = '%s';</script>", $this->pay_with_apurata_addon));
                $this->insert_pay_with_apurata_html();
            }

            function insert_pay_with_apurata_html() {
                echo(<<<EOF
                    <!-- Agregar al carro: "Págalo en cuotas con Apurata" -->
                    <script>
                    document.addEventListener("DOMContentLoaded", function(){
                        var apurata_div = document.createElement('div');
                        apurata_div.setAttribute("id", "pay-with-apurata");
                        apurata_div.innerHTML = window.PAY_WITH_APURATA_ADDON_HTML;

                        var ct = document.getElementsByClassName("cart_totals")[0];
                        var st = ct.getElementsByClassName('shop_table')[0];
                        st.parentNode.insertBefore(apurata_div, st.nextSibling);
                    });
                    </script>
                    <!-- Fin de Agregar al carro: "Págalo en cuotas con Apurata" -->
                EOF);
            }

            function get_landing_config() {
                if (!$this->landing_config) {
                    $ch = curl_init();

                    global $APURATA_API_DOMAIN;
                    $url = $APURATA_API_DOMAIN .
                             '/pos/client/landing_config';
                    curl_setopt($ch, CURLOPT_URL, $url);

                    $headers = array("Authorization: Bearer " . $this->secret_token);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                    // $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $landing_config = curl_exec($ch);
                    $landing_config = json_decode($landing_config);
                    curl_close($ch);
                    $this->landing_config = $landing_config;
                }
                return $this->landing_config;
            }

            function should_hide_apurata_gateway(bool $talkToApurata = TRUE) {
                /* Hide Apurata gateway based on some conditions. */
                $currency = get_woocommerce_currency();

                if ($this->allow_http == "no" && $_SERVER['REQUEST_SCHEME'] != 'https') {
                    error_log('Apurata solo soporta https');
                    return TRUE;
                }
                if( $currency != 'PEN' ){
                    //disable gateway paypal if currency is ABC
                    error_log('Apurata sólo soporta currency=PEN. Currency actual=' . $currency);
                    return TRUE;
                }

                if (!$talkToApurata) {
                    // The following checks require to talk to Apurata
                    return FALSE;
                }

                $landing_config = $this->get_landing_config();
                if ($landing_config->min_amount > WC()->cart->total || $landing_config->max_amount < WC()->cart->total) {
                    error_log('Apurata no financia el monto del carrito: ' . WC()->cart->total);
                    return TRUE;
                }

                return FALSE;
            }

            function hide_apurata_gateway( $gateways ) {
                /* Hide Apurata gateway based on some conditions. */
                if ($this->should_hide_apurata_gateway()) {
                    unset( $gateways['apurata'] );
                }
                return $gateways;
            }


            function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array
                    (
                        'title' => __('Habilitar', APURATA_TEXT_DOMAIN) . '/' . __('Deshabilitar', APURATA_TEXT_DOMAIN),
                        'type' => 'checkbox',
                        'label' => __('Habilitar Apurata', APURATA_TEXT_DOMAIN),
                        'default' => 'yes'
                    ),
                    'allow_http' => array
                    (
                        'title' => __('Habilitar HTTP', APURATA_TEXT_DOMAIN),
                        'type' => 'checkbox',
                        'label' => __('Habilitar HTTP (no seguro)', APURATA_TEXT_DOMAIN),
                        'default' => 'no'
                    ),
                    'client_id' => array
                    (
                        'title' => __('ID de Cliente', APURATA_TEXT_DOMAIN),
                        'type' => 'text',
                        'required' => true,
                        'description' => __('Para obtener este ID comunícate con nosotros al correo merchants@apurata.com', APURATA_TEXT_DOMAIN),
                        'default' => ''
                    ),
                    'secret_token' => array
                    (
                        'title' => __('Token Secreto', APURATA_TEXT_DOMAIN),
                        'type' => 'text',
                        'required' => true,
                        'description' => __('Para obtener este Token comunícate con nosotros al correo merchants@apurata.com', APURATA_TEXT_DOMAIN),
                        'default' => ''
                    ),
                );
            }

            function process_payment( $order_id ) {
                global $APURATA_DOMAIN;
                $order = wc_get_order( $order_id );

                $redirect_url = $APURATA_DOMAIN .
                                 '/pos/crear-orden-y-continuar' .
                                 '?order_id=' . urlencode($order->get_id()) .
                                 '&pos_client_id=' . urlencode($this->client_id) .
                                 '&amount=' . urlencode($order->get_total()) .
                                 '&url_redir_on_canceled=' . urlencode(wc_get_checkout_url()) .
                                 '&url_redir_on_rejected=' . urlencode(wc_get_checkout_url()) .
                                 '&url_redir_on_success=' . urlencode($this->get_return_url( $order )) .
                                 '&customer_data__email=' . urlencode($order->get_billing_email()) .
                                 '&customer_data__phone=' . urlencode($order->get_billing_phone()) .
                                 '&customer_data__billing_address_1=' . urlencode($order->get_billing_address_1()) .
                                 '&customer_data__billing_address_2=' . urlencode($order->get_billing_address_2()) .
                                 '&customer_data__billing_first_name=' . urlencode($order->get_billing_first_name()) .
                                 '&customer_data__billing_last_name=' . urlencode($order->get_billing_last_name()) .
                                 '&customer_data__billing_city=' . urlencode($order->get_billing_city()) .
                                 '&customer_data__shipping_address_1=' . urlencode($order->get_shipping_address_1()) .
                                 '&customer_data__shipping_address_2=' . urlencode($order->get_shipping_address_2()) .
                                 '&customer_data__shipping_first_name=' . urlencode($order->get_shipping_first_name()) .
                                 '&customer_data__shipping_last_name=' . urlencode($order->get_shipping_last_name()) .
                                 '&customer_data__shipping_city=' . urlencode($order->get_shipping_city()) ;


                // Return thankyou redirect
                return array(
                    'result' => 'success',
                    'redirect' => $redirect_url
                );
            }

            /* BEGIN OF HOOKS */
            function on_new_event_from_apurata() {
                global $woocommerce;

                $order_id = intval($_GET["order_id"]);
                $event = $_GET["event"];

                $order = wc_get_order( $order_id );

                if (!$order) {
                    error_log('Orden no encontrada: ' . $order_id);
                    return;
                }

                // Check Authorization
                $auth = getallheaders()['Authorization'];
                if (!$auth) {
                    error_log('Missing authorization header');
                    return;
                }
                list($auth_type, $token) = explode(' ', $auth);
                if (strtolower($auth_type) != 'bearer'){
                    error_log('Invalid authorization type');
                    return;
                }
                if ($token != $this->secret_token) {
                    error_log('Invalid authorization token');
                    return;
                }

                if ($event == 'approved' && $order->get_status() == 'pending') {
                    $order->update_status('on-hold', __( 'Apurata aprobó la orden, esperando validación de identidad', APURATA_TEXT_DOMAIN ));
                    $woocommerce->cart->empty_cart();
                } else if ($event == 'validated') {
                    $order->update_status('processing', __( 'Apurata validó identidad', APURATA_TEXT_DOMAIN ));
                } else if ($event == 'rejected') {
                    $order->update_status('failed', __( 'Apurata rechazó la orden', APURATA_TEXT_DOMAIN ));
                } else if ($event == 'canceled') {
                    $order->update_status('failed', __( 'El financiamiento en Apurata fue cancelado', APURATA_TEXT_DOMAIN ));
                } else {
                    error_log('Evento no soportado: ' . $event);
                }
            }
            /* END OF HOOKS */
        }
    }

    add_action( 'plugins_loaded', 'init_wc_apurata_payment_gateway' );

    function add_wc_apurata_payment_gateway( $methods ) {
        $methods[] = 'WC_Apurata_Payment_Gateway';
        return $methods;
    }

    add_filter( 'woocommerce_payment_gateways', 'add_wc_apurata_payment_gateway' );
}

?>
