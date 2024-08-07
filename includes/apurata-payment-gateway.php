<?php
class WC_Apurata_Payment_Gateway extends WC_Payment_Gateway
{

    public function __construct()
    {
        $this->id = PLUGIN_ID;
        $this->title = __('Cuotas sin tarjeta de crédito - aCuotaz', APURATA_TEXT_DOMAIN);
        // Get settings, e.g.
        $this->client_id = $this->get_option('client_id');
        $this->allow_http = $this->get_option('allow_http');
        $this->secret_token = $this->get_option('secret_token');
        $this->is_dark_theme = $this->get_option('is_dark_theme');
        $this->description = <<<EOF
            <div id="apurata-pos-steps"></div>
            <script style="display:none">
                var r = new XMLHttpRequest();
                r.open("GET", "https://apurata.com/pos/{$this->client_id}/info-steps", true);
                r.onreadystatechange = function () {
                    if (r.readyState != 4 || r.status != 200) return;
                    var elem = document.getElementById("apurata-pos-steps");
                    elem.innerHTML = r.responseText;
                };
                r.send();
            </script>
EOF;

        # Eventually this image will change, preserving the URL
        $this->icon = 'https://static.apurata.com/img/logo-dark-aCuotaz.svg';
        $this->has_fields = false;
        // Shown in the admin panel:
        $this->method_title = 'aCuotaz Apurata';
        $this->method_description = __(
            'Evalúa a tus clientes y financia su compra con cuotas quincenales, sin tarjeta de crédito',
            APURATA_TEXT_DOMAIN
        );

        $this->init_form_fields();
        $this->init_settings();

        // Init vars used:
        $this->pay_with_apurata_addon = null;
        $this->landing_config = null;
        try {
            $apurata_session_id = WC()->session->get('apurata_session_id');
            if (!$apurata_session_id) {
                WC()->session->set('apurata_session_id', WC()->session->get_customer_id());
            }
            $this->session_id = WC()->session->get('apurata_session_id');
        } catch (Throwable $e) {
            apurata_log('Error:can not get session_id');
        }
    }

