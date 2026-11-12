<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once plugin_dir_path(__FILE__) . 'config.php';

/**
 * Check the environment for compatibility issues.
 *
 * @return string|false
 */
function bank_transfer_check_system_requirements()
{
	if (version_compare(phpversion(), BANK_TRANSFER_PAYMENT_GATEWAY_MIN_PHP_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required PHP version, %2$s is the current PHP version
			__('RT Bank Transfer needs PHP version %1$s or newer. This site runs %2$s. Ask your web host to update PHP.', 'bank-transfer-payment-gateway'),
			BANK_TRANSFER_PAYMENT_GATEWAY_MIN_PHP_VER,
			phpversion()
		);
	}

	// Get WooCommerce versions
	$wc_db_version = get_option('woocommerce_db_version');
	$wc_plugin_version = defined('WC_VERSION') ? WC_VERSION : null;

	// Check if the WooCommerce database version is outdated
	if (!$wc_db_version || version_compare($wc_db_version, BANK_TRANSFER_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce database version, %2$s is the current WooCommerce database version (or "undefined" if not available)
			__('RT Bank Transfer needs WooCommerce database version %1$s or newer. This site has %2$s. Update WooCommerce and run its database update.', 'bank-transfer-payment-gateway'),
			BANK_TRANSFER_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_db_version ? $wc_db_version : __('an unknown version', 'bank-transfer-payment-gateway')
		);
	}

	// Check if WooCommerce plugin version is outdated
	if (!$wc_plugin_version || version_compare($wc_plugin_version, BANK_TRANSFER_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce plugin version, %2$s is the current WooCommerce plugin version (or "undefined" if not available)
			__('RT Bank Transfer needs WooCommerce version %1$s or newer. This site has %2$s. Update WooCommerce.', 'bank-transfer-payment-gateway'),
			BANK_TRANSFER_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_plugin_version ? $wc_plugin_version : __('an unknown version', 'bank-transfer-payment-gateway')
		);
	}

	return false;
}

if (!function_exists('bank_transfer_add_unique_order_note')) {

    function bank_transfer_add_unique_order_note($order, $key, $message)
    {
        if (!$order instanceof WC_Order) {
            return false;
        }

        if (empty($message)) {
            return false;
        }

        // Plugin identifier (IMPORTANT for tracking in WP admin)
        $plugin_prefix = '<strong>Bank Transfer Gateway</strong>';

        // Unique meta key per note type (scoped to plugin)
        $meta_key = '_bank_transfer_order_note_' . sanitize_key($key);

        // Check if already exists
        $existing = $order->get_meta($meta_key, true);

        if (!empty($existing)) {
            return false;
        }

        // Prepend plugin identifier to every note
        $final_message = $plugin_prefix . "\n\n" . wp_kses_post($message);

        // Add WooCommerce order note
        $order->add_order_note($final_message);

        // Store timestamp using WooCommerce timezone-aware time
        $order->update_meta_data($meta_key, current_time('timestamp'));

        $order->save();

        return true;
    }
}

if (!function_exists('bank_transfer_get_plugin_version')) {
    /**
     * Retrieve current plugin version dynamically from constant or plugin header.
     *
     * @return string
     */
    function bank_transfer_get_plugin_version() {
        if (defined('BANK_TRANSFER_PLUGIN_VERSION') && !empty(BANK_TRANSFER_PLUGIN_VERSION)) {
            return BANK_TRANSFER_PLUGIN_VERSION;
        }

        if (defined('BANK_TRANSFER_PAYMENT_GATEWAY_FILE') && function_exists('get_file_data')) {
            $plugin_data = get_file_data(BANK_TRANSFER_PAYMENT_GATEWAY_FILE, ['Version' => 'Version'], 'plugin');
            if (!empty($plugin_data['Version'])) {
                return $plugin_data['Version'];
            }
        }

        return '1.0.0';
    }
}

/* =========================================================================
 * GATEWAY IDENTITY (BK-13)
 * ========================================================================= */

/**
 * Every gateway id an order of this plugin may carry: the current one and
 * the pre-1.0.2 one.
 *
 * @return string[]
 */
function bank_transfer_gateway_ids()
{
	return [BANK_TRANSFER_GATEWAY_ID, BANK_TRANSFER_LEGACY_GATEWAY_ID];
}

