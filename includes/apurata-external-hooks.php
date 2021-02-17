<?php
class WC_Apurata_External_Hooks{
    public function __construct($file) {
        $this->file = $file;
    }
    public function init_hooks() {
        add_filter('plugin_action_links_' . WC_APURATA_BASENAME, array($this,'wc_apurata_add_settings_link_on_plugin_page'));
        // Display on each shop item "Págalo en cuotas con Apurata"
        // See: https://www.businessbloomer.com/woocommerce-visual-hook-guide-single-product-page/#more-19252
        add_action('woocommerce_before_add_to_cart_form', array($this, 'on_every_product'));
        // See: https://www.businessbloomer.com/woocommerce-visual-hook-guide-cart-page/#more-19167
        add_action('woocommerce_proceed_to_checkout', array($this ,'on_proceed_to_checkout'), 15);
        // See: https://www.businessbloomer.com/woocommerce-visual-hook-guide-checkout-page/
        add_action('woocommerce_review_order_before_payment', array($this, 'on_proceed_to_checkout'), 15);
        add_filter('woocommerce_payment_gateways', array($this, 'add_wc_apurata_payment_gateway'));
        add_action('wp_head', array($this, 'add_apurata_script'));
    }

    private function update_pos_client_context() {
        if (is_admin()) {
            global $wp_version;
            if(!function_exists('get_plugin_data')) {
                require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }
            $apurata_gateway = new WC_Apurata_Payment_Gateway();
            $url = "/pos/client/" . $apurata_gateway->client_id . "/context";
            $apurata_gateway->make_curl_to_apurata("POST", $url, array(
                "php_version"         => PHP_VERSION,
                "wordpress_version"   => $wp_version,
                "woocommerce_version" => WC_VERSION,
                "wc_acuotaz_version"  => get_plugin_data($this->file)['Version'],
            ), TRUE);
        }
    }

    public function wc_apurata_add_settings_link_on_plugin_page($links) {
        try {
            $this->update_pos_client_context();
        } catch (SomeException $e) {}
        $plugin_links = array();
        $plugin_links[] = '<a href="'
            . admin_url('admin.php?page=wc-settings&tab=checkout&section='
            . PLUGIN_ID)
            . '">'
            . __('Ajustes', 'woocommerce-mercadopago')
            . '</a>';
        return array_merge($plugin_links, $links);
    }

    public function on_every_product() {
        global $product;
        $apurata_gateway = new WC_Apurata_Payment_Gateway();
        if ($product->is_type('variable')) {
            // Has different prices
            $available_variations = $product->get_available_variations();
            $min_variaton_sale_price = $product->get_variation_sale_price('min', true);
            $single_price = $min_variaton_sale_price == $product->get_variation_sale_price('max', true);
            if ( count($available_variations) == 1 or $single_price) {
                $apurata_gateway->gen_pay_with_apurata_html('product', $product->get_price());
            }
            else {
                $apurata_gateway->gen_pay_with_apurata_html('product', $min_variaton_sale_price, TRUE);
            }
        } else {
            $apurata_gateway->gen_pay_with_apurata_html('product', $product->get_price());
        }
    }

    public function on_proceed_to_checkout() {
        $apurata_gateway = new WC_Apurata_Payment_Gateway();
        $apurata_gateway->gen_pay_with_apurata_html('cart');
    }

    public function add_wc_apurata_payment_gateway($methods) {
        $methods[] = 'WC_Apurata_Payment_Gateway';
        return $methods;
    }

    public function add_apurata_script(){
        $path = '/vendor/pixels/apurata-pixel.txt';
        $request_uri = APURATA_STATIC_DOMAIN . $path;
        $response = wp_remote_get($request_uri, array('timeout'=>2));
        $httpCode = wp_remote_retrieve_response_code($response);
        if ($httpCode != 200) {
            apurata_log(sprintf('Apurata responded with http_code %s', $httpCode));
        }
        else{
            echo $response['body'];
        }
    }
}
?>