    public function init_hooks()
    {
        add_action('before_woocommerce_init', function () {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
            }
        });
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_on_new_event_from_apurata', array($this, 'on_new_event_from_apurata'));
    }

    public function gen_pay_with_apurata_html($page, $loan_amount = null, $variable_price = false)
    {
        if ($this->pay_with_apurata_addon) {
            return;
        }
        if (!$loan_amount) {
            $loan_amount = $this->get_order_total();
        }
        if ($this->should_hide_apurata_gateway(false)) {
            // Don't talk to apurata, the add-on endpoint will run the validations
            return;
        }
        global $woocommerce;
        global $wp;
        global $product;
        global $current_user;

        $url = "/pos/pay-with-apurata-add-on/" . $loan_amount;

        $current_url = add_query_arg($wp->query_vars, home_url($wp->request));
        $number_of_items = $woocommerce->cart->cart_contents_count;
        $dark_theme = ($this->is_dark_theme == '' || $this->is_dark_theme == 'no') ? 'FALSE' : 'TRUE';
        $url = add_query_arg(array(
            'page' => urlencode($page),
            'continue_url' => urlencode($current_url),
            'is_dark_theme' => urlencode($dark_theme)
        ), $url);
        if ($this->session_id) {
            $url = add_query_arg(array(
                'user__session_id' => urlencode((string) $this->session_id)
            ), $url);
        }
        if ($page == 'cart') {
            if ($number_of_items > 1)
                $url = add_query_arg(array('multiple_products' => urlencode('TRUE'),), $url);
            $products = $woocommerce->cart->get_cart();
            $string_array = '[';
            $i = 0;
            foreach ($products as $item => $_product) {
                $_product = $_product['data'];
                if ($i > 0)
                    $string_array .= ',';
                $string_array .= sprintf('{"id":"%s","name":"%s","amount":%s}', $_product->get_id(), $_product->get_title(), $_product->get_price());
                $i++;
            }
            $url = add_query_arg(array(
                'products'   => urlencode($string_array . ']')
            ), $url);
        }
        if ($page == 'product') {
            if ($product) {
                $url = add_query_arg(array(
                    'product__id'   => urlencode($product->get_id()),
                    'product__name' => urlencode($product->get_title()),
                ), $url);
            }
            $url = add_query_arg(array(
                'variable_amount' => $variable_price,
            ), $url);
        }
        if ($current_user) {
            $url = add_query_arg(array(
                'user__id'         => urlencode((string) $current_user->ID),
                'user__email'      => urlencode((string) $current_user->user_email),
                'user__first_name' => urlencode((string) $current_user->first_name),
                'user__last_name'  => urlencode((string) $current_user->last_name),
            ), $url);
        }
        list($resp_code, $this->pay_with_apurata_addon) = $this->make_curl_to_apurata('GET', $url);

        if ($resp_code == 200) {
            $this->pay_with_apurata_addon = str_replace(array("\r", "\n"), '', $this->pay_with_apurata_addon);
        } else {
            $this->pay_with_apurata_addon = '';
        }
        echo ($this->pay_with_apurata_addon);
    }

    public function make_curl_to_apurata($method, $path, $data = null, $fire_and_forget = false)
    {
        // $method: "GET" or "POST"
        // $path: e.g. /pos/client/landing_config
        // If data is present, send it via JSON
        global $APURATA_API_DOMAIN;
        $ch = curl_init();
        $url = $APURATA_API_DOMAIN . $path;
        curl_setopt($ch, CURLOPT_URL, $url);
        // Timeouts
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);    // seconds
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); // seconds

        $headers = array('Authorization: Bearer ' . $this->secret_token);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (strtoupper($method) == "GET") {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else if (strtoupper($method) == "POST") {
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            throw new Exception('Method not supported: ' . $method);
        }

        if ($data) {
            $payload = json_encode($data);
            // Attach encoded JSON string to the POST fields
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            // Set the content type to application/json
            array_push($headers, 'Content-Type:application/json');
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($fire_and_forget) {
            // From: https://www.xspdf.com/resolution/52447753.html
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            // We don't use CURLOPT_TIMEOUT_MS because the name resolution fails and the
            // whole request never goes out
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        }

        $ret = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode != 200) {
            apurata_log('Apurata responded with http_code ' . $httpCode . ' on ' . $method . ' to ' . $url);
        }
        curl_close($ch);
        return array($httpCode, $ret);
    }

    private function get_landing_config()
    {
        if (!$this->landing_config) {
            list($httpCode, $landing_config) = $this->make_curl_to_apurata('GET', '/pos/client/landing_config');
            $landing_config = json_decode($landing_config);
            $this->landing_config = $landing_config;
        }
        return $this->landing_config;
    }

    private function should_hide_apurata_gateway($talkToApurata)
    {
        /* Hide Apurata gateway based on some conditions. */
        $currency = get_woocommerce_currency();

        // See https://www.designcise.com/web/tutorial/how-to-check-for-https-request-in-php
        if (isset($_SERVER['HTTPS']))
            $isHttps = $_SERVER['HTTPS'];
        else if (isset($_SERVER['REQUEST_SCHEME']))
            $isHttps = $_SERVER['REQUEST_SCHEME'];
        else if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']))
            $isHttps = $_SERVER['HTTP_X_FORWARDED_PROTO'];
        else
            $isHttps = null;
        /* Up to php7.2
        $isHttps =
            $_SERVER['HTTPS']
            ?? $_SERVER['REQUEST_SCHEME']
            ?? $_SERVER['HTTP_X_FORWARDED_PROTO']
            ?? null
        ;
        */
        $isHttps =
            $isHttps && (
                strcasecmp('on', $isHttps) == 0
                || strcasecmp('https', $isHttps) == 0
            );

        if ($this->allow_http == 'no' && !$isHttps) {
            apurata_log('Apurata solo soporta https');
            return true;
        }
        if ($currency != 'PEN') {
            //disable gateway paypal if currency is ABC
            apurata_log('Apurata sólo soporta currency=PEN. Currency actual=' . $currency);
            return true;
        }

        if (!$talkToApurata) {
            // The following checks require to talk to Apurata
            return false;
        }

        $landing_config = $this->get_landing_config();
        $order_total = $this->get_order_total();
        if ($order_total > 0 && ($landing_config->min_amount > $order_total || $landing_config->max_amount < $order_total)) {
            global $APURATA_API_DOMAIN;
            apurata_log('Apurata (' . $APURATA_API_DOMAIN . ') no financia el monto del carrito: ' . $order_total);
            return true;
        }
        return false;
    }

    public function is_available()
    {
        return !$this->should_hide_apurata_gateway(true);
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Habilitar', APURATA_TEXT_DOMAIN) . '/' . __('Deshabilitar', APURATA_TEXT_DOMAIN),
                'type'    => 'checkbox',
                'label'   => __('Habilitar aCuotaz Apurata', APURATA_TEXT_DOMAIN),
                'default' => 'yes'
            ),
            'allow_http' => array(
                'title'   => __('Habilitar HTTP', APURATA_TEXT_DOMAIN),
                'type'    => 'checkbox',
                'label'   => __('Habilitar HTTP (no seguro)', APURATA_TEXT_DOMAIN),
                'default' => 'no'
            ),
            'is_dark_theme' => array(
                'title'   => __('Tema oscuro', APURATA_TEXT_DOMAIN),
                'type'    => 'checkbox',
                'label'   => __('Activar en temas de fondo oscuro', APURATA_TEXT_DOMAIN),
                'default' => 'no'
            ),
            'client_id' => array(
                'title'       => __('ID de Cliente', APURATA_TEXT_DOMAIN),
                'type'        => 'text',
                'required'    => true,
                'description' => __(
                    'Para obtener este ID comunícate con nosotros al correo merchants@apurata.com',
                    APURATA_TEXT_DOMAIN
                ),
                'default'     => ''
            ),
            'secret_token' => array(
                'title'       => __('Token Secreto', APURATA_TEXT_DOMAIN),
                'type'        => 'text',
                'required'    => true,
                'description' => __(
                    'Para obtener este Token comunícate con nosotros al correo merchants@apurata.com',
                    APURATA_TEXT_DOMAIN
                ),
                'default'     => ''
            ),
        );
    }

    public function process_payment($order_id)
    {
        global $APURATA_DOMAIN;
        $order = wc_get_order($order_id);

        $redirect_url = $APURATA_DOMAIN . '/pos/crear-orden-y-continuar';

        $redirect_url = add_query_arg(array(
            'order_id' => urlencode($order->get_id()),
            'pos_client_id' => urlencode($this->client_id),
            'amount' => urlencode($order->get_total()),
            'url_redir_on_canceled' => urlencode(wc_get_checkout_url()),
            'url_redir_on_rejected' => urlencode(wc_get_checkout_url()),
            'url_redir_on_success' => urlencode($this->get_return_url($order)),
            'customer_data__customer_id' => urlencode($order->get_customer_id()),
            'customer_data__billing_company' => urlencode($order->get_billing_company()),
            'customer_data__shipping_company' => urlencode($order->get_shipping_company()),
            'customer_data__email' => urlencode($order->get_billing_email()),
            'customer_data__phone' => urlencode($order->get_billing_phone()),
            'customer_data__billing_address_1' => urlencode($order->get_billing_address_1()),
            'customer_data__billing_address_2' => urlencode($order->get_billing_address_2()),
            'customer_data__billing_first_name' => urlencode($order->get_billing_first_name()),
            'customer_data__billing_last_name' => urlencode($order->get_billing_last_name()),
            'customer_data__billing_city' => urlencode($order->get_billing_city()),
            'customer_data__shipping_address_1' => urlencode($order->get_shipping_address_1()),
            'customer_data__shipping_address_2' => urlencode($order->get_shipping_address_2()),
            'customer_data__shipping_first_name' => urlencode($order->get_shipping_first_name()),
            'customer_data__shipping_last_name' => urlencode($order->get_shipping_last_name()),
            'customer_data__shipping_city' => urlencode($order->get_shipping_city()),
            'customer_data__session_id' => urlencode($this->session_id),
        ), $redirect_url);

        // Add dni if it exists
        $dni = WC()->checkout->get_value($this->get_dni_field_id());
        if ($dni) {
            $redirect_url = add_query_arg(array(
                'customer_data__dni' => urlencode($dni),
            ), $redirect_url);
        }

        // Add last_name_m if it exists
        $last_name_m = get_post_meta($order->get_id(), "billing_apmaterno", true);
        if ($last_name_m) {
            $redirect_url = add_query_arg(array(
                'customer_data__billing_last_name_m' => urlencode($last_name_m),
            ), $redirect_url);
        }

        // Return thankyou redirect
        return array(
            'result'   => 'success',
            'redirect' => $redirect_url
        );
    }

    public function on_new_event_from_apurata()
    {
        global $woocommerce;

        // We need a way to debug execution in ecommerce side
        // We always must write the header Apurata-Log before return
        $log = 'Start;';
        $order_id = intval($_GET['order_id']);
        $event = $_GET['event'];
        $force_change = strtolower($_GET['force_change']) === 'true' ? true : false;
        $agent = $_GET['agent'];
        $order = wc_get_order($order_id);
        $conditions = array('pending', 'onhold', 'failed');
        $order_status = $order->get_status();
        if (!$order) {
            apurata_log('Orden no encontrada: ' . $order_id);
            $log = $log . 'Order not found;';
            header('Apurata-Log: ' . $log);
            http_response_code(404);
            return;
        }

        // Check Authorization
        $auth = getallheaders()['Apurata-Auth'];
        if (!$auth) {
            apurata_log('Missing authorization header');
            $log = $log . "Missing authorization header;";
            header('Apurata-Log: ' . $log);
            http_response_code(401);
            return;
        }
        list($auth_type, $token) = explode(' ', $auth);
        if (strtolower($auth_type) != 'bearer') {
            apurata_log('Invalid authorization type');
            $log = $log . "Invalid authorization type;";
            header('Apurata-Log: ' . $log);
            http_response_code(401);
            return;
        }
        if ($token != $this->secret_token) {
            apurata_log('Invalid authorization token');
            $log = $log . "Invalid authorization token;";
            header('Apurata-Log: ' . $log);
            http_response_code(401);
            return;
        }
        if (!$force_change && !in_array($order_status, $conditions)) {
            apurata_log("Orden en estado {$order_status} no puede ser procesada");
            $log = $log . "Order in status {$order_status} cannot be processed;";
            header('Apurata-Log: ' . $log);
            header('order_status:' . $order_status);
            http_response_code(400);
            return;
        }
        $log = $log . "Success auth;";
        if ($force_change) {
            $order->add_order_note(__($agent . ' aprobó cambio de estado a Procesando', APURATA_TEXT_DOMAIN));
        }
        switch ($event) {
            case 'onhold':
                // Collateral effect: empty cart and don't allow to choose a different payment method
                $order->update_status('on-hold', __('aCuotaz puso la orden en onhold', APURATA_TEXT_DOMAIN));
                break;
            case 'validated':
                $order->add_order_note(__('aCuotaz: Validó identidad del usuario', APURATA_TEXT_DOMAIN));
                break;
            case 'rejected':
                $order->update_status('failed', __('aCuotaz: No aprobó el financiamiento', APURATA_TEXT_DOMAIN));
                break;
            case 'canceled':
                $order->update_status('failed', __('aCuotaz: Anuló el financiamiento.', APURATA_TEXT_DOMAIN));
                break;
            case 'funded':
                if ($_GET["transaction_id"]) {
                    $msg = __(
                        'aCuotaz: Notifica que esta orden fue pagada y ya se puede entregar con transaction_id='
                            . $_GET["transaction_id"],
                        APURATA_TEXT_DOMAIN
                    );
                    add_post_meta($order->get_id(), 'acuotaz_transaction_id', $_GET["transaction_id"], true);
                } else {
                    $msg = __('aCuotaz: Notifica que esta orden fue pagada y ya se puede entregar', APURATA_TEXT_DOMAIN);
                }
                $order->update_status('processing', $msg);
                break;
            case 'approved':
                $order->add_order_note(__('aCuotaz: Calificó el financiamiento (Todavia no entregar producto)', APURATA_TEXT_DOMAIN));
                apurata_log('Evento ignorado: ' . $event);
                $log = $log . 'Ignored event ' . $event . ';';
                break;
            default:
                $order->add_order_note(__('aCuotaz: Ignoró el siguiente evento -> ' . $event, APURATA_TEXT_DOMAIN));
                apurata_log('Evento ignorado: ' . $event);
                $log = $log . 'Ignored event ' . $event . ';';
                break;
        }
        $log = $log . 'Done;';
        header('Apurata-Log: ' . $log);
        http_response_code(200);
    }

    public function get_dni_field_id()
    {
        foreach (WC()->checkout()->get_checkout_fields()["billing"] as $key => $value) {
            if (strpos(strtolower($key), 'dni') !== false) {
                return $key;
            }
            if (strpos(strtolower($value["label"]), 'dni') !== false) {
                return $key;
            }
            if (strpos(strtolower($value["placeholder"]), 'dni') !== false) {
                return $key;
            }
        }
    }
}