/**
 * Whether an order was placed through this gateway (current or legacy id).
 *
 * @param WC_Order|mixed $order
 * @return bool
 */
function bank_transfer_is_gateway_order($order)
{
	if (!$order instanceof WC_Order) {
		return false;
	}
	return in_array((string) $order->get_payment_method(), bank_transfer_gateway_ids(), true);
}

/* =========================================================================
 * SETTINGS / ACCOUNTS
 * ========================================================================= */

/**
 * The gateway settings (WooCommerce stores them under the gateway id; the
 * pre-1.0.2 option is read until the migration has copied it).
 *
 * @return array
 */
function bank_transfer_get_settings()
{
	$settings = get_option(BANK_TRANSFER_SETTINGS_OPTION, []);
	if (empty($settings)) {
		$settings = get_option(BANK_TRANSFER_LEGACY_SETTINGS_OPTION, []);
	}
	if (is_string($settings)) {
		$settings = maybe_unserialize($settings);
	}
	return is_array($settings) ? $settings : [];
}

/**
 * Whether the gateway runs in sandbox mode.
 *
 * @return bool
 */
function bank_transfer_is_sandbox()
{
	$settings = bank_transfer_get_settings();
	return isset($settings['sandbox']) && $settings['sandbox'] === 'yes';
}

/**
 * The configured merchant accounts. Only this plugin's own option is read;
 * BK-09 removed every fallback to the ByteNFT / Unified plugin options.
 *
 * @return array[] A list of account arrays (never a bare single account).
 */
function bank_transfer_get_accounts()
{
	$accounts = get_option(BANK_TRANSFER_ACCOUNTS_OPTION, []);
	if (is_string($accounts)) {
		$accounts = maybe_unserialize($accounts);
	}
	if (!is_array($accounts)) {
		return [];
	}
	if (isset($accounts['live_secret_key']) || isset($accounts['sandbox_secret_key']) || isset($accounts['live_public_key'])) {
		$accounts = [$accounts];
	}
	return array_values(array_filter($accounts, 'is_array'));
}

/**
 * The configured account a public key belongs to, as
 * ['public_key' => , 'secret_key' => , 'is_sandbox' => ] for the mode the key
 * belongs to, or null when no configured account has that key.
 *
 * @param string $public_key
 * @return array|null
 */
function bank_transfer_get_account_for_public_key($public_key)
{
	$public_key = (string) $public_key;
	if ($public_key === '') {
		return null;
	}
	foreach (bank_transfer_get_accounts() as $account) {
		if (!empty($account['sandbox_public_key']) && hash_equals((string) $account['sandbox_public_key'], $public_key)) {
			return [
				'public_key' => (string) $account['sandbox_public_key'],
				'secret_key' => (string) ($account['sandbox_secret_key'] ?? ''),
				'is_sandbox' => 1,
			];
		}
		if (!empty($account['live_public_key']) && hash_equals((string) $account['live_public_key'], $public_key)) {
			return [
				'public_key' => (string) $account['live_public_key'],
				'secret_key' => (string) ($account['live_secret_key'] ?? ''),
				'is_sandbox' => 0,
			];
		}
	}
	return null;
}

/**
 * The account whose public key created an order's latest payment link, or
 * null when the order's key matches no configured account.
 *
 * @param WC_Order $order
 * @return array|null
 */
function bank_transfer_get_order_account($order)
{
	if (!$order instanceof WC_Order) {
		return null;
	}
	return bank_transfer_get_account_for_public_key((string) $order->get_meta('_bank_transfer_public_key'));
}

/**
 * The public key a given payment link of this order was raised under, from
 * the order's link history, or '' when the history does not name it.
 *
 * @param WC_Order $order
 * @param string   $pay_id
 * @return string
 */
function bank_transfer_public_key_for_pay_id($order, $pay_id)
{
	$pay_id = trim((string) $pay_id);
	if (!$order instanceof WC_Order || $pay_id === '') {
		return '';
	}
	$history = $order->get_meta('_bank_transfer_pay_id_history');
	if (!is_array($history)) {
		return '';
	}
	foreach ($history as $entry) {
		if (
			is_array($entry) &&
			isset($entry['pay_id'], $entry['public_key']) &&
			(string) $entry['pay_id'] !== '' &&
			hash_equals((string) $entry['pay_id'], $pay_id)
		) {
			return (string) $entry['public_key'];
		}
	}
	return '';
}

