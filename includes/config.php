<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php

/*
 * Where this plugin talks to RT. ONE setting decides it: BANK_TRANSFER_API_BASE_URL.
 *
 * Production (the default, for Live AND Sandbox keys) is
 * https://app.rt.app/payment-api. Sandbox mode does not use a different
 * address: the same service answers both, and the keys plus the is_sandbox
 * flag in each request decide the mode.
 *
 * A test shop can point at another RT environment without editing code:
 *
 *   add_filter('rt_payment_api_base', function () { return 'https://dev.rt.app/payment-api'; });
 *
 * (in a must-use plugin, so it runs before this file), or by defining
 * BANK_TRANSFER_API_BASE_URL in wp-config.php. The older
 * bank_transfer_api_host filter (a bare host) is still honoured.
 * The address is always HTTPS and must be on rt.app, so a shop's keys and its
 * customers' details can never be sent anywhere else by a filter.
 */
if (!defined('BANK_TRANSFER_DEFAULT_API_BASE_URL')) {
	define('BANK_TRANSFER_DEFAULT_API_BASE_URL', 'https://app.rt.app/payment-api');
}

if (!function_exists('bank_transfer_clean_api_base')) {
	/**
	 * A safe API base: https, a host on rt.app, no trailing slash. Anything
	 * else falls back to production.
	 *
	 * @param mixed $candidate
	 * @return string
	 */
	function bank_transfer_clean_api_base($candidate)
	{
		$candidate = is_string($candidate) ? trim($candidate) : '';
		if ($candidate === '') {
			return BANK_TRANSFER_DEFAULT_API_BASE_URL;
		}
		if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate)) {
			$candidate = 'https://' . $candidate;
		}
		$parts = function_exists('wp_parse_url') ? wp_parse_url($candidate) : parse_url($candidate);
		$host  = is_array($parts) && !empty($parts['host']) ? strtolower((string) $parts['host']) : '';
		if ($host === '' || !($host === 'rt.app' || substr($host, -7) === '.rt.app')) {
			return BANK_TRANSFER_DEFAULT_API_BASE_URL;
		}
		$path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
		if ($path === '') {
			$path = '/payment-api';
		}
		return 'https://' . $host . (!empty($parts['port']) ? ':' . (int) $parts['port'] : '') . $path;
	}
}

if (!defined('BANK_TRANSFER_API_BASE_URL')) {
	$bank_transfer_api_base = BANK_TRANSFER_DEFAULT_API_BASE_URL;
	// Older filter: a bare host name.
	$bank_transfer_legacy_host = apply_filters('bank_transfer_api_host', '');
	if (is_string($bank_transfer_legacy_host) && trim($bank_transfer_legacy_host) !== '') {
		$bank_transfer_api_base = 'https://' . preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', rtrim(trim($bank_transfer_legacy_host), '/')) . '/payment-api';
	}
	define('BANK_TRANSFER_API_BASE_URL', bank_transfer_clean_api_base(apply_filters('rt_payment_api_base', $bank_transfer_api_base, 'bank-transfer-payment-gateway')));
}

/* Derived from the API base; never set separately. Used for links to RT pages
 * (Developers) and to recognise RT's own origin. */
if (!defined('BANK_TRANSFER_PROTOCOL')) {
	define('BANK_TRANSFER_PROTOCOL', 'https://');
}
if (!defined('BANK_TRANSFER_HOST')) {
	define('BANK_TRANSFER_HOST', (string) parse_url(BANK_TRANSFER_API_BASE_URL, PHP_URL_HOST));
}
if (!defined('BANK_TRANSFER_BASE_URL')) {
	define('BANK_TRANSFER_BASE_URL', BANK_TRANSFER_PROTOCOL . BANK_TRANSFER_HOST);
}

if (!defined('BANK_TRANSFER_PLUGIN_VERSION')) {
    define('BANK_TRANSFER_PLUGIN_VERSION', '1.0.2');
}

/*
 * BK-13: every identifier this plugin registers with WordPress / WooCommerce
 * carries the rt_ prefix so it cannot collide with another "bank transfer"
 * plugin. Orders placed before 1.0.2 still carry the legacy gateway id, so
 * both are recognised when reading an order (bank_transfer_is_gateway_order()).
 */
if (!defined('BANK_TRANSFER_GATEWAY_ID')) {
	define('BANK_TRANSFER_GATEWAY_ID', 'rt_bank_transfer');
}
if (!defined('BANK_TRANSFER_LEGACY_GATEWAY_ID')) {
	define('BANK_TRANSFER_LEGACY_GATEWAY_ID', 'bank_transfer');
}

/* REST namespace of the return / webhook endpoint. The legacy namespaces stay
 * registered as aliases for payment links issued before 1.0.2. */
if (!defined('BANK_TRANSFER_REST_NAMESPACE')) {
	define('BANK_TRANSFER_REST_NAMESPACE', 'rt-bank-transfer/v1');
}

/* BK-12: this plugin's own table (never shared with the NFT checkout plugin). */
if (!defined('BANK_TRANSFER_TABLE')) {
	define('BANK_TRANSFER_TABLE', 'rt_bank_transfer_payment_link');
}

/* Cron hook and interval names. */
if (!defined('BANK_TRANSFER_CRON_HOOK')) {
	define('BANK_TRANSFER_CRON_HOOK', 'rt_bank_transfer_cron_event');
}
if (!defined('BANK_TRANSFER_CRON_INTERVAL')) {
	define('BANK_TRANSFER_CRON_INTERVAL', 'rt_bank_transfer_every_two_hours');
}
if (!defined('BANK_TRANSFER_ASYNC_SYNC_HOOK')) {
	define('BANK_TRANSFER_ASYNC_SYNC_HOOK', 'rt_bank_transfer_async_sync_order');
}

/* BK-01: WooCommerce order status a paid order moves to when the bank returns
 * the ACH debit (without the wc- prefix, as WooCommerce uses it). */
if (!defined('BANK_TRANSFER_RETURNED_STATUS')) {
	define('BANK_TRANSFER_RETURNED_STATUS', 'rt-pay-returned');
}

/* Settings / option names. */
if (!defined('BANK_TRANSFER_SETTINGS_OPTION')) {
	define('BANK_TRANSFER_SETTINGS_OPTION', 'woocommerce_' . BANK_TRANSFER_GATEWAY_ID . '_settings');
}
if (!defined('BANK_TRANSFER_LEGACY_SETTINGS_OPTION')) {
	define('BANK_TRANSFER_LEGACY_SETTINGS_OPTION', 'woocommerce_' . BANK_TRANSFER_LEGACY_GATEWAY_ID . '_settings');
}
if (!defined('BANK_TRANSFER_ACCOUNTS_OPTION')) {
	define('BANK_TRANSFER_ACCOUNTS_OPTION', 'woocommerce_bank_transfer_payment_gateway_accounts');
}
