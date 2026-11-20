<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Schema version written once every step below has run. Bump it whenever a
 * step is added; the main plugin file runs the steps when the stored version
 * is older.
 */
define('RTBTCH_SCHEMA_VERSION', '1.3.1');

/**
 * BK-12: this plugin's own payment-link table. The pre-1.0.2 plugin shared
 * {prefix}order_payment_link with the NFT checkout plugin, each defining the
 * columns differently, so whichever ran dbDelta last rewrote the other's
 * columns. That table is left untouched for the other plugin.
 */
function rtbtch_create_tables()
{
	global $wpdb;
	$table_name      = rtbtch_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	// TEXT columns get no literal default: MySQL rejects one.
	$sql = "CREATE TABLE $table_name (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  uuid varchar(191) NOT NULL DEFAULT '',
  payment_link text NULL,
  customer_email varchar(191) NOT NULL DEFAULT '',
  amount varchar(50) NOT NULL DEFAULT '0.00',
  created_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY uuid (uuid)
) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);

	return rtbtch_payment_link_table_exists();
}

/**
 * Whether this plugin's payment-link table exists.
 *
 * @return bool
 */
function rtbtch_payment_link_table_exists()
{
	global $wpdb;
	$table_name = rtbtch_table_name();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
}

/**
 * 1.0.5 renamed every stored name to the plugin's own "rtbtch" prefix. Move a
 * store's existing settings, order data, order statuses and payment-link rows
 * to the new names once, so an update keeps working. Old names are only read
 * and removed here; nothing is written under them.
 */
function rtbtch_migrate_pre_105_names()
{
	global $wpdb;

	$option_moves = [
		'woocommerce_bank_transfer_payment_gateway_accounts' => RTBTCH_ACCOUNTS_OPTION,
		'bank_transfer_group_id'                             => 'rtbtch_group_id',
		'bank_transfer_legacy_rows_migrated'                 => 'rtbtch_legacy_rows_migrated',
	];
	foreach ($option_moves as $old_name => $new_name) {
		$old_value = get_option($old_name, null);
		if ($old_value === null) {
			continue;
		}
		if (get_option($new_name, null) === null) {
			update_option($new_name, $old_value, false);
		}
		delete_option($old_name);
	}
	delete_option('bank_transfer_schema_version');
	delete_option('bank_transfer_plugin_version');

	foreach (['rt_bank_transfer_cron_event', 'rt_bank_transfer_async_sync_order', 'bank_transfer_cron_event'] as $old_hook) {
		wp_clear_scheduled_hook($old_hook);
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$old_meta_like = $wpdb->esc_like('_bank_transfer_') . '%';
	$new_meta_like = $wpdb->esc_like('_rtbtch_') . '%';
	$meta_tables   = [[$wpdb->postmeta, 'post_id']];
	$hpos_meta     = $wpdb->prefix . 'wc_orders_meta';
	$hpos_orders   = $wpdb->prefix . 'wc_orders';
	$has_hpos      = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta)) === $hpos_meta;
	if ($has_hpos) {
		$meta_tables[] = [$hpos_meta, 'order_id'];
	}
	foreach ($meta_tables as [$meta_table, $id_column]) {
		$wpdb->query($wpdb->prepare(
			'UPDATE %i SET meta_key = CONCAT(%s, SUBSTRING(meta_key, 16)) WHERE meta_key LIKE %s',
			$meta_table,
			'_rtbtch_',
			$old_meta_like
		));
		// The test-order flag had no prefix: move it only on this plugin's orders.
		$wpdb->query($wpdb->prepare(
			'UPDATE %i AS a INNER JOIN %i AS b ON a.%i = b.%i AND b.meta_key LIKE %s SET a.meta_key = %s WHERE a.meta_key = %s',
			$meta_table,
			$meta_table,
			$id_column,
			$id_column,
			$new_meta_like,
			'_rtbtch_is_test_order',
			'_is_test_order'
		));
	}

	// The "Payment returned" order status was renamed.
	$wpdb->query($wpdb->prepare('UPDATE %i SET post_status = %s WHERE post_status = %s', $wpdb->posts, 'wc-' . RTBTCH_RETURNED_STATUS, 'wc-rt-pay-returned'));
	if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_orders)) === $hpos_orders) {
		$wpdb->query($wpdb->prepare('UPDATE %i SET status = %s WHERE status = %s', $hpos_orders, 'wc-' . RTBTCH_RETURNED_STATUS, 'wc-rt-pay-returned'));
	}

	$old_table = $wpdb->prefix . 'rt_bank_transfer_payment_link';
	$new_table = rtbtch_table_name();
	if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old_table)) === $old_table && rtbtch_payment_link_table_exists()) {
		$old_rows = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $old_table));
		$copied   = 0;
		if ($old_rows > 0) {
			$copied = $wpdb->query($wpdb->prepare(
				'INSERT INTO %i (order_id, uuid, payment_link, customer_email, amount, created_at) SELECT order_id, uuid, payment_link, customer_email, amount, created_at FROM %i',
				$new_table,
				$old_table
			));
		}
		// Remove the old table only when every row reached the new one.
		if ((int) $copied === $old_rows) {
			$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $old_table));
		}
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

/**
 * Copy this plugin's rows out of the shared legacy table (orders paid through
 * this gateway only), once. Rows already present in the new table are kept.
 */
