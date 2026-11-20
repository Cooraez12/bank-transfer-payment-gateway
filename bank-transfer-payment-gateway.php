<?php

/**
 * Plugin Name: RT Bank Transfer Checkout
 * Description: Accept ACH bank transfer payments in WooCommerce through RT's hosted payment page.
 * Author: RT.app
 * Author URI: https://rt.app/
 * Text Domain: rt-bank-transfer-checkout
 * Plugin URI: https://rt.app/accept-payments/
 * Version: 1.0.5
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 6.5.4
 * WC tested up to: 11.3
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright (c) 2024 RT
 */


if (!defined('ABSPATH')) {
	exit;
}

define('RTBTCH_GATEWAY_MIN_PHP_VER', '8.0');
define('RTBTCH_GATEWAY_MIN_WC_VER', '6.5.4');
define('RTBTCH_GATEWAY_FILE', __FILE__);
define('RTBTCH_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Declare HPOS and block-checkout compatibility
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', RTBTCH_GATEWAY_FILE, true);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', RTBTCH_GATEWAY_FILE, true);
	}
});

// Include utility functions
require_once RTBTCH_GATEWAY_PLUGIN_DIR . 'includes/config.php';
require_once RTBTCH_GATEWAY_PLUGIN_DIR . 'includes/rt-bank-transfer-payment-gateway-utils.php';

// Migrations functions
include_once plugin_dir_path(__FILE__) . 'migration.php';

// Classes that do not depend on WooCommerce being loaded. The gateway and
// block-checkout classes extend WooCommerce classes and are loaded later, on
// plugins_loaded, only when WooCommerce is active.
require_once RTBTCH_GATEWAY_PLUGIN_DIR . 'includes/class-bank-transfer-payment-logger.php';
require_once RTBTCH_GATEWAY_PLUGIN_DIR . 'includes/class-bank-transfer-payment-gateway-admin-notices.php';
require_once RTBTCH_GATEWAY_PLUGIN_DIR . 'includes/class-bank-transfer-payment-state-engine.php';
require_once RTBTCH_GATEWAY_PLUGIN_DIR . 'includes/class-bank-transfer-payment-gateway-rest-api.php';
require_once RTBTCH_GATEWAY_PLUGIN_DIR . 'includes/class-bank-transfer-payment-gateway-loader.php';

RTBTCH_GATEWAY_Loader::get_instance();

// BT-42: Ensure tables and migrations run on plugin update. On 'init' (after
// WooCommerce's own init at priority 0) so the row migration can load orders.
add_action('init', 'rtbtch_maybe_upgrade', 20);

/* =========================================================================
 * BK-01: "Payment returned" order status
 * ========================================================================= */

add_action('init', function () {
	register_post_status('wc-' . RTBTCH_RETURNED_STATUS, [
		'label'                     => _x('Payment returned', 'Order status', 'rt-bank-transfer-checkout'),
		'public'                    => false,
		'exclude_from_search'       => false,
		'show_in_admin_all_list'    => true,
		'show_in_admin_status_list' => true,
		/* translators: %s: number of orders */
		'label_count'               => _n_noop('Payment returned <span class="count">(%s)</span>', 'Payment returned <span class="count">(%s)</span>', 'rt-bank-transfer-checkout'),
	]);
});

add_filter('wc_order_statuses', function ($statuses) {
	$statuses['wc-' . RTBTCH_RETURNED_STATUS] = _x('Payment returned', 'Order status', 'rt-bank-transfer-checkout');
	return $statuses;
});

// A returned order is not "paid": keep WooCommerce's paid-status helpers honest.
add_filter('woocommerce_order_is_paid_statuses', function ($statuses) {
	return array_values(array_diff((array) $statuses, [RTBTCH_RETURNED_STATUS]));
});

/* =========================================================================
 * BK-03: never cancel an order whose ACH debit is in flight
 * ========================================================================= */

/**
 * WooCommerce's "hold stock" timer cancels unpaid pending orders. While RT
 * reports the debit as processing the order is not unpaid: it is waiting for
 * settlement. Refresh the status from RT first, then refuse the cancellation
 * when the money is on its way (or already arrived).
 */
add_filter('woocommerce_cancel_unpaid_order', function ($cancel, $order) {
	if (!$cancel || !rtbtch_is_gateway_order($order)) {
		return $cancel;
	}
	return !rtbtch_order_payment_is_live($order);
}, 10, 2);

// The customer's "cancel order" link only applies to orders with no live payment.
add_filter('woocommerce_valid_order_statuses_for_cancel', function ($statuses, $order = null) {
	if ($order instanceof WC_Order && rtbtch_is_gateway_order($order) && RTBTCH_State_Engine::is_in_flight($order)) {
		return [];
	}
	return $statuses;
}, 10, 2);

/**
 * Whether an order's payment is in flight or settled according to RT (after a
 * live status refresh), so it must not be cancelled.
 *
 * @param WC_Order $order
 * @return bool
 */
function rtbtch_order_payment_is_live($order)
{
	if (!rtbtch_is_gateway_order($order)) {
		return false;
	}
	if ($order->has_status(['processing', 'completed', 'on-hold', RTBTCH_RETURNED_STATUS])) {
		return true;
	}
	if (class_exists('RTBTCH_GATEWAY_Loader')) {
		$state = RTBTCH_GATEWAY_Loader::get_instance()->sync_single_order_status($order);
		if (in_array($state, ['processing', 'success'], true)) {
			return true;
		}
	}
	return RTBTCH_State_Engine::is_in_flight(wc_get_order($order->get_id()));
}

add_action('woocommerce_order_status_cancelled', 'rtbtch_cancel_unpaid_order_action');

