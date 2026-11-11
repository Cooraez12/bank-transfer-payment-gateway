<?php
if (!defined('ABSPATH')) {
	exit();
}

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'bank-transfer-payment-gateway-utils.php';
require_once plugin_dir_path(__FILE__) . 'class-bank-transfer-payment-logger.php';

class BANK_TRANSFER_PAYMENT_GATEWAY extends WC_Payment_Gateway_CC
{
	const ID = BANK_TRANSFER_GATEWAY_ID;

	protected $sandbox;
	private $base_url;
	private $public_key;
	private $secret_key;
	private $sandbox_secret_key;
	private $sandbox_public_key;

	private $admin_notices;
	private $accounts = [];
	private $current_account_index = 0;

	private static $log_once_flags = [];

	

	/**
	 * Account selected during the availability filter for dynamic title/subtitle.
	 *
	 * @var array|null
	 */
	private $selected_account_for_display = null;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		if (!class_exists('WC_Payment_Gateway_CC')) {
			add_action('admin_notices', [$this, 'bank_transfer_woocommerce_not_active_notice']);
			return;
		}

		$this->admin_notices = new BANK_TRANSFER_PAYMENT_GATEWAY_Admin_Notices();
		$this->base_url      = BANK_TRANSFER_BASE_URL;

		$this->id                 = self::ID;
		$this->icon               = '';
		$this->method_title       = __('RT Bank Transfer', 'bank-transfer-payment-gateway');
		$this->method_description = __('Let shoppers pay from their US bank account. Bank payments take 1 to 5 business days to arrive. Refunds are made in RT, not here: open the payment in your RT account and refund it there.', 'bank-transfer-payment-gateway');

		$this->bank_transfer_init_form_fields();
		$this->init_settings();
		$this->settings['group_id'] = get_option('bank_transfer_group_id') ? get_option('bank_transfer_group_id') : $this->bank_transfer_get_group_id();
		$this->load_gateway_settings();

