<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Apurata_Blocks_Support extends AbstractPaymentMethodType
{
	private $gateway;
	protected $name = PLUGIN_ID;

	public function initialize()
	{
		$this->settings = get_option('woocommerce_' . PLUGIN_ID . '_settings', array());
		$this->gateway = new WC_Apurata_Payment_Gateway();
	}

	public function is_active()
	{
		return isset($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
	}

	public function get_supported_features()
	{
		$supports = isset($this->gateway->supports) ? $this->gateway->supports : array('products');
		return array_filter((array) $supports);
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
		$min_amount = null;
		$max_amount = null;
		try {
			$apiContext = $this->gateway->make_curl_to_apurata('GET', '/pos/client/landing_config');
			$config = $apiContext['response_json'] ?: json_decode($apiContext['response_raw']);
			if ($config && isset($config->min_amount) && isset($config->max_amount)) {
				$min_amount = (float) $config->min_amount;
				$max_amount = (float) $config->max_amount;
			}
		} catch (Throwable $e) {
		}

		return array(
			'title' => $this->gateway->title,
			'clientId' => $this->gateway->client_id,
			'icon' => $this->gateway->icon,
			'supports' => $this->get_supported_features(),
			'allowHttp' => isset($this->settings['allow_http']) && $this->settings['allow_http'] === 'yes',
			'requiredCurrency' => 'PEN',
			'minAmount' => $min_amount,
			'maxAmount' => $max_amount,
		);
	}
}
