<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php

/*
 * Where this plugin talks to RT. ONE setting decides it: RTBTCH_API_BASE_URL.
 *
 * Production (the default, for Live AND Sandbox keys) is
 * https://app.rt.app/payment-api. Sandbox mode does not use a different
 * address: the same service answers both, and the keys plus the is_sandbox
 * flag in each request decide the mode.
 *
 * A test shop can point at another RT environment without editing code:
 *
 *   add_filter('rtbtch_api_base', function () { return 'https://<test-environment>.rt.app/payment-api'; });
 *
 * (in a must-use plugin, so it runs before this file), or by defining
 * RTBTCH_API_BASE_URL in wp-config.php. The older
 * rtbtch_api_host filter (a bare host) is still honoured.
 * The address is always HTTPS and must be on rt.app, so a shop's keys and its
 * customers' details can never be sent anywhere else by a filter.
 */
if (!defined('RTBTCH_DEFAULT_API_BASE_URL')) {
	define('RTBTCH_DEFAULT_API_BASE_URL', 'https://app.rt.app/payment-api');
}

if (!function_exists('rtbtch_clean_api_base')) {
	/**
	 * A safe API base: https, a host on rt.app, no trailing slash. Anything
	 * else falls back to production.
	 *
	 * @param mixed $candidate
	 * @return string
	 */
	function rtbtch_clean_api_base($candidate)
	{
		$candidate = is_string($candidate) ? trim($candidate) : '';
		if ($candidate === '') {
			return RTBTCH_DEFAULT_API_BASE_URL;
		}
		if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate)) {
			$candidate = 'https://' . $candidate;
		}
		$parts = wp_parse_url($candidate);
		$host  = is_array($parts) && !empty($parts['host']) ? strtolower((string) $parts['host']) : '';
		if ($host === '' || !($host === 'rt.app' || substr($host, -7) === '.rt.app')) {
			return RTBTCH_DEFAULT_API_BASE_URL;
		}
		$path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
		if ($path === '') {
			$path = '/payment-api';
		}
		return 'https://' . $host . (!empty($parts['port']) ? ':' . (int) $parts['port'] : '') . $path;
	}
}

if (!defined('RTBTCH_API_BASE_URL')) {
	$rtbtch_api_base = RTBTCH_DEFAULT_API_BASE_URL;
	// Older filter: a bare host name.
	$rtbtch_legacy_host = apply_filters('rtbtch_api_host', '');
	if (is_string($rtbtch_legacy_host) && trim($rtbtch_legacy_host) !== '') {
		$rtbtch_api_base = 'https://' . preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', rtrim(trim($rtbtch_legacy_host), '/')) . '/payment-api';
	}
	define('RTBTCH_API_BASE_URL', rtbtch_clean_api_base(apply_filters('rtbtch_api_base', $rtbtch_api_base)));
}

/* Derived from the API base; never set separately. Used for links to RT pages
 * (Developers) and to recognise RT's own origin. */
if (!defined('RTBTCH_PROTOCOL')) {
	define('RTBTCH_PROTOCOL', 'https://');
}
if (!defined('RTBTCH_HOST')) {
	define('RTBTCH_HOST', (string) wp_parse_url(RTBTCH_API_BASE_URL, PHP_URL_HOST));
}
if (!defined('RTBTCH_BASE_URL')) {
	define('RTBTCH_BASE_URL', RTBTCH_PROTOCOL . RTBTCH_HOST);
}

if (!defined('RTBTCH_PLUGIN_VERSION')) {
    define('RTBTCH_PLUGIN_VERSION', '1.0.6');
}

/*
 * BK-13: every identifier this plugin registers with WordPress / WooCommerce
 * carries the rt_ prefix so it cannot collide with another "bank transfer"
 * plugin. Orders placed before 1.0.2 still carry the legacy gateway id, so
 * both are recognised when reading an order (rtbtch_is_gateway_order()).
 */
if (!defined('RTBTCH_GATEWAY_ID')) {
	define('RTBTCH_GATEWAY_ID', 'rt_bank_transfer');
}
if (!defined('RTBTCH_LEGACY_GATEWAY_ID')) {
	define('RTBTCH_LEGACY_GATEWAY_ID', 'bank_transfer');
}

/* REST namespace of the return / webhook endpoint. The legacy namespaces stay
 * registered as aliases for payment links issued before 1.0.2. */
if (!defined('RTBTCH_REST_NAMESPACE')) {
	define('RTBTCH_REST_NAMESPACE', 'rt-bank-transfer/v1');
}

/* BK-12: this plugin's own table (never shared with the NFT checkout plugin). */
if (!defined('RTBTCH_TABLE')) {
	define('RTBTCH_TABLE', 'rtbtch_payment_link');
}

/* Cron hook and interval names. */
if (!defined('RTBTCH_CRON_HOOK')) {
	define('RTBTCH_CRON_HOOK', 'rtbtch_cron_event');
}
if (!defined('RTBTCH_CRON_INTERVAL')) {
	define('RTBTCH_CRON_INTERVAL', 'rtbtch_every_two_hours');
}
if (!defined('RTBTCH_ASYNC_SYNC_HOOK')) {
	define('RTBTCH_ASYNC_SYNC_HOOK', 'rtbtch_async_sync_order');
}

/* BK-01: WooCommerce order status a paid order moves to when the bank returns
 * the ACH debit (without the wc- prefix, as WooCommerce uses it). */
if (!defined('RTBTCH_RETURNED_STATUS')) {
	define('RTBTCH_RETURNED_STATUS', 'rtbtch-returned');
}

/* Settings / option names. */
if (!defined('RTBTCH_SETTINGS_OPTION')) {
	define('RTBTCH_SETTINGS_OPTION', 'woocommerce_' . RTBTCH_GATEWAY_ID . '_settings');
}
if (!defined('RTBTCH_LEGACY_SETTINGS_OPTION')) {
	define('RTBTCH_LEGACY_SETTINGS_OPTION', 'woocommerce_' . RTBTCH_LEGACY_GATEWAY_ID . '_settings');
}
if (!defined('RTBTCH_ACCOUNTS_OPTION')) {
	define('RTBTCH_ACCOUNTS_OPTION', 'rtbtch_accounts');
}
