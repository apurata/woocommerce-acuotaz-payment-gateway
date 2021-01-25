<?php
class WC_Apurata_External_Hooks{
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
        add_action('wp_head', array($this, 'add_facebook_pixel'));
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
                "wc_acuotaz_version"  => get_plugin_data(__FILE__)['Version'],
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

    public function add_facebook_pixel(){
        ?>
        <script>
        !function(f,b,e,v,n,t,s){
            if(f.fbq)return;
            n=f.fbq=function(){
                n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)
            };
            if(!f._fbq)f._fbq=n;
            n.push=n;
            n.loaded=!0;
            n.version='2.0';
            n.queue=[];
            t=b.createElement(e);
            t.async=!0;
            t.src=v;
            s=b.getElementsByTagName(e)[0];
            s.parentNode.insertBefore(t,s)
        }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '181284105708138');
        fbq('track', 'PageView');
        </script>
        <noscript>
            <img
                height="1"
                width="1"
                style="display:none"
                src="https://www.facebook.com/tr?id=181284105708138&ev=PageView&noscript=1"
            />
        </noscript>
        <?php
    }
}
?>
