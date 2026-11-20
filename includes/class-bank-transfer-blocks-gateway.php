<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'rt-bank-transfer-payment-gateway-utils.php';

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * WooCommerce Blocks integration.
 *
 * BK-08: the block checkout goes through WooCommerce's Store API like every
 * other gateway. Place Order is not intercepted; the Store API validates the
 * checkout (stock, terms, captcha / anti-fraud hooks, this gateway's own
 * validators and consent checkbox) and calls process_payment(), whose
 * redirect sends the shopper to RT's payment page.
 */
class RTBTCH_Blocks_Gateway extends AbstractPaymentMethodType {

	protected $name = RTBTCH_GATEWAY_ID;
	protected $id   = RTBTCH_GATEWAY_ID;

	public function initialize() {
		$this->settings = rtbtch_get_settings();
	}

	public function is_active() {
		if (
			!isset($this->settings['enabled']) ||
			$this->settings['enabled'] !== 'yes'
		) {
			return false;
		}

		$gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
		$gateway  = $gateways[$this->name] ?? null;
		if ($gateway && method_exists($gateway, 'is_available')) {
			return $gateway->is_available();
		}

		return true;
	}

	public function get_payment_method_script_handles() {
		$blocks_js_path = plugin_dir_path(RTBTCH_GATEWAY_FILE) . 'assets/js/rt-bank-transfer-blocks.js';
		$blocks_js_ver  = file_exists($blocks_js_path) ? filemtime($blocks_js_path) : rtbtch_get_plugin_version();

		wp_register_script(
			'rt-bank-transfer-blocks-js',
			plugin_dir_url(RTBTCH_GATEWAY_FILE) . 'assets/js/rt-bank-transfer-blocks.js',
			['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
			$blocks_js_ver,
			true
		);

		return ['rt-bank-transfer-blocks-js'];
	}

	public function get_payment_method_data() {
		$title       = $this->settings['title'] ?? __('Pay by bank transfer', 'rt-bank-transfer-checkout');
		$description = class_exists('RTBTCH_GATEWAY') ? RTBTCH_GATEWAY::clean_description($this->settings['description'] ?? '') : wp_strip_all_tags((string) ($this->settings['description'] ?? ''));

		if (WC()->cart) {
			$amount = (float) WC()->cart->get_total('edit');
			if ($amount < 0.01) {
				$totals = WC()->cart->get_totals();
				$amount = (float) ($totals['total'] ?? 0);
			}
			$gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
			$gateway  = $gateways[$this->name] ?? null;
			if ($gateway && method_exists($gateway, 'get_checkout_info_for_amount')) {
				$info = $gateway->get_checkout_info_for_amount($amount);
				if (!empty($info['title']))    $title       = $info['title'];
				if (!empty($info['subtitle'])) $description = $info['subtitle'];
			}
		}

		$is_active = $this->is_active();
		return [
			'id'                    => $this->name,
			'title'                 => sanitize_text_field($title),
			'description'           => wp_kses_post($description),
			'supports'              => ['products'],
			'plugin_version'        => rtbtch_get_plugin_version(),
			'isActive'              => $is_active,
			'can_pay'               => $is_active,
			'instructions'          => $this->settings['instructions'] ?? '',
			'show_consent_checkbox' => ($this->settings['show_consent_checkbox'] ?? 'no') === 'yes',
			'consent_label'         => __('I consent to the collection of my data to process this payment', 'rt-bank-transfer-checkout'),
			'consent_nonce'         => wp_create_nonce('rtbtch_payment'),
			'redirect_notice'       => __('After you place the order, you will go to RT\'s secure page to connect your bank and approve the payment.', 'rt-bank-transfer-checkout'),
		];
	}
}