/**
 * The account that must have signed a webhook about $pay_id (BANK-05).
 *
 * In a shop with several RT accounts, an order can carry links raised under
 * different accounts (for example after a daily-limit failover). RT signs a
 * webhook with the secret of the account that raised THAT link, so the
 * signature is checked against the account the link history names for the
 * payment ID. Only when the history does not name it (orders from before the
 * history existed) is the order's latest account used, as before. The payment
 * ID only chooses which configured account's secret to check; it grants
 * nothing until the signature matches.
 *
 * @param WC_Order $order
 * @param string   $pay_id
 * @return array|null
 */
function bank_transfer_get_webhook_account($order, $pay_id)
{
	$link_key = bank_transfer_public_key_for_pay_id($order, $pay_id);
	if ($link_key !== '') {
		return bank_transfer_get_account_for_public_key($link_key);
	}
	return bank_transfer_get_order_account($order);
}

/**
 * Whether the merchant has entered at least one usable key pair. Nothing is
 * sent to RT before this is true (BK-17: no telemetry before setup).
 *
 * @return bool
 */
function bank_transfer_is_configured()
{
	foreach (bank_transfer_get_accounts() as $account) {
		if (
			(!empty($account['live_public_key']) && !empty($account['live_secret_key'])) ||
			(!empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']))
		) {
			return true;
		}
	}
	return false;
}

/* =========================================================================
 * ACCOUNT STATUS WORDING
 * ========================================================================= */

/**
 * Plain words for each account status, for the settings screen.
 *
 * @return array status => [label, help]
 */
function bank_transfer_account_status_texts()
{
	return [
		'active'        => [
			__('Connected', 'bank-transfer-payment-gateway'),
			__('RT accepted these keys. Shoppers can pay by bank transfer.', 'bank-transfer-payment-gateway'),
		],
		'pending'       => [
			__('Waiting for RT approval', 'bank-transfer-payment-gateway'),
			__('RT has your keys but your account is not approved yet. Shoppers cannot pay by bank transfer until it is. Check your RT account for anything left to finish.', 'bank-transfer-payment-gateway'),
		],
		'inactive'      => [
			__('Not active', 'bank-transfer-payment-gateway'),
			__('This account cannot take payments right now. Check your RT account, or add keys for this mode.', 'bank-transfer-payment-gateway'),
		],
		'invalid'       => [
			__('Keys not accepted', 'bank-transfer-payment-gateway'),
			__('RT did not accept these keys. Check that the publishable and secret keys are from the same RT account and the same mode (Sandbox or Live).', 'bank-transfer-payment-gateway'),
		],
		'not_connected' => [
			__('Could not connect', 'bank-transfer-payment-gateway'),
			__('We could not reach RT to check these keys. Press "Check with RT" to try again.', 'bank-transfer-payment-gateway'),
		],
		'unknown'       => [
			__('Not checked yet', 'bank-transfer-payment-gateway'),
			__('These keys have not been checked with RT yet. Save your changes, then press "Check with RT".', 'bank-transfer-payment-gateway'),
		],
	];
}

/**
 * What to show for one account's status in the current mode.
 *
 * @param string $status     Stored status word.
 * @param bool   $is_sandbox Whether test mode is on.
 * @return array ['class' => string, 'label' => string, 'help' => string]
 */
function bank_transfer_account_status_view($status, $is_sandbox)
{
	$texts  = bank_transfer_account_status_texts();
	$status = strtolower(trim((string) $status));
	if (!isset($texts[$status])) {
		$status = 'unknown';
	}
	$mode = $is_sandbox
		? __('Sandbox', 'bank-transfer-payment-gateway')
		: __('Live', 'bank-transfer-payment-gateway');

	return [
		'class' => str_replace('_', '-', $status),
		'label' => $mode . ': ' . $texts[$status][0],
		'help'  => $texts[$status][1],
	];
}

/**
 * A plain sentence for an order note about what RT says of a payment.
 *
 * @param string $state Engine state word.
 * @return string
 */
function bank_transfer_plain_status_sentence($state)
{
	switch (strtolower((string) $state)) {
		case 'success':
			return __('Checked with RT: the payment has arrived.', 'bank-transfer-payment-gateway');
		case 'processing':
			return __('Checked with RT: the customer has approved the bank transfer and the money is on its way (1 to 5 business days).', 'bank-transfer-payment-gateway');
		case 'pending':
			return __('Checked with RT: the customer has not paid yet.', 'bank-transfer-payment-gateway');
		case 'failed':
			return __('Checked with RT: the payment did not go through. No money was received.', 'bank-transfer-payment-gateway');
		case 'cancelled':
			return __('Checked with RT: the customer cancelled the payment. No money was received.', 'bank-transfer-payment-gateway');
		case 'expired':
			return __('Checked with RT: the payment link closed before the customer paid. No money was received.', 'bank-transfer-payment-gateway');
		case 'returned':
			return __('Checked with RT: the bank returned this payment. The money is no longer in your RT account.', 'bank-transfer-payment-gateway');
		default:
			return __('Checked with RT: there is no news about this payment yet. Try again later.', 'bank-transfer-payment-gateway');
	}
}

/* =========================================================================
 * DATABASE
 * ========================================================================= */

/**
 * This plugin's payment-link table name, with the site prefix (BK-12).
 *
 * @return string
 */
function bank_transfer_table_name()
{
	global $wpdb;
	return $wpdb->prefix . BANK_TRANSFER_TABLE;
}

/* =========================================================================
 * REQUEST SIGNING (BK-10)
 * ========================================================================= */

/**
 * Headers for a call to the RT payment API: the public key as bearer and an
 * HMAC-SHA256 signature of "<timestamp>.<raw body>" made with the secret key.
 * The secret itself is never sent.
 *
 * @param string $public_key
 * @param string $secret_key
 * @param string $raw_body   The exact body bytes that will be sent.
 * @param string $content_type
 * @return array
 */
function bank_transfer_signed_headers($public_key, $secret_key, $raw_body, $content_type = 'application/json')
{
	$timestamp = (string) time();
	return [
		'Content-Type'  => $content_type,
		'Accept'        => 'application/json',
		'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
		'X-Timestamp'   => $timestamp,
		'X-Signature'   => hash_hmac('sha256', $timestamp . '.' . (string) $raw_body, (string) $secret_key),
	];
}

/**
 * Proof of an account's secret for /sync-account-status: a signature over
 * "<timestamp>.<public_key>", so the secret stays on the shop.
 *
 * @param string $public_key
 * @param string $secret_key
 * @return array ['timestamp' => string, 'signature' => string]
 */
function bank_transfer_account_signature($public_key, $secret_key)
{
	$timestamp = (string) time();
	return [
		'timestamp' => $timestamp,
		'signature' => hash_hmac('sha256', $timestamp . '.' . (string) $public_key, (string) $secret_key),
	];
}

/* =========================================================================
 * PAYMENT CLAIM VALIDATION (BK-05)
 * ========================================================================= */

/**
 * Format an amount the way every comparison in this plugin does: two
 * decimals, dot separator, no thousands separator.
 *
 * @param mixed $amount
 * @return string
 */
function bank_transfer_format_amount($amount)
{
	return number_format((float) $amount, 2, '.', '');
}

/**
 * The one check every status path runs before an order is treated as paid:
 * the payment id must be one the order was issued, the amount must equal
 * the order total exactly, the currency must match, and an order reference
 * in the payload must name this order. Fails closed: a missing field refuses.
 *
 * @param WC_Order $order
 * @param array    $data      The payload (webhook body or status response).
 * @param string   $pay_id    The payment id the payload is about.
 * @param string[] $known_ids Every payment id the order was issued.
 * @return true|WP_Error
 */
function bank_transfer_verify_payment_claim($order, array $data, $pay_id, array $known_ids)
{
	if (!$order instanceof WC_Order) {
		return new WP_Error('bank_transfer_no_order', 'Order not found.');
	}

	$pay_id = trim((string) $pay_id);
	if ($pay_id === '') {
		return new WP_Error('bank_transfer_pay_id_required', 'Payment ID required.');
	}
	$id_matches = false;
	foreach ($known_ids as $known_id) {
		if ($known_id !== '' && hash_equals((string) $known_id, $pay_id)) {
			$id_matches = true;
			break;
		}
	}
	if (!$id_matches) {
		return new WP_Error('bank_transfer_pay_id_mismatch', 'Payment ID mismatch.');
	}

	$raw_amount = $data['amount'] ?? ($data['total_amount'] ?? null);
	if ($raw_amount === null || $raw_amount === '' || !is_numeric($raw_amount)) {
		return new WP_Error('bank_transfer_amount_required', 'Amount required.');
	}
	if (bank_transfer_format_amount($raw_amount) !== bank_transfer_format_amount($order->get_total())) {
		return new WP_Error('bank_transfer_amount_mismatch', 'Amount mismatch.');
	}

	$raw_currency = $data['curr_code'] ?? ($data['currency'] ?? '');
	if (!is_string($raw_currency) || trim($raw_currency) === '') {
		return new WP_Error('bank_transfer_currency_required', 'Currency required.');
	}
	if (strtoupper(trim(sanitize_text_field($raw_currency))) !== strtoupper(trim((string) $order->get_currency()))) {
		return new WP_Error('bank_transfer_currency_mismatch', 'Currency mismatch.');
	}

	$raw_order_id = $data['order_id'] ?? ($data['order_reference'] ?? ($data['meta_data']['order_id'] ?? null));
	if ($raw_order_id !== null && $raw_order_id !== '' && !is_array($raw_order_id)) {
		if ((string) absint($raw_order_id) !== (string) $order->get_id()) {
			return new WP_Error('bank_transfer_order_mismatch', 'Order mismatch.');
		}
	}

	return true;
}

/* =========================================================================
 * RATE LIMIT SUBJECT (BK-17)
 * ========================================================================= */

/**
 * Who a public request is counted against: the WooCommerce session when
 * there is one, else the client IP as WooCommerce resolves it (so one
 * shopper behind a CDN cannot lock checkout for everyone sharing that IP).
 *
 * @return string
 */
function bank_transfer_rate_limit_subject()
{
	if (function_exists('WC') && WC() && WC()->session && method_exists(WC()->session, 'get_customer_id')) {
		$customer_id = (string) WC()->session->get_customer_id();
		if ($customer_id !== '') {
			return 'session:' . $customer_id;
		}
	}
	$ip = class_exists('WC_Geolocation') ? WC_Geolocation::get_ip_address() : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
	return 'ip:' . (filter_var($ip, FILTER_VALIDATE_IP) ?: 'unknown');
}

/**
 * An IP fit for a log line (last octet / 80 bits dropped).
 *
 * @param string $ip
 * @return string
 */
function bank_transfer_anonymize_ip($ip)
{
	if (function_exists('wp_privacy_anonymize_ip')) {
		return wp_privacy_anonymize_ip((string) $ip);
	}
	return '***';
}

/* =========================================================================
 * ENVIRONMENT / ALERTS (BK-01, BK-04, BK-18)
 * ========================================================================= */

/**
 * Whether this site is a production site (WP_ENVIRONMENT_TYPE). WordPress
 * reports 'production' when nothing is configured, so a sandbox warning
 * errs on the safe side.
 *
 * @return bool
 */
function bank_transfer_is_production_environment()
{
	$type = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
	return apply_filters('rt_bank_transfer_is_production_environment', $type === 'production', $type);
}

/**
 * Tell the shop owner about something that needs a human: a returned
 * payment, a duplicate payment. E-mail to the admin address plus a WooCommerce
 * order note; both filterable.
 *
 * @param string        $subject
 * @param string        $message Plain text.
 * @param WC_Order|null $order
 */
function bank_transfer_notify_admin($subject, $message, $order = null)
{
	if ($order instanceof WC_Order) {
		$order->add_order_note('<strong>Bank Transfer Gateway</strong><br><br>' . esc_html($message));
	}

	$to = apply_filters('rt_bank_transfer_alert_email', get_option('admin_email'), $order);
	if (empty($to) || !is_email($to)) {
		return;
	}

	$site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
	$body = $message;
	if ($order instanceof WC_Order) {
		$body .= "\n\n" . sprintf(
			/* translators: %s: order edit URL */
			__('Order: %s', 'bank-transfer-payment-gateway'),
			$order->get_edit_order_url()
		);
	}

	wp_mail(
		$to,
		sprintf('[%s] %s', $site, $subject),
		$body
	);
}
