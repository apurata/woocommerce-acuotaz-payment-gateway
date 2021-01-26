<?php
class WC_Apurata_API {

    public function init_hooks() {
        add_action('rest_api_init', array($this, 'register_apurata_route'));
    }

    public function register_apurata_route() {
        $WC_apurata_API = new WC_Apurata_API();
        register_rest_route('apurata', '/order-state/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($WC_apurata_API,'send_state_func'),
            'permission_callback' => '__return_true'
        ));
    }
    public function send_state_func(WP_REST_Request $data) {
        $order_id = $data['id'];
        $order = wc_get_order($order_id);
        $auth = $data->get_header('Apurata-Auth');
        if (!$auth) {
            return new WP_Error('authorization_required', 'Missing authorization header', array('status' => 401));
        }
        list($auth_type, $token) = explode(' ', $auth);
        if (strtolower($auth_type) != 'bearer') {
            return new WP_Error('authorization_required', 'Invalid authorization type', array('status' => 401));
        }
        $apurata_gateway = new WC_Apurata_Payment_Gateway();
        if ($token != $apurata_gateway->secret_token) {
            return new WP_Error('authorization_required', 'Invalid authorization token', array('status' => 401 ));
        }
        if (!$order) {
            return new WP_Error('not_found', 'Order: ' . $order_id . ' not found', array('status' => 404 ));
        }
        $order_status = $order->get_status();
        switch ($order_status) {
            case 'cancelled':
            case 'failed':
            case 'refunded':
                $order_status = 'cancelled';
                break;
            case 'completed':
            case 'processing':
                $order_status = 'paid';
                break;
            default:
                $order_status = 'pending';
        }
        $order = wc_get_order($order_id);
        $items = $order->get_items();
        $data = [
            'order_status' => $order_status,
            'enough_stock' => 'TRUE',
        ];
        foreach ($items as $item) {
            $product = $item->get_product();
            $stock_quantity = $product->get_stock_quantity();
            if($stock_quantity && $stock_quantity < $item->get_quantity() || !$product->is_in_stock()) {
                $data['enough_stock'] = 'FALSE';
            }
        }
        return new WP_REST_Response($data, 200);
    }
}

?>