		$this->register_hooks();
	}

	/**
	 * Load gateway settings.
	 * Called once in constructor AND can be re-called to refresh in AJAX context.
	 */
	/**
	 * Retrieve plugin version dynamically.
	 *
	 * @return string
	 */
	public function get_plugin_version() {
		if (function_exists('bank_transfer_get_plugin_version')) {
			return bank_transfer_get_plugin_version();
		}
		return defined('BANK_TRANSFER_PLUGIN_VERSION') ? BANK_TRANSFER_PLUGIN_VERSION : '1.0.0';
	}

	/**
	 * Attach plugin version metadata to the newly created order.
	 *
	 * @param WC_Order $order
	 * @param array $data
	 */
	public function bank_transfer_add_order_meta($order, $data = []) {
		if (!$order instanceof WC_Order) {
			return;
		}

		$payment_method = $order->get_payment_method();
		if (empty($payment_method) || $payment_method === $this->id) {
			$version = $this->get_plugin_version();
			if (!empty($version)) {
				$order->update_meta_data('_bank_transfer_plugin_version', sanitize_text_field($version));
				$order->update_meta_data('bank_transfer_plugin_version', sanitize_text_field($version));
			}
		}
	}

	public function load_gateway_settings() {
		$this->title       = sanitize_text_field($this->get_option('title'));
		$this->description = self::clean_description($this->get_option('description'));

		$this->enabled    = sanitize_text_field($this->get_option('enabled'));
		$this->sandbox    = 'yes' === sanitize_text_field($this->get_option('sandbox'));
		$this->public_key = sanitize_text_field($this->get_option($this->sandbox ? 'sandbox_public_key' : 'public_key'));
		$this->secret_key = sanitize_text_field($this->get_option($this->sandbox ? 'sandbox_secret_key' : 'secret_key'));
		$this->current_account_index = 0;
	}

	/**
	 * Tags a shop owner may use in the checkout description. Everything else,
	 * including style attributes and scripts, is removed.
	 *
	 * @return array
	 */
	private static function description_allowed_html() {
		return [
			'a'      => ['href' => true, 'title' => true, 'target' => true, 'rel' => true],
			'br'     => [],
			'em'     => [],
			'strong' => [],
			'b'      => [],
			'i'      => [],
			'p'      => [],
		];
	}

	/**
	 * Make a stored description safe and readable.
	 *
	 * Versions before 1.0.3 saved a default wrapped in styled paragraph tags,
	 * which showed up as raw code in the settings box. Those wrappers are
	 * turned into plain paragraphs; any other markup is limited to a few safe
	 * tags. Output is still escaped where it is printed.
	 *
	 * @param mixed $value Stored description.
	 * @return string
	 */
	public static function clean_description($value) {
		$value = is_string($value) ? $value : '';
		if ($value === '') {
			return '';
		}
		// Paragraph tags become blank lines so the text box shows plain text.
		$value = preg_replace('#</p>\s*<p[^>]*>#i', "\n\n", $value);
		$value = preg_replace('#</?p[^>]*>#i', '', (string) $value);
		$value = wp_kses((string) $value, self::description_allowed_html());
		$lines = array_map('trim', explode("\n", str_replace("\r", '', $value)));
		return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
	}

	/**
	 * WooCommerce reads this when it shows or uses the description setting.
	 * The description is always returned cleaned.
	 */
	public function get_option($key, $empty_value = null) {
		$value = parent::get_option($key, $empty_value);
		if ('description' === $key) {
			return self::clean_description($value);
		}
		return $value;
	}

	/**
	 * Clean the description when the settings are saved.
	 */
	public function validate_description_field($key, $value) {
		return self::clean_description(is_string($value) ? wp_unslash($value) : '');
	}

	/**
	 * Register hooks for the gateway.
	 */
	private function register_hooks() {
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'bank_transfer_process_admin_options']);
		add_action('woocommerce_checkout_create_order', [$this, 'bank_transfer_add_order_meta'], 10, 2);
		add_action('wp_enqueue_scripts', [$this, 'bank_transfer_enqueue_styles_and_scripts']);
		add_action('admin_enqueue_scripts', [$this, 'bank_transfer_admin_scripts']);

		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'bank_transfer_display_test_order_tag']);
		add_filter('woocommerce_admin_order_preview_line_items', [$this, 'bank_transfer_add_custom_label_to_order_row'], 10, 2);
		add_filter('woocommerce_available_payment_gateways', [$this, 'bank_transfer_hide_custom_payment_gateway_conditionally']);

		add_action('woocommerce_after_checkout_validation', [$this, 'bank_transfer_validate_checkout_fields'], 10, 2);
		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			[$this, 'bank_transfer_validate_blocks_checkout'],
			10,
			2
		);
	}

	/**
	 * Strict validation for Phone and Zip Code.
	 * Runs before process_payment to ensure only clean data reaches your API.
	 */
	public function bank_transfer_validate_checkout_fields($data, $errors)
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$selected_gateway = isset($data['payment_method']) ? sanitize_text_field($data['payment_method']) : (isset($_POST['payment_method']) ? sanitize_text_field(wp_unslash($_POST['payment_method'])) : '');

		if (empty($selected_gateway)) {
			return;
		}

		if ($selected_gateway !== $this->id) {
			return;
		}

		/*
		|--------------------------------------------------------------------------
		| PHONE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$phone   = trim($data['billing_phone'] ?? '');
		$country = strtoupper($data['billing_country'] ?? '');

		if (!empty($phone)) {

			$country_calling_code = WC()->countries->get_country_calling_code($country);

			if (is_array($country_calling_code)) {
				$country_calling_code = reset($country_calling_code);
			}

			$normalized = $this->bank_transfer_normalize_phone(
				$phone,
				$country_calling_code
			);

			if (empty($normalized['is_valid'])) {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					'Classic checkout validation failed: invalid phone number',
					[
						'phone'      => $phone,
						'country'    => $country,
						'normalized' => $normalized,
						'error'      => $normalized['error'] ?? null,
					]
				);

				$errors->add(
					'bank_transfer_phone_error',
					$normalized['error'] ?? __('Please check your phone number. Enter it with the area code, for example 415 555 0132.', 'bank-transfer-payment-gateway')
				);

				return;
			}

			Bank_Transfer_Payment_Gateway_Logger::info(
				'Classic checkout phone validation passed',
				[
					'phone'      => $phone,
					'country'    => $country,
					'normalized' => $normalized,
				]
			);
		}

		/*
		|--------------------------------------------------------------------------
		| PO BOX VALIDATION
		|--------------------------------------------------------------------------
		*/
		$billing_address_1 = trim($data['billing_address_1'] ?? '');

		if (!empty($billing_address_1) && $this->is_po_box($billing_address_1)) {

			Bank_Transfer_Payment_Gateway_Logger::warning(
				'Classic checkout validation failed: PO Box detected',
				[
					'country' => $country,
				]
			);

			$errors->add(
				'bank_transfer_po_box_error',
				__('Bank transfer needs a street address, not a PO Box. Please enter your street address.', 'bank-transfer-payment-gateway')
			);

			return;
		}

		/*
		|--------------------------------------------------------------------------
		| ZIP / POSTCODE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$postcode = trim($data['billing_postcode'] ?? '');

		if (!empty($postcode)) {

			$clean = strtoupper(preg_replace('/\s+/', '', $postcode));

			$valid = false;

			switch ($country) {

				case 'US':
					$valid = preg_match('/^\d{5}(-\d{4})?$/', $postcode);
					break;

				case 'CA':
					$valid = preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $clean);
					break;

				case 'GB':
					$valid = preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $clean);
					break;

				default:

					$clean_postcode = preg_replace('/[^A-Z0-9]/', '', strtoupper($postcode));

					// Reject purely numeric long values
					if (preg_match('/^\d{8,}$/', $clean_postcode)) {
						$valid = false;
					} else {

						$valid = preg_match(
							'/^(?=.*[A-Z0-9])[A-Z0-9\- ]{3,12}$/i',
							$postcode
						);
					}

					break;
			}

			if (!$valid) {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					'Classic checkout validation failed: invalid postcode',
					[
						'postcode' => $postcode,
						'country'  => $country
					]
				);

				$errors->add(
					'bank_transfer_postcode_error',
					__('Please check your ZIP / postal code.', 'bank-transfer-payment-gateway')
				);

				return;
			}

			Bank_Transfer_Payment_Gateway_Logger::info(
				'Classic checkout postcode validation passed',
				[
					'postcode' => $postcode,
					'country'  => $country
				]
			);
		}
	}


	public function bank_transfer_validate_blocks_checkout($order, $request)
	{
		$payment_method = $request['payment_method'] ?? '';

		if (empty($payment_method)) {
			return;
		}

		if ($payment_method !== $this->id) {
			return;
		}

		/*
		|--------------------------------------------------------------------------
		| PHONE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$phone   = trim($request['billing_address']['phone'] ?? '');
		$country = strtoupper($request['billing_address']['country'] ?? '');

		if (!empty($phone)) {

			$country_calling_code = WC()->countries->get_country_calling_code($country);

			if (is_array($country_calling_code)) {
				$country_calling_code = reset($country_calling_code);
			}

			$normalized = $this->bank_transfer_normalize_phone(
				$phone,
				$country_calling_code
			);

			if (empty($normalized['is_valid'])) {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					'Blocks checkout validation failed: invalid phone number',
					[
						'phone'      => $phone,
						'country'    => $country,
						'normalized' => $normalized,
						'order_id'   => $order->get_id() ?? null,
						'error'      => $normalized['error'] ?? null,
					]
				);

				throw $this->bank_transfer_checkout_exception(
					$normalized['error'] ?? __('Please check your phone number. Enter it with the area code, for example 415 555 0132.', 'bank-transfer-payment-gateway')
				);
			}

			// Sync latest validated value to order
			$order->set_billing_phone($phone);

			Bank_Transfer_Payment_Gateway_Logger::info(
				'Blocks checkout phone validation passed',
				[
					'phone'      => $phone,
					'country'    => $country,
					'normalized' => $normalized,
					'order_id'   => $order->get_id() ?? null,
				]
			);
		}

		/*
		|--------------------------------------------------------------------------
		| PO BOX VALIDATION
		|--------------------------------------------------------------------------
		*/
		$billing_address_1 = trim($request['billing_address']['address_1'] ?? '');

		if (!empty($billing_address_1) && $this->is_po_box($billing_address_1)) {

			Bank_Transfer_Payment_Gateway_Logger::warning(
				'Blocks checkout validation failed: PO Box detected',
				[
					'country'  => $country,
					'order_id' => $order->get_id() ?? null,
				]
			);

			throw $this->bank_transfer_checkout_exception(
				__('Bank transfer needs a street address, not a PO Box. Please enter your street address.', 'bank-transfer-payment-gateway')
			);
		}

		/*
		|--------------------------------------------------------------------------
		| ZIP / POSTCODE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$postcode = trim($request['billing_address']['postcode'] ?? '');

		if (!empty($postcode)) {

			$clean = strtoupper(preg_replace('/\s+/', '', $postcode));

			$valid = false;

			switch ($country) {

				case 'US':
					$valid = preg_match('/^\d{5}(-\d{4})?$/', $postcode);
					break;

				case 'CA':
					$valid = preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $clean);
					break;

				case 'GB':
					$valid = preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $clean);
					break;

				default:

					$clean_postcode = preg_replace('/[^A-Z0-9]/', '', strtoupper($postcode));

					// Reject purely numeric long values
					if (preg_match('/^\d{8,}$/', $clean_postcode)) {
						$valid = false;
					} else {

						$valid = preg_match(
							'/^(?=.*[A-Z0-9])[A-Z0-9\- ]{3,12}$/i',
							$postcode
						);
					}

					break;
			}

			if (!$valid) {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					'Blocks checkout validation failed: invalid postcode',
					[
						'postcode' => $postcode,
						'country'  => $country,
						'order_id' => $order->get_id() ?? null
					]
				);

				throw $this->bank_transfer_checkout_exception(
					__('Please check your ZIP / postal code.', 'bank-transfer-payment-gateway')
				);
			}

			$order->set_billing_postcode($postcode);

			Bank_Transfer_Payment_Gateway_Logger::info(
				'Blocks checkout postcode validation passed',
				[
					'postcode' => $postcode,
					'country'  => $country,
					'order_id' => $order->get_id() ?? null
				]
			);
		}

		return $order;
	}

	/**
	 * A checkout validation failure for the Store API: a RouteException is
	 * answered as a 400 with the message; a plain Exception would be a 500.
	 *
	 * @param string $message
	 * @return \Exception
	 */
	private function bank_transfer_checkout_exception($message) {
		if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
			return new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'rt_bank_transfer_invalid_address',
				esc_html($message),
				400
			);
		}
		return new \Exception(esc_html($message));
	}

	/**
	 * Whether the current request is a WooCommerce Store API (block checkout) call.
	 */
	private function is_store_api_request() {
		if (!defined('REST_REQUEST') || !REST_REQUEST) {
			return false;
		}
		$uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return strpos($uri, 'wc/store') !== false;
	}

	private function get_api_url($endpoint) {
		$endpoint = '/' . ltrim($endpoint, '/');
		if (strpos($endpoint, '/payment-api/') === 0) {
			return BANK_TRANSFER_BASE_URL . $endpoint;
		}
		if (strpos($endpoint, '/api/') === 0) {
			$endpoint = substr($endpoint, 4);
		}
		$api_base = defined('BANK_TRANSFER_API_BASE_URL') ? BANK_TRANSFER_API_BASE_URL : ($this->base_url . '/payment-api');
		return $api_base . $endpoint;
	}

	public function bank_transfer_process_admin_options() {
		// BT-12: Check capabilities and nonce BEFORE processing options
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to change these settings. Ask the shop owner or an administrator.', 'bank-transfer-payment-gateway'));
		}

		if (!isset($_POST['bank_transfer_accounts_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bank_transfer_accounts_nonce'])), 'bank_transfer_accounts_nonce_action')) {
			Bank_Transfer_Payment_Gateway_Logger::info('CSRF check failed during admin options update.');
			wp_die(esc_html__('This page was open for too long. Go back, refresh the settings page and save again.', 'bank-transfer-payment-gateway'));
		}

		$enabled     = isset($_POST['woocommerce_' . $this->id . '_enabled']) ? 'yes' : 'no';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$accounts    = isset($_POST['accounts']) && is_array($_POST['accounts']) ? wp_unslash($_POST['accounts']) : [];
		$keys_entered = false;

		if (!empty($accounts)) {
			foreach ($accounts as $account) {
				if (
					!empty($account['live_public_key']) ||
					!empty($account['live_secret_key']) ||
					!empty($account['sandbox_public_key']) ||
					!empty($account['sandbox_secret_key'])
				) {
					$keys_entered = true;
					break;
				}
			}
		}

		parent::process_admin_options();

		$errors             = [];
		$valid_accounts     = [];
		$unique_live_keys   = [];
		$unique_sandbox_keys = [];
		$normalized_index   = 0;
		$raw_accounts       = [];

		if (isset($_POST['accounts']) && is_array($_POST['accounts'])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$unslashed_accounts = wp_unslash($_POST['accounts']);
			$raw_accounts = array_map(
				static function ($account) {
					return is_array($account)
						? array_map('sanitize_text_field', $account)
						: sanitize_text_field($account);
				},
				$unslashed_accounts
			);
		}

		$no_accounts_submitted = (!is_array($raw_accounts) || empty($raw_accounts));
		if ($no_accounts_submitted) {
			Bank_Transfer_Payment_Gateway_Logger::info('No accounts submitted in admin options.');
		}

		// BK-09: only this plugin's own accounts; never another plugin's keys.
		$existing_accounts = bank_transfer_get_accounts();

		$existing_by_id = [];
		foreach ($existing_accounts as $idx => $existing_acc) {
			if (is_array($existing_acc)) {
				if (!empty($existing_acc['unique_id'])) {
					$existing_by_id[$existing_acc['unique_id']] = $existing_acc;
				}
				$existing_by_id['idx_' . $idx] = $existing_acc;
			}
		}

		foreach ((array) $raw_accounts as $raw_index => $account) {
			if (!is_array($account)) continue;

			$account = array_map('sanitize_text_field', $account);

			$account_title      = $account['title'] ?? '';
			$priority           = intval($account['priority'] ?? 1);
			$live_public_key    = $account['live_public_key'] ?? '';
			$live_secret_key    = $account['live_secret_key'] ?? '';
			$sandbox_public_key = $account['sandbox_public_key'] ?? '';
			$sandbox_secret_key = $account['sandbox_secret_key'] ?? '';
			$has_sandbox         = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';
			$live_status        = $account['live_status'] ?? '';
			$sandbox_status     = $has_sandbox ? ($account['sandbox_status'] ?? 'unknown') : 'inactive';
			$unique_id          = $account['unique_id'] ?? '';
			$submitted_unique_id = $unique_id;
			if (empty($unique_id)) {
				// Back-fill missing IDs here, on save, rather than while rendering the settings page.
				$unique_id = $this->bank_transfer_get_unique_id();
			}
			$checkout_title      = $account['checkout_title'] ?? '';
	        $checkout_subtitle   = $account['checkout_subtitle'] ?? '';

			// Write-only secret key handling: preserve existing secret if submitted value is empty
			$existing_account = null;
			if (!empty($unique_id) && isset($existing_by_id[$unique_id])) {
				$existing_account = $existing_by_id[$unique_id];
			} elseif ($submitted_unique_id === '' && isset($existing_by_id['idx_' . $raw_index])) {
				// Only an account saved before ids existed is matched by position.
				// A new account must never pick up another account's secret key.
				$existing_account = $existing_by_id['idx_' . $raw_index];
			}

			if (empty($live_secret_key) && $existing_account && !empty($existing_account['live_secret_key'])) {
				$live_secret_key = $existing_account['live_secret_key'];
			}
			if ($has_sandbox) {
				if (empty($sandbox_secret_key) && $existing_account && !empty($existing_account['sandbox_secret_key']) && !empty($sandbox_public_key)) {
					$sandbox_secret_key = $existing_account['sandbox_secret_key'];
				}
			} else {
				$sandbox_public_key = '';
				$sandbox_secret_key = '';
				$sandbox_status     = 'inactive';
			}

			if (empty($account_title) && empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
				continue;
			}

			if (empty($account_title)) {
				$errors[] = __('Give each payment account a name, for example "Main account".', 'bank-transfer-payment-gateway');
				Bank_Transfer_Payment_Gateway_Logger::info('Validation failed: an account has no name.');
				continue;
			}

			// A business may start with test keys only, live keys only, or both.
			$has_live_keys    = !empty($live_public_key) && !empty($live_secret_key);
			$has_sandbox_keys = $has_sandbox && !empty($sandbox_public_key) && !empty($sandbox_secret_key);

			if (!$has_live_keys && (!empty($live_public_key) || !empty($live_secret_key))) {
				/* translators: %s: Account name */
				$errors[] = sprintf(__('Account "%s": paste both live keys (publishable and secret), or leave both live boxes empty to test with sandbox keys only.', 'bank-transfer-payment-gateway'), $account_title);
				Bank_Transfer_Payment_Gateway_Logger::info("Validation failed: incomplete live keys for account '{$account_title}'");
				continue;
			}

			if ($has_sandbox && !$has_sandbox_keys) {
				/* translators: %s: Account name */
				$errors[] = sprintf(__('Account "%s": paste both sandbox keys (publishable and secret), or untick "I have sandbox (test) keys".', 'bank-transfer-payment-gateway'), $account_title);
				Bank_Transfer_Payment_Gateway_Logger::info("Validation failed: missing sandbox keys for account '{$account_title}'");
				continue;
			}

			if (!$has_live_keys && !$has_sandbox_keys) {
				/* translators: %s: Account name */
				$errors[] = sprintf(__('Account "%s": add your sandbox keys to test, or your live keys to take real payments. You can copy them in RT under Developers.', 'bank-transfer-payment-gateway'), $account_title);
				Bank_Transfer_Payment_Gateway_Logger::info("Validation failed: no keys for account '{$account_title}'");
				continue;
			}

			if ($has_live_keys) {
				$live_combined = $live_public_key . '|' . $live_secret_key;
				if (in_array($live_combined, $unique_live_keys, true)) {
					/* translators: %s: Account name */
					$errors[] = sprintf(__('Account "%s": these live keys are already used by another account on this page. Remove one of the two accounts.', 'bank-transfer-payment-gateway'), $account_title);
					Bank_Transfer_Payment_Gateway_Logger::info("Validation failed: duplicate live keys for account '{$account_title}'");
					continue;
				}

				if ($live_public_key === $live_secret_key) {
					/* translators: %s: Account name */
					$errors[] = sprintf(__('Account "%s": the live publishable key and live secret key are the same. Paste your secret key in the second box.', 'bank-transfer-payment-gateway'), $account_title);
					Bank_Transfer_Payment_Gateway_Logger::info("Validation failed: live keys are identical for account '{$account_title}'");
					continue;
				}

				$unique_live_keys[] = $live_combined;
				if ($live_status === '') {
					$live_status = 'unknown';
				}
			} else {
				$live_status = 'inactive';
			}

			if ($has_sandbox && !empty($sandbox_public_key) && !empty($sandbox_secret_key)) {
				$sandbox_combined = $sandbox_public_key . '|' . $sandbox_secret_key;
				if (in_array($sandbox_combined, $unique_sandbox_keys, true)) {
					/* translators: %s: Account name */
					$errors[] = sprintf(__('Account "%s": these sandbox keys are already used by another account on this page. Remove one of the two accounts.', 'bank-transfer-payment-gateway'), $account_title);
					Bank_Transfer_Payment_Gateway_Logger::info("Validation failed: duplicate sandbox keys for account '{$account_title}'");
					continue;
				}
				if ($sandbox_public_key === $sandbox_secret_key) {
					/* translators: %s: Account name */
					$errors[] = sprintf(__('Account "%s": the sandbox publishable key and sandbox secret key are the same. Paste your sandbox secret key in the second box.', 'bank-transfer-payment-gateway'), $account_title);
					Bank_Transfer_Payment_Gateway_Logger::info("Validation failed: sandbox keys are identical for account '{$account_title}'");
					continue;
				}
				$unique_sandbox_keys[] = $sandbox_combined;
			}

			// The status is what RT last told us about these exact keys. It is
			// never taken from the submitted form, and it starts again as
			// "not checked yet" whenever a key changes.
			$live_unchanged = $existing_account
				&& hash_equals((string) ($existing_account['live_public_key'] ?? ''), (string) $live_public_key)
				&& hash_equals((string) ($existing_account['live_secret_key'] ?? ''), (string) $live_secret_key);
			$sandbox_unchanged = $existing_account
				&& hash_equals((string) ($existing_account['sandbox_public_key'] ?? ''), (string) $sandbox_public_key)
				&& hash_equals((string) ($existing_account['sandbox_secret_key'] ?? ''), (string) $sandbox_secret_key);

			if (!$has_live_keys) {
				$live_status = 'inactive';
			} else {
				$live_status = $live_unchanged ? sanitize_key((string) ($existing_account['live_status'] ?? '')) : 'unknown';
				if ($live_status === '') {
					$live_status = 'unknown';
				}
			}
			if (!$has_sandbox_keys) {
				$sandbox_status = 'inactive';
			} else {
				$sandbox_status = $sandbox_unchanged ? sanitize_key((string) ($existing_account['sandbox_status'] ?? '')) : 'unknown';
				if ($sandbox_status === '') {
					$sandbox_status = 'unknown';
				}
			}

			$valid_accounts[$normalized_index++] = [
				'title'              => $account_title,
				'priority'           => $priority,
				'live_public_key'    => $live_public_key,
				'live_secret_key'    => $live_secret_key,
				'sandbox_public_key' => $sandbox_public_key,
				'sandbox_secret_key' => $sandbox_secret_key,
				'has_sandbox'        => $has_sandbox ? 'on' : 'off',
				'sandbox_status'     => $sandbox_status,
				'live_status'        => $live_status,
				'unique_id'          => $unique_id,
				'checkout_title'     => $checkout_title,
	            'checkout_subtitle'  => $checkout_subtitle,
			];

			Bank_Transfer_Payment_Gateway_Logger::info("Validated and added account '{$account_title}' to saved list.");
		}

		if (empty($valid_accounts) && empty($errors)) {
			// Nothing to check and nothing wrong: the list is simply empty (a new
			// install, or the business removed its last account). Save it, and
			// say what to do next. Checkout hides bank transfer until an
			// approved account exists, so shoppers are never offered it.
			update_option(BANK_TRANSFER_ACCOUNTS_OPTION, [], false);
			$this->admin_notices->bank_transfer_add_notice(
				'no_accounts',
				'notice notice-warning',
				__('Add a payment account to accept bank payments. Bank transfer stays hidden at checkout until an account is added and approved.', 'bank-transfer-payment-gateway')
			);
			add_action('admin_notices', [$this->admin_notices, 'display_notices']);
			return;
		}

		if (empty($errors)) {
			update_option(BANK_TRANSFER_ACCOUNTS_OPTION, $valid_accounts, false);

			$first_account = !empty($valid_accounts) ? reset($valid_accounts) : null;
			$public_key    = $first_account ? ($this->sandbox ? ($first_account['sandbox_public_key'] ?? '') : ($first_account['live_public_key'] ?? '')) : '';
			$api_url       = esc_url($this->get_api_url('/plugin/check/plugin'));
			$plugin_version = $this->get_plugin_version();

			$sanitized_accounts = array_map(function($acc) {
				unset($acc['live_secret_key'], $acc['sandbox_secret_key']);
				return $acc;
			}, $valid_accounts);

			$body = [
				'valid_accounts' => $sanitized_accounts,
				'plugin_status'  => $enabled === 'yes' ? 1 : 0,
				'plugin_version' => $plugin_version,
				'gateway_loaded' => 0,
				'group_id'       => get_option('bank_transfer_group_id'),
				'domain_name'    => wp_parse_url(home_url(), PHP_URL_HOST),
				'wordpress_version'     => get_bloginfo('version'),
				'woocommerce_version'   => get_option('woocommerce_version') ?: '',
				'woocommerce_db_version'=> get_option('woocommerce_db_version'),
			];

			wp_remote_post($api_url, [
				'method'    => 'POST',
				'timeout'   => 30,
				'body'      => wp_json_encode($body),
				'headers'   => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
				'sslverify' => true,
			]);

			Bank_Transfer_Payment_Gateway_Logger::info('Account settings updated successfully.', ['count' => count($valid_accounts)]);

			if (class_exists('BANK_TRANSFER_PAYMENT_GATEWAY_Loader')) {
				$loader = BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance();
				if (method_exists($loader, 'handle_cron_event')) {
					$loader->handle_cron_event();
					Bank_Transfer_Payment_Gateway_Logger::info('Triggered BANK_TRANSFER_PAYMENT_GATEWAY_Loader::handle_cron_event() after settings save.');

					// Say plainly whether RT accepted the keys that were just saved.
					$sync_problem = method_exists($loader, 'bank_transfer_last_sync_problem') ? $loader->bank_transfer_last_sync_problem($this->sandbox) : '';
					if ($sync_problem !== '') {
						$this->admin_notices->bank_transfer_add_notice('sync_problem', 'notice notice-error', $sync_problem);
					}
				}
			}
		} else {
			$this->admin_notices->bank_transfer_add_notice(
				'settings_error_intro',
				'notice notice-error',
				__('Your payment accounts were not saved. Fix the following and save again:', 'bank-transfer-payment-gateway')
			);
			foreach ($errors as $error) {
				$this->admin_notices->bank_transfer_add_notice('settings_error', 'notice notice-error', $error);
				Bank_Transfer_Payment_Gateway_Logger::info("Admin settings error: {$error}");
			}
		}

		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
	}

	public function bank_transfer_init_form_fields() {
		$this->form_fields = $this->bank_transfer_get_form_fields();
	}

	function bank_transfer_get_group_id() {
		$group_id = get_option('bank_transfer_group_id');
		if (empty($group_id)) {
			$group_id = 'grp_' . wp_generate_uuid4();
			update_option('bank_transfer_group_id', $group_id);
		}
		return $group_id;
	}

	function bank_transfer_get_unique_id() {
		return 'acc_' . wp_generate_uuid4();
	}

	function update_accounts_uniqueID($accounts) {
		if (empty($accounts) || !is_array($accounts)) return $accounts;
		$updated = false;
		foreach ($accounts as $index => &$account) {
			if (!is_array($account)) continue;
			if (empty($account['unique_id'])) {
				$account['unique_id'] = $this->bank_transfer_get_unique_id();
				$updated = true;
			}
		}
		unset($account);
		if ($updated) {
			update_option(BANK_TRANSFER_ACCOUNTS_OPTION, $accounts);
		}
		return $accounts;
	}

	public function bank_transfer_get_form_fields() {
		$dev_instructions_link = sprintf(
			'<strong><a class="bank-transfer-instructions-url" href="%s" target="_blank" rel="noopener noreferrer">%s</a></strong><br>',
			esc_url($this->base_url . '/developers'),
			esc_html__('Open the Developers page in RT', 'bank-transfer-payment-gateway')
		);

		return apply_filters('bank_transfer_woocommerce_gateway_settings_fields_' . $this->id, [

			'enabled' => [
				'title'   => __('Turn on', 'bank-transfer-payment-gateway'),
				'label'   => __('Offer bank transfer at checkout', 'bank-transfer-payment-gateway'),
				'type'    => 'checkbox',
				'default' => 'no',
			],

			'title' => [
				'title'       => __('Title', 'bank-transfer-payment-gateway'),
				'type'        => 'text',
				'description' => __('The name shoppers see for this payment method at checkout.', 'bank-transfer-payment-gateway'),
				'default'     => __('Pay by bank transfer', 'bank-transfer-payment-gateway'),
				'desc_tip'    => true,
			],

			'description' => [
				'title'       => __('Description', 'bank-transfer-payment-gateway'),
				'type'        => 'textarea',
				'description' => __('A short note shoppers see under the title at checkout. Plain text; links and bold are allowed.', 'bank-transfer-payment-gateway'),
				'default'     => __('Pay from your US bank account. You will connect your bank on RT\'s secure page. Bank payments take 1 to 5 business days to confirm.', 'bank-transfer-payment-gateway'),
				'desc_tip'    => true,
			],

			'instructions' => [
				'title'       => __('How to set up', 'bank-transfer-payment-gateway'),
				'type'        => 'title',
				'description' => wp_kses(
					__('1. Copy your keys from RT (Developers).', 'bank-transfer-payment-gateway') . ' ' . $dev_instructions_link .
					esc_html__('2. Under Payment accounts below, press Add account and paste the keys.', 'bank-transfer-payment-gateway') . '<br>' .
					esc_html__('3. Press Save changes. The account status shows whether RT accepted the keys.', 'bank-transfer-payment-gateway') . '<br><br>' .
					'<strong>' . esc_html__('Refunds:', 'bank-transfer-payment-gateway') . '</strong> ' .
					esc_html__('refund a bank payment in RT, not in WooCommerce. Open the payment in your RT account and refund it there.', 'bank-transfer-payment-gateway'),
					['a' => ['href' => true, 'target' => true, 'class' => true, 'rel' => true], 'strong' => [], 'br' => []]
				),
			],

			'sandbox' => [
				'title'       => __('Test mode', 'bank-transfer-payment-gateway'),
				'label'       => __('Use sandbox (test) keys', 'bank-transfer-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('While this is on, no real money moves. Turn it off before taking real orders.', 'bank-transfer-payment-gateway'),
				'default'     => 'no',
			],

			'group_id' => [
				'type' => 'hidden',
			],

			'accounts' => [
				'title'       => __('Payment accounts', 'bank-transfer-payment-gateway'),
				'type'        => 'accounts_repeater',
				'description' => __('The RT account that receives the money. Most shops need one.', 'bank-transfer-payment-gateway'),
			],

			'order_status' => [
				'title'       => __('Order status when paid', 'bank-transfer-payment-gateway'),
				'type'        => 'select',
				'description' => __('The status an order moves to once the money has arrived.', 'bank-transfer-payment-gateway'),
				'default'     => '',
				'id'          => 'order_status_select',
				'desc_tip'    => true,
				'options'     => [
					'processing' => __('Processing', 'bank-transfer-payment-gateway'),
					'completed'  => __('Completed', 'bank-transfer-payment-gateway'),
				],
			],

			'show_consent_checkbox' => [
				'title'       => __('Consent tick box', 'bank-transfer-payment-gateway'),
				'label'       => __('Ask shoppers to agree before paying', 'bank-transfer-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Adds a tick box at checkout: "I consent to the collection of my data to process this payment".', 'bank-transfer-payment-gateway'),
				'default'     => 'no',
			],

			'debug' => [
				'title'       => __('Troubleshooting log', 'bank-transfer-payment-gateway'),
				'label'       => __('Keep a detailed log', 'bank-transfer-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Useful when RT support asks for it. Find the log under WooCommerce > Status > Logs. Your secret keys are never written to it.', 'bank-transfer-payment-gateway'),
				'default'     => 'no',
				'desc_tip'    => true,
			],

		], $this);
	}

	public function generate_accounts_repeater_html($key, $data) {
		$option_value    = bank_transfer_get_accounts();
		$sandbox_enabled = bank_transfer_is_sandbox();

		// Render-only normalisation. This method runs on a GET of the settings page, so it must not
		// write options; missing unique_ids are persisted by the save handler and the migration.
		if (!empty($option_value)) {
			foreach ($option_value as $index => &$account) {
				if (empty($account['unique_id'])) {
					$account['unique_id'] = $this->bank_transfer_get_unique_id();
				}
				// Ensure all fields are present for new/empty accounts
				if (!isset($account['checkout_title'])) {
					$account['checkout_title'] = '';
				}
				if (!isset($account['checkout_subtitle'])) {
					$account['checkout_subtitle'] = '';
				}
			}
		}
		unset($account);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html($data['title']); ?></label>
			</th>
			<td class="forminp">
				<p class="description" style="margin-bottom: 10px;"><?php echo esc_html($data['description'] ?? ''); ?></p>
				<div id="bank-transfer-global-error" class="bank-transfer-global-error" style="color: #b32d2e; margin-bottom: 10px;" role="alert"></div>
				<div class="bank-transfer-accounts-container">
					<?php if (!empty($option_value)): ?>
						<div class="bank-transfer-sync-account">
							<span id="<?php echo esc_attr($this->id); ?>-sync-status"></span>
							<button type="button" class="button" id="<?php echo esc_attr($this->id); ?>-sync-accounts"><span><i class="fa fa-refresh" aria-hidden="true"></i></span> <?php esc_html_e('Check with RT', 'bank-transfer-payment-gateway'); ?></button>
						</div>
					<?php endif; ?>

					<?php if (empty($option_value)): ?>
						<div class="empty-account"><?php esc_html_e('No payment account yet. Press "Add account" and paste your RT keys.', 'bank-transfer-payment-gateway'); ?></div>
					<?php else: ?>
						<?php foreach (array_values($option_value) as $index => $account): ?>
							<?php
							$live_status    = (!empty($account['live_status'])) ? $account['live_status'] : '';
							$sandbox_status = (!empty($account['sandbox_status'])) ? $account['sandbox_status'] : 'unknown';
							$unique_id      = (!empty($account['unique_id'])) ? $account['unique_id'] : '';
							?>
							<div class="bank-transfer-account" data-index="<?php echo esc_attr($index); ?>">
								<input type="hidden" class="live-status" name="accounts[<?php echo esc_attr($index); ?>][live_status]" value="<?php echo esc_attr($account['live_status'] ?? ''); ?>">
								<input type="hidden" class="sandbox-status" name="accounts[<?php echo esc_attr($index); ?>][sandbox_status]" value="<?php echo esc_attr($account['sandbox_status'] ?? ''); ?>">
								<div class="title-blog">
									<h4>
										<span class="account-name-display bank-transfer-name-display">
											<?php echo !empty($account['title']) ? esc_html($account['title']) : esc_html__('New account', 'bank-transfer-payment-gateway'); ?>
										</span>
										&nbsp;<i class="fa fa-caret-down bank-transfer-toggle-btn account-toggle-btn" aria-hidden="true"></i>
									</h4>
									<div class="action-button">
										<div class="account-status-block">
											<?php $shown_status = bank_transfer_account_status_view($sandbox_enabled ? $sandbox_status : $live_status, $sandbox_enabled); ?>
											<span class="account-status-label <?php echo esc_attr($shown_status['class']); ?>" title="<?php echo esc_attr($shown_status['help']); ?>">
												<?php echo esc_html($shown_status['label']); ?>
											</span>
										</div>
										<button type="button" class="delete-account-btn" aria-label="<?php esc_attr_e('Remove this account', 'bank-transfer-payment-gateway'); ?>" title="<?php esc_attr_e('Remove this account', 'bank-transfer-payment-gateway'); ?>">
											<i class="fa fa-trash" aria-hidden="true"></i>
										</button>
									</div>
								</div>

								<div class="bank-transfer-info account-info">
									<div class="add-blog title-priority">
										<div class="account-input account-name">
											<label><?php esc_html_e('Account name (only you see this)', 'bank-transfer-payment-gateway'); ?></label>
											<input type="text" class="account-title" name="accounts[<?php echo esc_attr($index); ?>][title]" placeholder="<?php esc_attr_e('For example: Main account', 'bank-transfer-payment-gateway'); ?>" value="<?php echo esc_attr($account['title'] ?? ''); ?>">
										</div>
										<div>
											<input type="hidden" class="unique-id" name="accounts[<?php echo esc_attr($index); ?>][unique_id]" value="<?php echo esc_attr($unique_id); ?>" readonly>
										</div>
										<div class="account-input priority-name">
											<label><?php esc_html_e('Order of use (1 is used first)', 'bank-transfer-payment-gateway'); ?></label>
											<input type="number" class="account-priority" name="accounts[<?php echo esc_attr($index); ?>][priority]" placeholder="<?php esc_attr_e('Priority', 'bank-transfer-payment-gateway'); ?>" value="<?php echo esc_attr($account['priority'] ?? '1'); ?>" min="1">
										</div>

									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Checkout title (optional)', 'bank-transfer-payment-gateway'); ?></label>
											<input type="text"
												name="accounts[<?php echo esc_attr($index); ?>][checkout_title]"
												placeholder="<?php esc_attr_e('Leave empty to use the title above', 'bank-transfer-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['checkout_title'] ?? ''); ?>">
										</div>
									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Checkout note (optional)', 'bank-transfer-payment-gateway'); ?></label>
											<textarea
												class="checkout-subtitle"
												name="accounts[<?php echo esc_attr($index); ?>][checkout_subtitle]"
												placeholder="<?php esc_attr_e('Leave empty to use the description above', 'bank-transfer-payment-gateway'); ?>"
												rows="2"><?php echo esc_textarea($account['checkout_subtitle'] ?? ''); ?></textarea>
										</div>
									</div>

									<div class="add-blog bank-transfer-production-keys">
										<div class="account-input">
											<label><?php esc_html_e('Live keys (for real payments)', 'bank-transfer-payment-gateway'); ?></label>
											<input type="text" class="live-public-key" name="accounts[<?php echo esc_attr($index); ?>][live_public_key]" placeholder="<?php esc_attr_e('Publishable key', 'bank-transfer-payment-gateway'); ?>" value="<?php echo esc_attr($account['live_public_key'] ?? ''); ?>">
										</div>
										<div class="account-input">
											<?php
											$live_secret_masked = !empty($account['live_secret_key']) ? (str_repeat('•', 8) . substr($account['live_secret_key'], -4)) : '';
											?>
											<div class="secret-key-wrapper" style="position: relative; display: flex; align-items: center;">
												<input type="password" autocomplete="new-password" class="live-secret-key" name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]" placeholder="<?php echo !empty($live_secret_masked) ? esc_attr($live_secret_masked) : esc_attr__('Secret key', 'bank-transfer-payment-gateway'); ?>" value="" data-has-existing="<?php echo !empty($account['live_secret_key']) ? '1' : '0'; ?>" style="padding-right: 30px;">
												<button type="button" class="toggle-secret-visibility" style="position: absolute; right: 15px; top: calc(50% - 4px); background: none; border: none; cursor: pointer; color: #555; padding: 0; outline: none;" title="<?php esc_attr_e('Show or hide the secret key', 'bank-transfer-payment-gateway'); ?>"><i class="fa fa-eye" aria-hidden="true"></i></button>
											</div>
										</div>
									</div>

									<div class="account-checkbox">
										<?php
										$checkbox_id    = 'bank-transfer-sandbox-checkbox-' . $index;
										$checkbox_class = 'bank-transfer-sandbox-checkbox';
										?>
										<input type="checkbox" class="<?php echo esc_attr($checkbox_class); ?>" id="<?php echo esc_attr($checkbox_id); ?>" name="accounts[<?php echo esc_attr($index); ?>][has_sandbox]" <?php checked(($account['has_sandbox'] ?? '') === 'on'); ?>>
										<label for="<?php echo esc_attr($checkbox_id); ?>"><?php esc_html_e('I have sandbox (test) keys', 'bank-transfer-payment-gateway'); ?></label>
									</div>

									<?php
									$sandbox_container_id    = 'bank-transfer-sandbox-keys-' . $index;
									$sandbox_container_class = 'bank-transfer-sandbox-keys';
									$sandbox_display_style   = ($account['has_sandbox'] ?? '') === 'on' ? '' : 'display: none;';
									?>
									<div id="<?php echo esc_attr($sandbox_container_id); ?>" class="<?php echo esc_attr($sandbox_container_class); ?>" style="<?php echo esc_attr($sandbox_display_style); ?>">
										<div class="add-blog">
											<div class="account-input">
												<label><?php esc_html_e('Sandbox keys (for testing)', 'bank-transfer-payment-gateway'); ?></label>
												<input type="text" class="sandbox-public-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_public_key]" placeholder="<?php esc_attr_e('Publishable key', 'bank-transfer-payment-gateway'); ?>" value="<?php echo esc_attr($account['sandbox_public_key'] ?? ''); ?>">
											</div>
											<div class="account-input">
												<?php
												$sandbox_secret_masked = !empty($account['sandbox_secret_key']) ? (str_repeat('•', 8) . substr($account['sandbox_secret_key'], -4)) : '';
												?>
												<div class="secret-key-wrapper" style="position: relative; display: flex; align-items: center;">
													<input type="password" autocomplete="new-password" class="sandbox-secret-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_secret_key]" placeholder="<?php echo !empty($sandbox_secret_masked) ? esc_attr($sandbox_secret_masked) : esc_attr__('Secret key', 'bank-transfer-payment-gateway'); ?>" value="" data-has-existing="<?php echo !empty($account['sandbox_secret_key']) ? '1' : '0'; ?>" style="padding-right: 30px;">
													<button type="button" class="toggle-secret-visibility" style="position: absolute; right: 15px; top: calc(50% - 4px); background: none; border: none; cursor: pointer; color: #555; padding: 0; outline: none;" title="<?php esc_attr_e('Show or hide the secret key', 'bank-transfer-payment-gateway'); ?>"><i class="fa fa-eye" aria-hidden="true"></i></button>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php wp_nonce_field('bank_transfer_accounts_nonce_action', 'bank_transfer_accounts_nonce'); ?>
					<div class="add-account-btn">
						<button type="button" class="button bank-transfer-add-account">
							<span>+</span> <?php esc_html_e('Add account', 'bank-transfer-payment-gateway'); ?>
						</button>
					</div>
				</div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * WooCommerce entry point for both checkouts.
	 *
	 * The classic checkout reads the result array (result / redirect). The
	 * Store API (block checkout, BK-08) turns a thrown exception into the
	 * error the shopper sees, so a refusal is thrown there instead of
	 * returned as 'failure' with a generic message.
	 */
	public function process_payment($order_id)
	{
		$result = $this->bank_transfer_do_process_payment($order_id);

		if (($result['result'] ?? '') !== 'success' && $this->is_store_api_request()) {
			throw new \Exception(esc_html($result['message'] ?: __('We could not start your bank payment. You have not been charged. Please try again in a moment, or choose another payment method.', 'bank-transfer-payment-gateway')));
		}

		return $result;
	}

	private function bank_transfer_do_process_payment($order_id)
	{
		global $wpdb;

		$lock_name = '';

		$log_prefix = "[Order #{$order_id}]";

		$start_time = microtime(true);

		$plugin_version = $this->get_plugin_version();

		Bank_Transfer_Payment_Gateway_Logger::info(
			$log_prefix . ' Payment process started',
			[
				'order_id'       => $order_id,
				'plugin_version' => $plugin_version,
			]
		);

		wc_clear_notices();

		// -------------------------------------------------
		// 1. ORDER VALIDATION
		// -------------------------------------------------
		$order = wc_get_order($order_id);

		if (!$order) {


			if (is_checkout()) {
				wc_add_notice(__('Something went wrong with your order. Please try again, or contact the shop.', 'bank-transfer-payment-gateway'), 'error');
			}

			return $this->build_response(
				'failure',
				'Invalid order.',
				[],
				400,
				$order_id
			);
		}

		if (!bank_transfer_is_gateway_order($order)) {
			if (is_checkout()) {
				wc_add_notice(__('Bank transfer cannot be used for this order. Please choose another payment method.', 'bank-transfer-payment-gateway'), 'error');
			}

			return $this->build_response(
				'failure',
				'Invalid payment method.',
				[],
				400,
				$order_id
			);
		}

		$plugin_version = $this->get_plugin_version();
		if (!empty($plugin_version)) {
			$order->update_meta_data('_bank_transfer_plugin_version', sanitize_text_field($plugin_version));
			$order->update_meta_data('bank_transfer_plugin_version', sanitize_text_field($plugin_version));
			$order->save();
		}

		Bank_Transfer_Payment_Gateway_Logger::info(
			"Payment initiated",
			[
				'order_id' => $order_id,
				'status'   =>  $order->get_status()
			]
		);

		$lock_name = 'bank_transfer_order_' . $order_id;

		// Try lock
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$lock_result = $wpdb->get_var(
			$wpdb->prepare("SELECT GET_LOCK(%s, 3)", $lock_name)
		);

		if ((string)$lock_result !== '1') {
			return $this->build_response(
				'failure',
				'Payment already in progress. Please wait a few seconds and try again.',
				[],
				409,
				$order_id
			);
		}

		try {

			// -------------------------------------------------
			// 4. RATE LIMITING (BK-17: per session, not per shared IP)
			// -------------------------------------------------
			$subject      = bank_transfer_rate_limit_subject();
			$window_size  = 10;
			$max_requests = 5;

			$timestamp_key = 'bt_rl_pay_' . md5($subject);
			$timestamps    = get_transient($timestamp_key) ?: [];
			$current_time  = time();

			$timestamps = array_filter($timestamps, fn($ts) => $current_time - $ts <= $window_size);

			if (count($timestamps) >= $max_requests) {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					$log_prefix . ' Rate limit exceeded',
					[
						'subject' => strpos($subject, 'ip:') === 0 ? bank_transfer_anonymize_ip(substr($subject, 3)) : 'session',
					]
				);

				if (is_checkout()) {
					wc_add_notice(__('Too many tries in a short time. Please wait a few minutes and try again.', 'bank-transfer-payment-gateway'), 'error');
				}

				return $this->build_response(
					'failure',
					'Too many requests. Please try again later.',
					[],
					429,
					$order_id
				);
			}

			$timestamps[] = $current_time;
			set_transient($timestamp_key, $timestamps, $window_size);

			// -------------------------------------------------
			// 5. ORDER STATUS PROTECTION
			// -------------------------------------------------
			$status = $order->get_status();

			if ($status === 'completed' || $status === 'processing') {

				if (WC()->cart) {
					WC()->cart->empty_cart();
				}

				return $this->build_response(
					'success',
					'Order already processed',
					[
						'redirect' => $order->get_checkout_order_received_url(),
					],
					200,
					$order->get_id()
				);
			}

			if ($status === BANK_TRANSFER_RETURNED_STATUS) {
				return $this->build_response(
					'failure',
					__('Your bank sent back the payment for this order. Please contact the shop to arrange payment.', 'bank-transfer-payment-gateway'),
					[],
					409,
					$order_id
				);
			}

			// -------------------------------------------------
			// 5b. BK-04: never issue a second link while a debit is in flight.
			// A debit the customer already authorised is on its way; a new link
			// would let them pay twice. Refresh from RT first in case the
			// in-flight state is stale.
			// -------------------------------------------------
			if (BANK_TRANSFER_PAYMENT_ENGINE::is_in_flight($order) && class_exists('BANK_TRANSFER_PAYMENT_GATEWAY_Loader')) {
				$live = BANK_TRANSFER_PAYMENT_GATEWAY_Loader::get_instance()->sync_single_order_status($order);
				$order = wc_get_order($order_id);

				if ($live === 'success' || $order->has_status(['processing', 'completed'])) {
					if (WC()->cart) {
						WC()->cart->empty_cart();
					}
					return $this->build_response(
						'success',
						'Order already processed',
						[
							'redirect' => $order->get_checkout_order_received_url(),
						],
						200,
						$order_id
					);
				}

				if ($live === 'processing' || BANK_TRANSFER_PAYMENT_ENGINE::is_in_flight($order)) {
					$message = __('A bank transfer for this order is already on its way. It takes 1 to 5 business days. Please do not pay again, so you are not charged twice.', 'bank-transfer-payment-gateway');
					if (is_checkout()) {
						wc_add_notice($message, 'error');
					}
					return $this->build_response(
						'failure',
						$message,
						[],
						409,
						$order_id
					);
				}
			}

			// -------------------------------------------------
			// 6. SANDBOX FLAG (UNCHANGED)
			// -------------------------------------------------
			if ($this->sandbox) {
				if (!$order->get_meta('_is_test_order')) {
					$order->update_meta_data('_is_test_order', true);
					bank_transfer_add_unique_order_note(
						$order,
						'sandbox_mode',
						__('This is a test order. No real money moves.', 'bank-transfer-payment-gateway')
					);
				}
			}

			// -------------------------------------------------
			// 7. PAYMENT ACCOUNT LOOP (UNCHANGED LOGIC)
			// -------------------------------------------------

		$accounts = $this->get_all_accounts();

		if (empty($accounts)) {
			return $this->build_response(
				'failure',
				'No eligible payment provider available.',
				[],
				400,
				$order_id
			);
		}

		$selected_account = null;
		$payment_data     = null;
		$last_error_data  = null;
		$failed_accounts  = [];

		foreach ($accounts as $account) {

			$public_key = $this->sandbox
				? $account['sandbox_public_key']
				: $account['live_public_key'];

			$secret_key = $this->sandbox
				? $account['sandbox_secret_key']
				: $account['live_secret_key'];

			// Prepare payment data
			$data = $this->bank_transfer_prepare_payment_data($order, $public_key);

			if (is_array($data) && ($data['result'] ?? '') === 'failure') {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					$log_prefix . ' Account preparation failed',
					[
						'account_title' => $account['title'] ?? null,
						'error'         => $data['error'] ?? ($data['message'] ?? null),
					]
				);

				$failed_accounts[] = [
					'account' => $account['title'] ?? null,
					'reason'  => 'prepare_failed',
				];

				continue;
			}

			// BK-10: the secret only signs the request; it is never in the body.
			$limit_url = $this->get_api_url('/dailylimit');
			$limit_body = wp_json_encode([
				'is_sandbox'     => $this->sandbox ? 1 : 0,
				'amount'         => (float) $order->get_total(),
				'api_public_key' => $public_key,
			]);

			$limit_resp = wp_remote_post($limit_url, [
				'method'    => 'POST',
				'timeout'   => 5,
				'body'      => $limit_body,
				'headers'   => bank_transfer_signed_headers($public_key, $secret_key, $limit_body),
				'sslverify' => true,
			]);

			if (is_wp_error($limit_resp)) {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					$log_prefix . ' Daily limit API WP error',
					[
						'account_title' => $account['title'] ?? null,
						'error'         => $limit_resp->get_error_message(),
					]
				);

				$failed_accounts[] = [
					'account' => $account['title'] ?? null,
					'reason'  => 'wp_error',
				];

				continue;
			}

			$limit_code = (int) wp_remote_retrieve_response_code($limit_resp);
			$limit_data = json_decode(wp_remote_retrieve_body($limit_resp), true);

			if ($limit_code < 200 || $limit_code >= 300 || ($limit_data['status'] ?? '') === 'error') {

				Bank_Transfer_Payment_Gateway_Logger::warning(
					$log_prefix . ' Account rejected by daily limit API',
					[
						'account_title' => $account['title'] ?? null,
						'http_code'     => $limit_code,
						'response'      => $limit_data,
					]
				);

				$last_error_data = $limit_data;

				$failed_accounts[] = [
					'account' => $account['title'] ?? null,
					'reason'  => 'limit_error',
					'response'=> $limit_data,
				];

				continue;
			}

			// ✅ SUCCESS
			Bank_Transfer_Payment_Gateway_Logger::info(
				$log_prefix . ' Account selected',
				[
					'account_title' => $account['title'] ?? null,
				]
			);

			$selected_account = $account;
			$payment_data     = $data;

			break;
		}

			if (!$selected_account) {

				if ($last_error_data) {

					if (!empty($last_error_data['max_limit_reached'])) {

						return $this->build_response(
							'failure',
							'The transaction amount exceeds the maximum allowed limit.',
							[],
							400,
							$order_id
						);
					}

					$order->update_meta_data('_bank_transfer_limit_exceeded', true);
					$order->save();

					return $this->build_response(
						'failure',
						sanitize_text_field($last_error_data['message'] ?? 'Payment limit error.'),
						[],
						400,
						$order_id
					);
				}

				Bank_Transfer_Payment_Gateway_Logger::error(
					'No eligible payment provider available for this order.',
					[
						'order_id' => $order_id ?? null
					]
				);

				return $this->build_response(
					'failure',
					'No eligible payment provider available for this order',
					[],
					400,
					$order_id
				);
				}

				// -------------------------------------------------
				// 8. PAYMENT REQUEST
				// -------------------------------------------------
				$account    = $selected_account;
				$data       = $payment_data;

				$public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
				$secret_key = $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];

				// BK-10: the body is built once and sent exactly as signed. The
				// secret key signs the request and is never part of it.
				$api_url  = esc_url($this->get_api_url('/request-payment'));
				$raw_body = is_array($data) ? http_build_query($data, '', '&') : (string) $data;

				$response = wp_remote_post($api_url, [
					'method'    => 'POST',
					'timeout'   => 30,
					'body'      => $raw_body,
					'headers'   => bank_transfer_signed_headers($public_key, $secret_key, $raw_body, 'application/x-www-form-urlencoded'),
					'sslverify' => true,
				]);

				if (is_wp_error($response)) {

					return $this->build_response(
						'failure',
						'Payment error: Unable to process.',
						[],
						500,
						$order_id
					);
				}

				$http_code = (int) wp_remote_retrieve_response_code($response);
				$resp_body = wp_remote_retrieve_body($response);
				$resp_data = json_decode($resp_body, true);

				Bank_Transfer_Payment_Gateway_Logger::info(
					'Payment API response received',
					[
						'order_id'  => $order_id,
						'http_code' => $http_code,
						'status'    => $resp_data['status'] ?? null,
						'pay_id'    => $resp_data['data']['pay_id'] ?? null,
					]
				);

				if ($http_code < 200 || $http_code >= 300 || empty($resp_data) || ($resp_data['status'] ?? '') === 'error') {

					$error_msg = '';
					if (is_array($resp_data)) {
						if (!empty($resp_data['message']) && is_string($resp_data['message'])) {
							$error_msg = $resp_data['message'];
						} elseif (!empty($resp_data['detail']) && is_string($resp_data['detail'])) {
							$error_msg = $resp_data['detail'];
						} elseif (!empty($resp_data['errors']) && is_array($resp_data['errors'])) {
							$flat = [];
							array_walk_recursive($resp_data['errors'], function($v) use (&$flat) {
								if (is_string($v)) $flat[] = $v;
							});
							$error_msg = implode(' ', $flat);
						}
					}

					if (empty($error_msg)) {
						$error_msg = __('We could not start your bank payment. You have not been charged. Please try again in a moment, or choose another payment method.', 'bank-transfer-payment-gateway');
					}
					$error_msg = sanitize_text_field($error_msg);
					// RT's own wording is only shown when it is about the shopper's
					// details (400 / 422). Anything about the shop's keys or RT's
					// service is not the shopper's problem and is kept in the log.
					$shopper_msg = in_array((int) $http_code, [400, 422], true)
						? $error_msg
						: __('Bank transfer is not available right now. You have not been charged. Please choose another payment method, or contact the shop.', 'bank-transfer-payment-gateway');

					Bank_Transfer_Payment_Gateway_Logger::error(
						$log_prefix . ' Payment API request failed',
						[
							'order_id'  => $order_id,
							'http_code' => $http_code,
							'error'     => $error_msg,
						]
					);

					if (is_checkout()) {
						wc_add_notice($shopper_msg, 'error');
					}

					return $this->build_response(
						'failure',
						$shopper_msg,
						[],
						($http_code >= 400 && $http_code < 500) ? $http_code : 400,
						$order_id
					);
				}

				$payment_link = $resp_data['data']['payment_link'] ?? null;
				$pay_id       = $resp_data['data']['pay_id'] ?? '';

				if (empty($payment_link)) {

					Bank_Transfer_Payment_Gateway_Logger::error(
						'Missing payment link in response',
						[
							'order_id' => $order_id,
							'pay_id'   => $pay_id,
						]
					);

					return $this->build_response(
						'failure',
						'Payment could not be initiated. Please try again in a moment.',
						[],
						500,
						$order_id
					);
				}

				// -------------------------------------------------
				// 9. DATABASE: one row per issued link (BK-04 history, BK-12 own table)
				// -------------------------------------------------
				$table_name = esc_sql(bank_transfer_table_name());

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$latest = $wpdb->get_row($wpdb->prepare(
					"SELECT id, uuid FROM {$table_name} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
					$order_id
				), ARRAY_A);

				$row = [
					'order_id'       => $order_id,
					'uuid'           => sanitize_text_field($pay_id),
					'payment_link'   => esc_url_raw($payment_link),
					'customer_email' => sanitize_email($resp_data['data']['customer_email'] ?? ''),
					'amount'         => number_format((float)($resp_data['data']['amount'] ?? 0), 2, '.', ''),
					'created_at'     => current_time('mysql', 1),
				];

				if (is_array($latest) && (string) ($latest['uuid'] ?? '') === (string) $pay_id) {
					// RT handed back the same (still open) link: refresh the row.
					$wpdb->update($table_name, $row, ['id' => (int) $latest['id']]);
				} else {
					$wpdb->insert($table_name, $row);
				}
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				// BT-22: Invalidate cached payment row and uuid
				wp_cache_delete('bank_transfer_payment_row_' . intval($order_id), 'bank_transfer_payment_gateway');
				wp_cache_delete('bank_transfer_payment_link_uuid_' . intval($order_id), 'bank_transfer_payment_gateway');

				// -------------------------------------------------
				// 10. PAY ID UPDATE (every id the order was ever issued is kept, BK-04)
				// -------------------------------------------------
				if (!empty($pay_id)) {

					$history = $order->get_meta('_bank_transfer_pay_id_history');
					$history = is_array($history) ? $history : [];
					$known   = array_map(static function ($entry) {
						return (string) (is_array($entry) ? ($entry['pay_id'] ?? '') : $entry);
					}, $history);
					if (!in_array((string) $pay_id, $known, true)) {
						$history[] = [
							'pay_id'     => sanitize_text_field($pay_id),
							'public_key' => sanitize_text_field($public_key),
							'created_at' => current_time('mysql', 1),
						];
						$order->update_meta_data('_bank_transfer_pay_id_history', $history);
					}

					$order->update_meta_data('_bank_transfer_pay_id', $pay_id);
					$order->update_meta_data('_bank_transfer_pay_id_updated_at', time());

					$order->update_meta_data('_bank_transfer_active_pay_id', $pay_id);
					$order->update_meta_data('_bank_transfer_payment_finalized', false);
					$order->update_meta_data('_bank_transfer_public_key', sanitize_text_field($public_key));

					// A new link starts a new payment cycle. A state closed by an
					// earlier link (failed / cancelled / expired) must not block the
					// RT backend webhook for this one; a paid order never gets here.
					$previous_state = $order->get_meta('_bank_transfer_state');
					if (in_array($previous_state, ['failed', 'cancelled', 'expired'], true)) {
						$order->update_meta_data('_bank_transfer_state', 'pending');
					}
					$order->save();
				}

				// -------------------------------------------------
				// 11. SUCCESS RESPONSE
				// -------------------------------------------------

				// The order stays pending until RT reports the debit in flight
				// (BK-03: then on-hold) or settled (then processing/completed).
				$order->update_status('pending', __('Waiting for the customer to approve the bank transfer on RT\'s page.', 'bank-transfer-payment-gateway'));

				bank_transfer_add_unique_order_note(
					$order,
					'payment_initiated',
					sprintf(
						/* translators: %s: Payment account title */
						__('The customer chose bank transfer (%s).', 'bank-transfer-payment-gateway'),
						$account['title']
					)
				);

				Bank_Transfer_Payment_Gateway_Logger::info(
					$log_prefix . ' Payment initiated successfully',
					[
						'order_id'    => $order_id,
						'pay_id'      => $pay_id,
						'payment_link'=> $payment_link,
					]
				);

				return $this->build_response(
					'success',
					'Payment initiated',
					[
						'payment_status' => $resp_data['data']['payment_status'] ?? 'pending',
						'redirect'       => esc_url($payment_link),
						'order_key'      => $order->get_order_key(),
						'bank_transfer_nonce'  => wp_create_nonce('bank_transfer_payment'),
					],
					200,
					$order_id
				);

			} catch (\Exception $e) {

				Bank_Transfer_Payment_Gateway_Logger::error(
					"Payment processing exception: " . $e->getMessage(),
					[
						'order_id' => $order_id ?? null,
						'file'     => $e->getFile(),
						'line'     => $e->getLine(),
						'trace'    => $e->getTraceAsString()
					]
				);

				return $this->build_response('failure', 'An internal error occurred.', [], 500, $order_id);

			} finally {

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
			}
	}

	private function build_response(
		string $result,
		string $message = '',
		array $data = [],
		int $code = 200,
		?int $order_id = null
	) {
		// WooCommerce reads result / redirect at the top level (classic checkout
		// and the Store API alike); the checkout script reads the rest.
		return [
			'result'              => $result, // success | failure
			'message'             => $message,
			'data'                => $data,
			'redirect'            => (string) ($data['redirect'] ?? ''),
			'order_id'            => $order_id,
			'order_key'           => (string) ($data['order_key'] ?? ''),
			'bank_transfer_nonce' => (string) ($data['bank_transfer_nonce'] ?? ''),
			'code'                => $code,
			'success'             => $result === 'success',
		];
	}

	public function bank_transfer_display_test_order_tag($order) {
		if ($order instanceof WC_Order && $order->get_meta('_is_test_order')) {
			echo '<p class="form-field form-field-wide"><strong>' . esc_html__('Test order: no real money was received.', 'bank-transfer-payment-gateway') . '</strong></p>';
		}
		// Refunds are made in RT by design; say so where the shop owner looks for the button.
		if ($order instanceof WC_Order && bank_transfer_is_gateway_order($order)) {
			echo '<p class="form-field form-field-wide"><strong>' . esc_html__('Refunds:', 'bank-transfer-payment-gateway') . '</strong> ' .
				esc_html__('to refund this bank payment, open it in your RT account and refund it there. The Refund button here only records a refund in WooCommerce; it does not send money back.', 'bank-transfer-payment-gateway') . '</p>';
		}
	}

	private function bank_transfer_get_return_url_base() {
		if (defined('BANK_TRANSFER_WEBHOOK_URL') && !empty(BANK_TRANSFER_WEBHOOK_URL)) {
			return BANK_TRANSFER_WEBHOOK_URL;
		}
		$url = rest_url('/' . BANK_TRANSFER_REST_NAMESPACE . '/data');
		return apply_filters('bank_transfer_webhook_url', $url);
	}

	private function is_po_box($address) {
		if (empty($address)) return false;

		$clean = strtolower(preg_replace('/[^a-z0-9]/i', '', $address));

		return preg_match('/pob|postoffice/', $clean) === 1;
	}

	private function bank_transfer_prepare_payment_data($order, $api_public_key) {
		$order_id    = $order->get_id();
		$is_sandbox  = $this->get_option('sandbox') === 'yes';
		$request_for = sanitize_email($order->get_billing_email() ?: $order->get_billing_phone());
		$first_name  = sanitize_text_field($order->get_billing_first_name());
		$last_name   = sanitize_text_field($order->get_billing_last_name());
		$amount      = number_format($order->get_total(), 2, '.', '');
		$email       = sanitize_text_field($order->get_billing_email());
		$original_phone = $order->get_billing_phone();
		$phone       = sanitize_text_field($original_phone);
		$country     = $order->get_billing_country();
		$country_code = WC()->countries->get_country_calling_code($country);
		
		$billing_address_1 = sanitize_text_field($order->get_billing_address_1());
		$billing_address_2 = sanitize_text_field($order->get_billing_address_2());
		$billing_city      = sanitize_text_field($order->get_billing_city());
		$billing_postcode  = sanitize_text_field($order->get_billing_postcode());
		$billing_country   = sanitize_text_field($order->get_billing_country());
		$billing_state     = sanitize_text_field($order->get_billing_state());

		$redirect_url = esc_url_raw(add_query_arg([
			'order_id' => $order_id,
			'key'      => $order->get_order_key(),
			'mode'     => 'wp',
		], $this->bank_transfer_get_return_url_base()));

		$ip_address = sanitize_text_field($this->bank_transfer_get_client_ip());

		if (empty($order_id)) {
			Bank_Transfer_Payment_Gateway_Logger::error(
				'Order ID is missing or invalid.',
				[]
			);
			return ['result' => 'failure','error'=>'Order ID is missing or invalid.'];
		}

		$plugin_version = $this->get_plugin_version();

		$meta_data_array = array_map('sanitize_text_field', [
			'order_id'       => $order_id,
			'amount'         => $amount,
			'source'         => 'woocommerce',
			'plugin_version' => $plugin_version,
		]);

		// BK-06: only the customer WooCommerce attached to the order. A guest
		// typing a registered customer's e-mail is still a guest; the e-mail is
		// never mapped to that customer's account.
		$user_id  = (int) $order->get_customer_id();
		$is_guest = $user_id > 0 ? 0 : 1;

		// BK-10: no secret in the body; the request is signed with it instead.
		return [
			'api_public_key'   => $api_public_key,
			'first_name'       => $first_name,
			'last_name'        => $last_name,
			'request_for'      => $request_for,
			'amount'           => $amount,
			'redirect_url'     => $redirect_url,
			'redirect_time'    => 3,
			'ip_address'       => $ip_address,
			'source'           => 'wordpress',
			'meta_data'        => $meta_data_array,
			'remarks'          => 'Order ' . $order->get_order_number(),
			'email'            => $email,
			'phone_number'     => $phone,
			'country_code'     => $country_code,
			'billing_address_1'=> $billing_address_1,
			'billing_address_2'=> $billing_address_2,
			'billing_city'     => $billing_city,
			'billing_postcode' => $billing_postcode,
			'billing_country'  => $billing_country,
			'billing_state'    => $billing_state,
			'is_sandbox'       => $is_sandbox,
			'curr_code'        => sanitize_text_field($order->get_currency()),
			'plugin_source'    => 'bank_transfer',
			'plugin_version'   => $plugin_version,
			'is_guest'         => $is_guest,
			'customer_user_id' => $user_id,
		];
	}

	private function bank_transfer_normalize_phone($phone, $country_code) {
		$cleanedPhone  = preg_replace('/[()\s-]/', '', $phone ?? '');
		$countryCode   = preg_replace('/[^0-9]/', '', $country_code ?? '');
		$phoneNumber   = preg_replace('/[^\d]/', '', $cleanedPhone);

		if (!empty($countryCode) && strlen($phoneNumber) > strlen($countryCode) && strpos($phoneNumber, $countryCode) === 0) {
			$normalizedPhone = substr($phoneNumber, strlen($countryCode));
		} else {
			$normalizedPhone = $phoneNumber;
		}

		$normalizedPhone = ltrim($normalizedPhone, '0');

		if (empty($phoneNumber)) {
			return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => true, 'error' => null];
		}

		$localLength   = strlen($normalizedPhone);
		$totalLength   = strlen($countryCode . $normalizedPhone);
		$requires10Digits = in_array($countryCode, ['1']);
		$europeCodes   = ['33','34','39','31','44','46','47','48','49','41','45','358'];

		if ($requires10Digits) {
			if ($localLength !== 10) {
				return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => 'Phone number must be exactly 10 digits.'];
			}
		} elseif (in_array($countryCode, $europeCodes)) {
			$min = ($countryCode === '49' || $countryCode === '358') ? 5 : 8;
			$max = ($countryCode === '49' || $countryCode === '358') ? 11 : 10;
			if ($localLength < $min || $localLength > $max) {
				return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => "European number invalid: should be $min-$max digits"];
			}
		} else {
			// Default international validation
			if ($localLength < 10 || $localLength > 15) {

				return [
					'phone'        => $normalizedPhone,
					'country_code' => '+' . $countryCode,
					'is_valid'     => false,
					'error'        => 'Phone number must be between 10 and 15 digits.'
				];
			}
		}

		if ($totalLength > 15) {
			return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => sprintf('Phone number is too long. Maximum allowed length is 15 digits (including country code). Your phone number has %d digits.', $totalLength)];
		}

		return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => true, 'error' => null];
	}

	private function bank_transfer_get_client_ip() {
		if (class_exists('WC_Geolocation')) {
			return WC_Geolocation::get_ip_address();
		}
		$ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
	}

	public function bank_transfer_add_custom_label_to_order_row($line_items, $order) {
		$order_origin = $order->get_meta('_order_origin');
		if (!empty($order_origin)) {
			$line_items[0]['name'] .= ' <span style="background-color: #ffeb3b; color: #000; padding: 3px 5px; border-radius: 3px; font-size: 12px;">' . esc_html($order_origin) . '</span>';
		}
		return $line_items;
	}

	public function bank_transfer_woocommerce_not_active_notice() {
		echo '<div class="error"><p>' . esc_html__('RT Bank Transfer needs WooCommerce. Install and activate WooCommerce first.', 'bank-transfer-payment-gateway') . '</p></div>';
	}

	public function payment_fields() {
		$description = $this->get_option('description');
		if (is_array($this->selected_account_for_display) && !empty($this->selected_account_for_display['checkout_subtitle'])) {
			$description = $this->selected_account_for_display['checkout_subtitle'];
		} elseif (WC()->cart) {
			
			$accounts = $this->get_all_accounts();
			$sorted   = $this->get_routing_sorted_accounts($accounts);
			if (!empty($sorted) && !empty($sorted[0]['checkout_subtitle'])) {
				$description = $sorted[0]['checkout_subtitle'];
			}
		}

		if ($description) {
			echo wp_kses_post(wpautop(wptexturize(trim($description))));
		}
		if ('yes' === $this->get_option('show_consent_checkbox')) {
			echo '<p class="form-row form-row-wide">
                <label for="bank_transfer_consent">
                    <input type="checkbox" id="bank_transfer_consent" name="bank_transfer_consent" /> ' .
				esc_html__('I consent to the collection of my data to process this payment', 'bank-transfer-payment-gateway') .
				'</label></p>';
			wp_nonce_field('bank_transfer_payment', 'bank_transfer_nonce');
		}
	}

	public function validate_fields() {

		if ($this->get_option('show_consent_checkbox') === 'yes') {
			$nonce = isset($_POST['bank_transfer_nonce']) ? sanitize_text_field(wp_unslash($_POST['bank_transfer_nonce'])) : '';
			if (empty($nonce) || !wp_verify_nonce($nonce, 'bank_transfer_payment')) {
				wc_add_notice(__('This page was open for too long. Please refresh it and try again.', 'bank-transfer-payment-gateway'), 'error');
				return false;
			}
			$consent = isset($_POST['bank_transfer_consent']) ? sanitize_text_field(wp_unslash($_POST['bank_transfer_consent'])) : '';
			if ($consent !== 'on') {
				wc_add_notice(__('Please tick the consent box to pay by bank transfer.', 'bank-transfer-payment-gateway'), 'error');
				return false;
			}
		}
		return true;
	}

	public function bank_transfer_enqueue_styles_and_scripts() {
		if (is_checkout()) {
			$image_url = plugin_dir_url(dirname(__FILE__)) . 'assets/images/loader.gif';
			wp_enqueue_style('bank-transfer-payment-loader-styles', plugins_url('../assets/css/frontend.css', __FILE__), [], '1.0', 'all');
			wp_enqueue_script('bank-transfer-js', plugins_url('../assets/js/bank-transfer.js', __FILE__), ['jquery'], '1.0', true);
			wp_localize_script('bank-transfer-js', 'bank_transfer_params', [
				'ajax_url'       => admin_url('admin-ajax.php'),
				'checkout_url'   => wc_get_checkout_url(),
				'bank_transfer_loader' => $image_url,
				'bank_transfer_nonce'  => wp_create_nonce('bank_transfer_payment'),
				'payment_method'       => $this->id,
				'plugin_version'       => $this->get_plugin_version(),
			]);
		}
	}

	function bank_transfer_admin_scripts($hook) {
		if (
			'woocommerce_page_wc-settings' !== $hook ||
			(sanitize_text_field(wp_unslash($_GET['section'] ?? '')) !== $this->id) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			return;
		}
		wp_enqueue_style('bank-transfer-font-awesome', plugins_url('../assets/css/font-awesome.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/font-awesome.css'), 'all');
		wp_enqueue_style('bank-transfer-admin-css', plugins_url('../assets/css/admin.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/admin.css'), 'all');
		wp_enqueue_script('bank-transfer-admin-script', plugins_url('../assets/js/bank-transfer-admin.js', __FILE__), ['jquery'], filemtime(plugin_dir_path(__FILE__) . '../assets/js/bank-transfer-admin.js'), true);
		$status_texts = [];
		foreach (bank_transfer_account_status_texts() as $status_key => $status_text) {
			$status_texts[$status_key] = ['label' => $status_text[0], 'help' => $status_text[1]];
		}
		wp_localize_script('bank-transfer-admin-script', 'bank_transfer_admin_data', [
			'ajax_url'    => admin_url('admin-ajax.php'),
			'nonce'       => wp_create_nonce('bank_transfer_sync_nonce'),
			'gateway_id'  => $this->id,
			'sync_action' => $this->id . '_manual_sync',
			'statuses'    => $status_texts,
			'i18n'        => [
				'sandbox'            => __('Sandbox', 'bank-transfer-payment-gateway'),
				'live'               => __('Live', 'bank-transfer-payment-gateway'),
				'new_account'        => __('New account', 'bank-transfer-payment-gateway'),
				'account_name'       => __('Account name (only you see this)', 'bank-transfer-payment-gateway'),
				'account_name_hint'  => __('For example: Main account', 'bank-transfer-payment-gateway'),
				'order_of_use'       => __('Order of use (1 is used first)', 'bank-transfer-payment-gateway'),
				'checkout_title'     => __('Checkout title (optional)', 'bank-transfer-payment-gateway'),
				'checkout_title_hint' => __('Leave empty to use the title above', 'bank-transfer-payment-gateway'),
				'checkout_note'      => __('Checkout note (optional)', 'bank-transfer-payment-gateway'),
				'checkout_note_hint' => __('Leave empty to use the description above', 'bank-transfer-payment-gateway'),
				'live_keys'          => __('Live keys (for real payments)', 'bank-transfer-payment-gateway'),
				'sandbox_keys'       => __('Sandbox keys (for testing)', 'bank-transfer-payment-gateway'),
				'publishable_key'    => __('Publishable key', 'bank-transfer-payment-gateway'),
				'secret_key'         => __('Secret key', 'bank-transfer-payment-gateway'),
				'show_hide'          => __('Show or hide the secret key', 'bank-transfer-payment-gateway'),
				'have_sandbox'       => __('I have sandbox (test) keys', 'bank-transfer-payment-gateway'),
				'remove'             => __('Remove this account', 'bank-transfer-payment-gateway'),
				'confirm_remove'     => __('Remove this account? Its keys are deleted from this site when you press Save changes.', 'bank-transfer-payment-gateway'),
				'removed_last'       => __('No payment account is left. Press Save changes to confirm; bank transfer will be hidden at checkout until you add one.', 'bank-transfer-payment-gateway'),
				'new_account_hint'   => __('Paste your keys, then press Save changes.', 'bank-transfer-payment-gateway'),
				'checking'           => __('Checking with RT...', 'bank-transfer-payment-gateway'),
				'check_done'         => __('Checked with RT.', 'bank-transfer-payment-gateway'),
				'check_failed'       => __('We couldn\'t connect to RT with these keys. Check that the publishable and secret keys are from the same RT account and the same mode (Sandbox or Live).', 'bank-transfer-payment-gateway'),
				'check_unreachable'  => __('We could not reach RT. Check your internet connection and try again in a moment.', 'bank-transfer-payment-gateway'),
				'fix_errors'         => __('Some boxes need your attention. Fix the ones marked in red, then save again.', 'bank-transfer-payment-gateway'),
				'need_title'         => __('Enter a name for this payment method. Shoppers see it at checkout.', 'bank-transfer-payment-gateway'),
				'need_description'   => __('Add a short note for shoppers, for example how long bank payments take.', 'bank-transfer-payment-gateway'),
				'need_name'          => __('Give this account a name, for example "Main account".', 'bank-transfer-payment-gateway'),
				'name_used'          => __('Another account already uses this name. Choose a different one.', 'bank-transfer-payment-gateway'),
				'need_order'         => __('Enter a number. 1 is used first.', 'bank-transfer-payment-gateway'),
				'order_used'         => __('Another account already uses this number. Give each account its own.', 'bank-transfer-payment-gateway'),
				'need_keys'          => __('Add your sandbox keys to test, or your live keys to take real payments.', 'bank-transfer-payment-gateway'),
				'need_both_live'     => __('Paste both live keys, or leave both boxes empty to use sandbox keys only.', 'bank-transfer-payment-gateway'),
				'need_both_sandbox'  => __('Paste both sandbox keys, or untick "I have sandbox (test) keys".', 'bank-transfer-payment-gateway'),
				'key_used'           => __('This key is already used in another box on this page. Each box needs its own key.', 'bank-transfer-payment-gateway'),
				'same_keys'          => __('The publishable key and the secret key are the same. Paste your secret key here.', 'bank-transfer-payment-gateway'),
			],
		]);
	}

	public function bank_transfer_hide_custom_payment_gateway_conditionally($available_gateways)
	{
		$gateway_id = $this->id;
		$this->selected_account_for_display = null;

		// Nothing to decide when this method is not on offer (switched off,
		// or already removed by WooCommerce or another plugin).
		if (!is_array($available_gateways) || !isset($available_gateways[$gateway_id])) {
			return $available_gateways;
		}

		// Never offer a method the shop has switched off.
		if ('yes' !== $this->enabled) {
			return $this->hide_gateway($available_gateways, $gateway_id);
		}

		// =====================================================
		// STEP 1: SAFE CART CHECK
		// =====================================================
		if (!function_exists('WC') || !WC()->cart) {
			return $available_gateways;
		}

		// =====================================================
		// STEP 2: CHECKOUT CONTEXT (STRICT)
		// =====================================================
		$is_ajax = function_exists('wp_doing_ajax') && wp_doing_ajax();
		$is_blocks = defined('REST_REQUEST') && REST_REQUEST && !is_admin();
		$is_checkout_page = function_exists('is_checkout') && is_checkout();

		if (!$is_checkout_page && !$is_ajax && !$is_blocks) {
			return $available_gateways;
		}

		// =====================================================
		// STEP 3: FLOW LABEL
		// =====================================================
		$flow = 'Checkout (Classic)';

		if ($is_blocks) {
			$flow = 'Checkout (Blocks)';
		} elseif ($is_ajax) {
			$flow = 'Checkout (AJAX)';
		}

		// =====================================================
		// STEP 4: CART INFO
		// =====================================================
		$amount = (float) WC()->cart->get_total('raw');
		if ($amount < 0.01) {
			$amount = (float) (WC()->cart->get_totals()['total'] ?? 0);
		}

		$items = count(WC()->cart->get_cart());

		// =====================================================
		// STEP 5: REQUEST FINGERPRINT (REAL FIX)
		// =====================================================
		static $last_fingerprint = null;
		static $last_selected    = null;

		$fingerprint = md5(wp_json_encode([
			'flow'   => $flow,
			'items'  => $items,
			'total'  => $amount,
			'ajax'   => $is_ajax,
			'blocks' => $is_blocks
		]));

		if ($last_fingerprint === $fingerprint && $last_selected !== null) {
			if (!$last_selected) {
				return $this->hide_gateway($available_gateways, $gateway_id);
			}
			return $available_gateways;
		}

		// =====================================================
		// STEP 6: LOAD ACCOUNTS
		// =====================================================
		if (!method_exists($this, 'get_all_accounts')) {
			return $available_gateways;
		}

		$accounts = $this->get_all_accounts();

		// =====================================================
		// STEP 7: NO ACCOUNTS
		// =====================================================
		if (empty($accounts)) {

			Bank_Transfer_Payment_Gateway_Logger::info(
				"Bank Transfer Gateway Decision",
				[
					'result' => 'HIDDEN',
					'reason' => 'No approved payment account for the current mode',
					'items'  => $items,
					'total'  => $amount,
					'flow'   => $flow
				]
			);

			return $this->hide_gateway($available_gateways, $gateway_id);
		}

		// =====================================================
		// STEP 8: SORT
		// =====================================================
		usort($accounts, fn($a, $b) =>
			($a['priority'] ?? 1) <=> ($b['priority'] ?? 1)
		);

		// =====================================================
		// STEP 9: EVALUATION
		// =====================================================
		$selected = null;
		$reason   = 'No eligible payment account';

		foreach ($accounts as $account) {
			$public = $this->sandbox
				? ($account['sandbox_public_key'] ?? '')
				: ($account['live_public_key'] ?? '');

			$secret = $this->sandbox
				? ($account['sandbox_secret_key'] ?? '')
				: ($account['live_secret_key'] ?? '');

			if (empty($public) || empty($secret)) {
				continue;
			}

			$selected = $account;
			$reason   = 'Valid payment account found';
			break;
		}

		// =====================================================
		// STEP 10: SINGLE FINAL LOG ONLY
		// =====================================================
		Bank_Transfer_Payment_Gateway_Logger::info(
			"Bank Transfer Gateway Decision",
			[
				'result' => $selected ? 'SHOWN' : 'HIDDEN',
				'reason' => $reason,
				'items'  => $items,
				'total'  => $amount,
				'flow'   => $flow,
				'account'=> $selected['title'] ?? null
			]
		);

		// =====================================================
		// STEP 11: RETURN RESULT
		// =====================================================
		$last_fingerprint = $fingerprint;
		$last_selected    = !empty($selected);

		$this->selected_account_for_display = $selected;

		if (!$selected) {
			return $this->hide_gateway($available_gateways, $gateway_id);
		}

		return $available_gateways;
	}

	/**
	 * Take this payment method out of the list a shopper can choose from.
	 *
	 * Used when bank transfer cannot take a payment right now: it is switched
	 * off, no payment account is saved, or no saved account is approved for
	 * the current mode (test or live). Every other payment method is left
	 * exactly as it was.
	 *
	 * @param array  $available_gateways Gateways WooCommerce is about to offer.
	 * @param string $gateway_id         This gateway's id.
	 * @return array
	 */
	private function hide_gateway($available_gateways, $gateway_id) {
		if (is_array($available_gateways) && isset($available_gateways[$gateway_id])) {
			unset($available_gateways[$gateway_id]);
		}
		return $available_gateways;
	}

	/**
	 * POST a JSON body to RT, signed with the secret (never sent), and cache
	 * the decoded answer.
	 */
	private function get_cached_api_response($url, $data, $cache_key, $ttl = 300, $force_refresh = false, $secret_key = '') {
		if (!$force_refresh && isset($_GET['refresh_accounts']) && sanitize_text_field(wp_unslash($_GET['refresh_accounts'])) === '1' && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')) {
			$force_refresh = true;
		}
		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if ($cached !== false) return $cached;
		} else {
			delete_transient($cache_key);
		}
		$raw_body = wp_json_encode($data);
		$response = wp_remote_post($url, [
			'method'    => 'POST',
			'timeout'   => 5,
			'headers'   => bank_transfer_signed_headers($data['api_public_key'] ?? '', $secret_key, $raw_body),
			'body'      => $raw_body,
			'sslverify' => true,
		]);
		if (is_wp_error($response)) {
			return null;
		}
		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (!empty($body)) {
			set_transient($cache_key, $body, $ttl);
		}
		return $body;
	}

	private function get_all_accounts() {
		$accounts = bank_transfer_get_accounts();
		$valid_accounts = [];
		foreach ($accounts as $i => $account) {
			if ($this->sandbox) {
				$has_sandbox = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';
				$status      = strtolower($account['sandbox_status'] ?? '');
				$has_keys    = !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']);
				if ($has_sandbox && $status === 'active' && $has_keys) {
					$valid_accounts[] = $account;
				}
			} else {
				$status   = strtolower($account['live_status'] ?? '');
				$has_keys = !empty($account['live_public_key']) && !empty($account['live_secret_key']);
				if ($status === 'active' && $has_keys) {
					$valid_accounts[] = $account;
				}
			}
		}
		$this->accounts = $valid_accounts;
		return $valid_accounts;
	}

	public function is_available() {
		$is_available = ('yes' === $this->enabled);

		if (!$is_available) {
			return false;
		}

		$accounts = $this->get_all_accounts();
		return !empty($accounts);
	}

	/**
	 * Alias for get_all_accounts() to provide backward compatibility.
	 *
	 * @return array
	 */
	public function get_all_available_accounts(): array {
		return $this->get_all_accounts();
	}

	private function get_routing_sorted_accounts(array $accounts): array {
		usort($accounts, function ($a, $b) {
			return ($a['priority'] ?? 1) <=> ($b['priority'] ?? 1);
		});
		return array_values($accounts);
	}

	/**
	 * Get checkout display info (title + subtitle) for a given cart amount.
	 * BT-03 & BT-17: Never sends secret keys; reads stored status and caches daily limit for 5 min.
	 *
	 * @param float $amount Order/cart total.
	 * @return array ['title' => string, 'subtitle' => string]
	 */
	public function get_checkout_info_for_amount(float $amount): array {
		$accounts = $this->get_all_accounts();
		if (empty($accounts)) {
			return [
				'title'    => $this->title,
				'subtitle' => $this->description,
			];
		}

		$sorted_accounts = $this->get_routing_sorted_accounts($accounts);
		$transactionLimitApiUrl = $this->get_api_url('/dailylimit');

		$force_refresh = false;
		if (
			isset($_GET['refresh_accounts'], $_GET['_wpnonce']) &&
			sanitize_text_field(wp_unslash($_GET['refresh_accounts'])) === '1'
		) {
			$refresh_nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
			if (wp_verify_nonce($refresh_nonce, 'refresh_accounts_nonce')) {
				$force_refresh = true;
			}
		}

		$selected_account = null;
		foreach ($sorted_accounts as $account) {
			$public_key = $this->sandbox ? ($account['sandbox_public_key'] ?? '') : ($account['live_public_key'] ?? '');
			$secret_key = $this->sandbox ? ($account['sandbox_secret_key'] ?? '') : ($account['live_secret_key'] ?? '');
			if (empty($public_key) || empty($secret_key)) {
				continue;
			}

			// BT-17: Check stored account status synced by cron instead of making remote calls on every checkout render
			$status = strtolower($this->sandbox ? ($account['sandbox_status'] ?? 'active') : ($account['live_status'] ?? 'active'));
			if ($status !== 'active') {
				continue;
			}

			// BK-10: the secret signs the call; it is not in the body.
			$data = [
				'is_sandbox'     => $this->sandbox ? 1 : 0,
				'amount'         => $amount,
				'api_public_key' => $public_key,
			];
			$cache_base  = 'bank_transfer_daily_limit_' . md5($public_key . '_' . $amount);
			$limit_data  = $this->get_cached_api_response($transactionLimitApiUrl, $data, $cache_base . '_limit', 300, $force_refresh, $secret_key);

			if (!empty($limit_data) && ($limit_data['status'] ?? '') === 'success') {
				$selected_account = $account;
				break;
			}
		}

		if (!$selected_account && !empty($sorted_accounts)) {
			$selected_account = $sorted_accounts[0];
		}

		$title    = !empty($selected_account['checkout_title']) ? $selected_account['checkout_title'] : $this->title;
		$subtitle = !empty($selected_account['checkout_subtitle']) ? $selected_account['checkout_subtitle'] : $this->description;

		return [
			'title'    => $title,
			'subtitle' => $subtitle,
		];
	}
}