function rtbtch_migrate_legacy_rows()
{
	global $wpdb;

	if (get_option('rtbtch_legacy_rows_migrated')) {
		return;
	}

	$legacy_table = esc_sql($wpdb->prefix . 'order_payment_link');
	$new_table    = esc_sql(rtbtch_table_name());

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy_table));
	if ($exists === $legacy_table) {
		$rows = $wpdb->get_results("SELECT order_id, uuid, payment_link, customer_email, amount, created_at FROM {$legacy_table}", ARRAY_A);
		foreach ((array) $rows as $row) {
			$order = wc_get_order((int) ($row['order_id'] ?? 0));
			if (!rtbtch_is_gateway_order($order)) {
				continue;
			}
			$already = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$new_table} WHERE order_id = %d LIMIT 1", (int) $row['order_id']));
			if ($already) {
				continue;
			}
			$wpdb->insert($new_table, [
				'order_id'       => (int) $row['order_id'],
				'uuid'           => sanitize_text_field((string) ($row['uuid'] ?? '')),
				'payment_link'   => esc_url_raw((string) ($row['payment_link'] ?? '')),
				'customer_email' => sanitize_email((string) ($row['customer_email'] ?? '')),
				'amount'         => sanitize_text_field((string) ($row['amount'] ?? '0.00')),
				'created_at'     => !empty($row['created_at']) ? $row['created_at'] : current_time('mysql', 1),
			]);
		}
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	update_option('rtbtch_legacy_rows_migrated', '1');
}

/**
 * Normalise this plugin's own settings. BK-09: nothing is imported from the
 * older plugin options any more; a merchant who used that plugin
 * enters their RT keys here themselves.
 */
function rtbtch_migrate_old_settings()
{
	// BK-13: the gateway id changed in 1.0.2, so WooCommerce now reads the
	// settings from a new option. Carry the saved settings over once.
	$settings = get_option(RTBTCH_SETTINGS_OPTION, []);
	if (empty($settings)) {
		$legacy_settings = get_option(RTBTCH_LEGACY_SETTINGS_OPTION, []);
		if (is_string($legacy_settings)) {
			$legacy_settings = maybe_unserialize($legacy_settings);
		}
		if (is_array($legacy_settings) && !empty($legacy_settings)) {
			$settings = $legacy_settings;
		}
	}
	if (is_array($settings)) {
		// Keys live in the accounts option only.
		unset(
			$settings['public_key'],
			$settings['secret_key'],
			$settings['sandbox_public_key'],
			$settings['sandbox_secret_key'],
			$settings['accounts']
		);
		if (!empty($settings)) {
			update_option(RTBTCH_SETTINGS_OPTION, $settings);
		}
	}

	$accounts = get_option(RTBTCH_ACCOUNTS_OPTION);
	if ($accounts) {
		$accounts = maybe_unserialize($accounts);

		if (is_array($accounts) && !empty($accounts)) {
			$enhanced_accounts = array_map(function ($account) {
				if (!is_array($account)) {
					return $account;
				}
				$account['live_status'] = $account['live_status'] ?? 'active';
				$account['sandbox_status'] = $account['sandbox_status'] ?? 'active';
				$account['has_sandbox'] = (!empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) ? 'on' : 'off';
				$account['priority'] = $account['priority'] ?? 1;
				$account['title'] = $account['title'] ?? 'Default Account';
				if (empty($account['unique_id'])) {
					$account['unique_id'] = 'acc_' . wp_generate_uuid4();
				}
				return $account;
			}, $accounts);

			update_option(RTBTCH_ACCOUNTS_OPTION, $enhanced_accounts);
		}
	}

	rtbtch_trigger_sync();
}

/**
 * Schedule one background account check, only once keys exist. Nothing is
 * sent to RT here.
 */
function rtbtch_trigger_sync()
{
	if (!rtbtch_is_configured()) {
		return;
	}
	if (get_transient('rtbtch_sync_lock')) {
		return;
	}
	set_transient('rtbtch_sync_lock', true, 5 * MINUTE_IN_SECONDS);
	wp_schedule_single_event(time() + 30, RTBTCH_CRON_HOOK);
}

/**
 * Run every schema step the stored version has not seen yet. Nothing here
 * contacts RT or prints anything.
 */
function rtbtch_run_schema_steps()
{
	rtbtch_create_tables();
	rtbtch_migrate_pre_105_names();
	rtbtch_migrate_legacy_rows();
	rtbtch_migrate_old_settings();

	if (class_exists('RTBTCH_GATEWAY_Loader')) {
		RTBTCH_GATEWAY_Loader::get_instance()->rtbtch_activate_cron_job();
	}

	update_option('rtbtch_schema_version', RTBTCH_SCHEMA_VERSION);
	update_option('rtbtch_plugin_version', RTBTCH_PLUGIN_VERSION);
}

function rtbtch_maybe_upgrade()
{
	$current_schema = get_option('rtbtch_schema_version', '0');
	if (version_compare($current_schema, RTBTCH_SCHEMA_VERSION, '>=')) {
		return;
	}
	rtbtch_run_schema_steps();
}

/**
 * Activation only prepares local data. It never contacts RT.
 */
function rtbtch_on_plugin_activate() {
	rtbtch_run_schema_steps();
}

function rtbtch_on_plugin_deactivate() {
	if (class_exists('RTBTCH_GATEWAY_Loader')) {
		RTBTCH_GATEWAY_Loader::get_instance()->rtbtch_deactivate_cron_job();
		// Sent only when the merchant opted in to sharing plugin status and has keys.
		if (rtbtch_is_configured()) {
			RTBTCH_GATEWAY_Loader::get_instance()->rtbtch_send_plugin_status(0, 0);
		}
	}
}

register_activation_hook(RTBTCH_GATEWAY_FILE, 'rtbtch_on_plugin_activate');
register_deactivation_hook(RTBTCH_GATEWAY_FILE, 'rtbtch_on_plugin_deactivate');