/**
 * Cancels an unpaid order payment link on the remote API.
 *
 * @param int $order_id The ID of the order to cancel.
 */
function rtbtch_cancel_unpaid_order_action($order_id)
{
	global $wpdb;

	if (empty($order_id) || !is_numeric($order_id) || $order_id <= 0) {
		return;
	}

	$order = wc_get_order($order_id);

	if (!$order || !is_a($order, 'WC_Order')) {
		return;
	}

	if (!rtbtch_is_gateway_order($order)) {
		return;
	}

	$cache_key   = 'rtbtch_payment_row_' . intval($order_id);
	$cache_group = 'rtbtch_payment_gateway';

	wp_cache_delete('rtbtch_payment_link_uuid_' . intval($order_id), $cache_group);
	wp_cache_delete($cache_key, $cache_group);

	$table_name = esc_sql(rtbtch_table_name());
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$payment_row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
			intval($order_id)
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$uuid = sanitize_text_field($payment_row['uuid'] ?? ($order->get_meta('_rtbtch_pay_id') ?: ''));

	if (empty($uuid)) {
		return;
	}

	// BK-03: an admin cancelled the order, but if RT says the debit is in flight
	// (or settled) the link must stay open: the money is coming and the webhook
	// will revive the order. Only an unstarted link is cancelled at RT.
	if (class_exists('RTBTCH_GATEWAY_Loader')) {
		$state = RTBTCH_GATEWAY_Loader::get_instance()->sync_single_order_status($order);
		if (in_array($state, ['processing', 'success'], true)) {
			$order->add_order_note(
				'<strong>Bank Transfer Gateway</strong><br><br>' .
				esc_html__('This order was cancelled while the customer\'s bank transfer was already on its way. The payment could not be stopped. This order will be updated when the money arrives or the payment fails; if it arrives, refund it in your RT account.', 'rt-bank-transfer-checkout')
			);
			return;
		}
	}

	$account = rtbtch_get_order_account($order);

	if (!is_array($account) || empty($account['secret_key'])) {
		RTBTCH_Logger::warning("Cancel skipped: No matching account found for Order ID: {$order_id}", [
			'source'   => 'rt-bank-transfer-checkout',
			'order_id' => $order_id,
		]);
		return;
	}

	$api_base = defined('RTBTCH_API_BASE_URL') ? RTBTCH_API_BASE_URL : (RTBTCH_BASE_URL . '/payment-api');
	$url      = $api_base . '/cancel-order-link';
	$cleanUrl = esc_url_raw(preg_replace('#(?<!:)//+#', '/', $url));

	$request_payload = [
		'order_id'   => intval($order_id),
		'order_uuid' => $uuid,
		'status'     => 'canceled',
		'is_sandbox' => (int) $account['is_sandbox'],
	];

	$raw_body = wp_json_encode($request_payload);

	$response = wp_remote_post($cleanUrl, [
		'method'    => 'POST',
		'timeout'   => 15,
		'body'      => $raw_body,
		'headers'   => rtbtch_signed_headers($account['public_key'], $account['secret_key'], $raw_body),
		'sslverify' => true,
	]);

	if (is_wp_error($response)) {
		RTBTCH_Logger::error("Cancel API call failed for Order ID: {$order_id}", [
			'source'  => 'rt-bank-transfer-checkout',
			'order_id' => $order_id,
		]);
	} else {
		$response_code = wp_remote_retrieve_response_code($response);
		if ($response_code >= 200 && $response_code < 300) {
			RTBTCH_Logger::info("Cancel API successful for Order ID: {$order_id}.", [
				'source'   => 'rt-bank-transfer-checkout',
				'order_id' => $order_id,
			]);
		} else {
			RTBTCH_Logger::warning("Cancel API returned non-200 code for Order ID: {$order_id}.", [
				'source'        => 'rt-bank-transfer-checkout',
				'order_id'      => $order_id,
				'response_code' => $response_code,
			]);
		}
	}
}

/* =========================================================================
 * BK-18: sandbox mode on a production site
 * ========================================================================= */

add_action('admin_notices', function () {
	// Shown only on this plugin's settings screen and WooCommerce order screens.
	if (!current_user_can('manage_woocommerce') || !rtbtch_is_sandbox() || !rtbtch_is_own_admin_screen(false)) {
		return;
	}
	$settings = rtbtch_get_settings();
	if (($settings['enabled'] ?? 'no') !== 'yes') {
		return;
	}
	$class = rtbtch_is_production_environment() ? 'notice notice-error' : 'notice notice-warning';
	echo '<div class="' . esc_attr($class) . '"><p><strong>' .
		esc_html__('RT Bank Transfer: test mode is on.', 'rt-bank-transfer-checkout') . '</strong> ' .
		esc_html__('Bank transfer orders are test orders and no real money is received.', 'rt-bank-transfer-checkout');
	if (rtbtch_is_production_environment()) {
		echo ' ' . esc_html__('Because this is a live shop, test orders are put On hold instead of being marked paid. Turn test mode off in the RT Bank Transfer settings before taking real orders.', 'rt-bank-transfer-checkout');
	} else {
		echo ' ' . esc_html__('Turn test mode off in the RT Bank Transfer settings before taking real orders.', 'rt-bank-transfer-checkout');
	}
	echo '</p></div>';
});

// Test orders are labelled in the orders list so nobody fulfils one by mistake.
add_filter('woocommerce_admin_order_buyer_name', function ($buyer, $order) {
	if ($order instanceof WC_Order && rtbtch_is_gateway_order($order) && $order->get_meta('_rtbtch_is_test_order')) {
		return $buyer . ' [' . __('TEST', 'rt-bank-transfer-checkout') . ']';
	}
	return $buyer;
}, 10, 2);
