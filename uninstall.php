<?php
/**
 * Fired when the plugin is uninstalled.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

function rtbtch_uninstall() {
	global $wpdb;

	wp_clear_scheduled_hook('rtbtch_cron_event');
	wp_clear_scheduled_hook('rtbtch_async_sync_order');

	$rtbtch_options = [
		'woocommerce_rt_bank_transfer_settings',
		'rtbtch_accounts',
		'rtbtch_group_id',
		'rtbtch_plugin_version',
		'rtbtch_schema_version',
		'rtbtch_legacy_rows_migrated',
	];
	foreach ($rtbtch_options as $rtbtch_option) {
		delete_option($rtbtch_option);
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

	// This plugin's lock options and transients.
	$patterns = [
		$wpdb->esc_like('rtbtch_lock_') . '%',
		$wpdb->esc_like('_transient_rtbtch_') . '%',
		$wpdb->esc_like('_transient_timeout_rtbtch_') . '%',
	];
	foreach ($patterns as $pattern) {
		$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $pattern));
	}

	// This plugin's own payment-link table.
	$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'rtbtch_payment_link'));

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}
rtbtch_uninstall();
