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
        $this->sentry_dsn = $this->get_option('sentry_dsn');
        $this->description = '<div id="apurata-pos-steps"></div>';

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
            if (WC() && WC()->session) {
                $apurata_session_id = WC()->session->get('apurata_session_id');
                if (!$apurata_session_id) {
                    WC()->session->set('apurata_session_id', WC()->session->get_customer_id());
                }
                $this->session_id = WC()->session->get('apurata_session_id');
            } else {
                $this->session_id = '';
            }
        } catch (Throwable $e) {
            apurata_log('Error:can not get session_id');
            $this->session_id = '';
        }
    }

    public function show_payment_mocker_by_js_script() {
        if (is_checkout() && !wp_doing_ajax()) { // Prevents script output during AJAX requests to avoid header issues
            ?>
            <script>
                var r = new XMLHttpRequest();
                r.open("GET", "https://apurata.com/pos/<?php echo $this->client_id; ?>/info-steps", true);
                r.onreadystatechange = function () {
                    if (r.readyState != 4 || r.status != 200) return;
                        var elem = document.getElementById("apurata-pos-steps");
                    if (elem) {
                        elem.innerHTML = r.responseText;
                    }
                };
                r.send();
            </script>
            <?php
        }
    }

    // Only handle aCuotaz payment image when method is selected
    public function add_payment_selection_handler() {
        if (is_checkout()) {
            static $script_added = false;
            if (!$script_added) {
                $script_added = true;
                ?>
                <script>
                jQuery(function($) {
                    function load() {
                        var e = document.getElementById("apurata-pos-steps");
                        if (e && $('input[name="payment_method"]:checked').val() === 'apurata') {
                            var r = new XMLHttpRequest();
                            r.open("GET", "https://apurata.com/pos/<?php echo $this->client_id; ?>/info-steps", true);
                            r.onreadystatechange = function () { if (r.readyState == 4 && r.status == 200) e.innerHTML = r.responseText; };
                            r.send();
                        }
                    }
                    $(document).on('change click wfacp_payment_method_changed updated_checkout', 'input[name="payment_method"], body', function() { setTimeout(load, 100); });
                    setTimeout(load, 500);
                });
                </script>
                <?php
            }
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
        add_action('woocommerce_checkout_init', array($this, 'show_payment_mocker_by_js_script'));
        add_action('wp_footer', array($this, 'add_payment_selection_handler')); // Works during AJAX
        add_action('woocommerce_api_on_new_event_from_apurata', array($this, 'on_new_event_from_apurata'));
    }

    public function gen_pay_with_apurata_html($page, $loan_amount = null, $variable_price = false)
    {
        if ($this->pay_with_apurata_addon) {
            return;
        }
        
        $cart = WC()->cart;
        $cart_exists = $cart && is_object($cart);
        
        if (!$loan_amount) {
            $loan_amount = $cart_exists ? $cart->total : 0;
        }
        if ($this->should_hide_apurata_gateway(false)) {
            // Don't talk to apurata, the add-on endpoint will run the validations
            return;
        }
        
        global $wp;
        global $product;
        global $current_user;

        $url = "/pos/pay-with-apurata-add-on/" . $loan_amount;

        $current_url = add_query_arg($wp->query_vars, home_url($wp->request));
        
        $number_of_items = $cart_exists ? $cart->cart_contents_count : 0;
        
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
            $products = $cart_exists ? $cart->get_cart() : [];
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
        $apiContext = $this->make_curl_to_apurata('GET', $url);

        if ($apiContext['http_code'] == 200) {
            $this->pay_with_apurata_addon = str_replace(array("\r", "\n"), '', $apiContext['response_raw']);
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

        $payload = null;
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

        $response = curl_exec($ch);
        
        // Create API context for Sentry reporting
        $apiContext = [
            'http_code'      => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'response_raw'   => $response,
            'response_json'  => json_decode($response),
            'url'            => $url,
            'method'         => $method,
            'request_body'   => $payload,
            'request_headers'=> $headers,
            'curl_error'     => curl_error($ch),
            'curl_errno'     => curl_errno($ch),
        ];
        curl_close($ch);

        if ($apiContext['http_code'] != 200) {
            $message = 'Error in HTTP response from Apurata endpoint';
            apurata_log($message);
            $this->sendToSentry($message, null, $apiContext);
        }

        return $apiContext;
    }

    private function getSentryPayload(string $message, ?\Throwable $exception = null, $apiContext = null): array
    {
        $eventId = bin2hex(random_bytes(16));
        $timestamp = gmdate('Y-m-d\TH:i:s');
        
        $wc_version = defined('WC_VERSION') ? WC_VERSION : 'unknown';
        $wp_version = get_bloginfo('version');

        $payload = [
            'event_id'    => $eventId,
            'timestamp'   => $timestamp,
            'platform'    => 'php',
            'environment' => 'production',
            'level'       => 'error',
            'logger'      => 'apurata-woocommerce',
            'message'     => $message,
            'tags'        => [
                'client_id' => $this->client_id,
            ],
            'server_name' => gethostname() ?: 'unknown',
            'user' => [
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ],
            'contexts' => [
                'platform' => [
                    'php_version'           => PHP_VERSION,
                    'wordpress_version'     => $wp_version,
                    'woocommerce_version'   => $wc_version,
                ],
                'runtime' => [
                    'name' => 'php',
                    'version' => PHP_VERSION,
                ],
                'os' => [
                    'name' => php_uname('s'),
                    'version' => php_uname('r'),
                ],
            ],
        ];

        if ($exception) {
            $payload['exception'] = [[
                'type'  => get_class($exception),
                'value' => $exception->getMessage(),
                'stacktrace' => [
                    'frames' => array_map(function ($frame) {
                        return [
                            'filename' => $frame['file'] ?? '[internal]',
                            'function' => $frame['function'] ?? '[unknown]',
                            'lineno'   => $frame['line'] ?? 0,
                        ];
                    }, array_reverse($exception->getTrace()))
                ]
            ]];
        }

        if ($apiContext) {
            $httpCode = $apiContext['http_code'] ?? 0;
            $payload['tags']['http_status_group'] = floor($httpCode / 100) . 'xx';
            $payload['request'] = [
                'url'     => $apiContext['url'] ?? '',
                'method'  => $apiContext['method'] ?? '',
                'data'    => $apiContext['request_body'] ?? null,
                'headers' => array_map(fn($h) => array_map('trim', explode(':', $h, 2)), $apiContext['request_headers'] ?? []),
            ];
            $payload['contexts']['response'] = [
                'status_code' => $httpCode,
                'body'        => $apiContext['response_json'] ?? $apiContext['response_raw'] ?? '',
            ];
            $payload['contexts']['curl'] = [
                'error'  => $apiContext['curl_error'] ?? '',
                'errno'  => $apiContext['curl_errno'] ?? 0,
            ];
        }

        return $payload;
    }

    public function sendToSentry(string $message, ?\Throwable $exception = null, $apiContext = null): void
    {
        if (empty($this->sentry_dsn) || !$this->sentry_dsn) {
            return;
        }

        $dsn = $this->sentry_dsn;
        $parsed = parse_url($dsn);
        
        if (!$parsed) return;

        $publicKey = $parsed['user'];
        $host = $parsed['host'];
        $projectId = ltrim($parsed['path'], '/');
        $endpoint = "https://{$host}/api/{$projectId}/store/";

        $payload = $this->getSentryPayload($message, $exception, $apiContext);

        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        $plugin_file = WP_PLUGIN_DIR . '/' . WC_APURATA_BASENAME;
        $plugin_version = get_plugin_data($plugin_file)['Version'];

        $headers = [
            'Content-Type: application/json',
            "X-Sentry-Auth: Sentry sentry_version=7, sentry_client=apurata-woocommerce/{$plugin_version}, sentry_key={$publicKey}",
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 2,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function get_landing_config()
    {
        if (!$this->landing_config) {
            $apiContext = $this->make_curl_to_apurata('GET', '/pos/client/landing_config');
            $landing_config = $apiContext['response_json'] ?: json_decode($apiContext['response_raw']);
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
        
        if (!isset(WC()->cart) || WC()->cart === null) {
            // hides the gateway when WC()->cart is null during AJAX updates to prevent critical errors
            return true;
        }

        $landing_config = $this->get_landing_config();
        $order_total = WC()->cart->total;
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
            'sentry_dsn' => array(
                'title'       => __('Sentry DSN', APURATA_TEXT_DOMAIN),
                'type'        => 'text',
                'required'    => false,
                'description' => __(
                    'Habilitar monitoreo de errores para mejorar el soporte y  estabilidad',
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
        $order_status = $order ? $order->get_status() : '';

        if (!$order) {
            apurata_log('Orden no encontrada: ' . $order_id);
            $log = $log . 'Order not found;';
            header('Apurata-Log: ' . $log);
            http_response_code(404);
            return;
        }

        // Check Authorization
        $auth = isset(getallheaders()['Apurata-Auth']) ? getallheaders()['Apurata-Auth'] : '';
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
                if (isset($_GET["transaction_id"]) && $_GET["transaction_id"]) {
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
    	$fields = WC()->checkout()->get_checkout_fields()["billing"] ?? [];
        
        foreach ($fields as $key => $value) {
            if (strpos(strtolower($key), 'dni') !== false) {
                return $key;
            }
            if (isset($value["label"]) && strpos(strtolower($value["label"]), 'dni') !== false) {
                return $key;
            }
            if (isset($value["placeholder"]) && strpos(strtolower($value["placeholder"]), 'dni') !== false) {
                return $key;
            }
        }
        return '';
    }
}
