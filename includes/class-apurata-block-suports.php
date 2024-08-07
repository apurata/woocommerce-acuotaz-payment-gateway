<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Apurata_Blocks_Support extends AbstractPaymentMethodType
{
	private $gateway;
	protected $name = PLUGIN_ID;

	public function initialize()
	{
		$this->settings = get_option("woocommerce_" . PLUGIN_ID . "_settings", array());
		$this->gateway = new WC_Apurata_Payment_Gateway();
	}

	public function is_active()
	{
		return $this->gateway->is_available();
	}

	public function get_payment_method_script_handles()
	{
		wp_register_script(
			'apurata-blocks-integration',
			plugin_dir_url(__DIR__) . 'src/index.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
			),
			null,
			true
		);

		return array('apurata-blocks-integration');
	}

	public function get_payment_method_data()
	{
		return array(
			'title'        => $this->gateway->title,
			'description'  => $this->gateway->description,
			'canMakePayment' => $this->gateway->is_available(),
			'clientId' => $this->gateway->client_id,
			'icon' => $this->gateway->icon,
		);
	}
}
