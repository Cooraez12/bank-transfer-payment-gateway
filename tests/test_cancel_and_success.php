<?php
/**
 * Order status sync with RT, with WordPress and WooCommerce stubbed out:
 *
 *  - an order cancelled in WooCommerce tells RT (cancel-order-link), and the
 *    answer in the BODY decides what the order records;
 *  - a settled payment moves the order to Processing/Completed -- a test
 *    (sandbox) payment included -- and never leaves it On hold;
 *  - the existing flows (in flight -> On hold, failed, cancelled by RT) hold.
 *
 * Run: php -n tests/test_cancel_and_success.php
 * Not shipped (see .distignore); refuses to run inside WordPress.
 */
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['options'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['orders'] = [];
$GLOBALS['http_log'] = [];
$GLOBALS['filters'] = [];
$GLOBALS['env_type'] = 'production';
$GLOBALS['http'] = function ($url, $args) { return ['response' => ['code' => 500], 'body' => '']; };

function plugin_dir_path($f) { return rtrim(dirname($f), '/\\') . '/'; }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function maybe_unserialize($v) { return $v; }
function apply_filters($tag, $v, ...$rest) { return isset($GLOBALS['filters'][$tag]) ? ($GLOBALS['filters'][$tag])($v, ...$rest) : $v; }
function add_action() {}
function add_filter() {}
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function wp_get_environment_type() { return $GLOBALS['env_type']; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function __($s, $d = '') { return $s; }
function esc_html__($s, $d = '') { return $s; }
function esc_html($s) { return (string) $s; }
function esc_sql($s) { return $s; }
function esc_url_raw($s) { return $s; }
function sanitize_text_field($s) { return is_scalar($s) ? trim((string) $s) : ''; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_cache_delete($k, $g = '') { return true; }
function current_time($t) { return $t === 'mysql' ? gmdate('Y-m-d H:i:s') : date($t); }
function get_bloginfo($k) { return 'Shop'; }
function wp_specialchars_decode($s, $q = 0) { return $s; }
function is_email($e) { return false; }
function wc_get_order($id) { return $GLOBALS['orders'][(int) $id] ?? false; }
function wp_remote_post($url, $args = []) { $GLOBALS['http_log'][] = [$url, $args]; return ($GLOBALS['http'])($url, $args); }
function wp_remote_retrieve_response_code($r) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }

class WP_Error { private $c; public function __construct($c, $m = '') { $this->c = $c; } public function get_error_code() { return $this->c; } }
class RTBTCH_Logger { public static function __callStatic($n, $a) {} }
class WC_Order {
	public $meta = []; public $notes = []; public $status = 'pending'; public $paid = false; public $method = 'rt_bank_transfer'; private $id;
	public function __construct($id = 42) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_status() { return $this->status; }
	public function has_status($s) { return in_array($this->status, (array) $s, true); }
	public function update_status($s, $note = '') { $this->status = $s; if ($note !== '') { $this->notes[] = $note; } return true; }
	public function get_meta($k, $single = true) { return $this->meta[$k] ?? ''; }
	public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
	public function delete_meta_data($k) { unset($this->meta[$k]); }
	public function save() {}
	public function add_order_note($n) { $this->notes[] = $n; }
	public function get_total() { return '25.00'; }
	public function get_payment_method() { return $this->method; }
	// The order being saved with a new payment method (WC_Data before-save view).
	public $changes = [];
	public function get_changes() { return $this->changes; }
	public function get_data() { return ['payment_method' => $this->method]; }
	public function is_paid() { return $this->paid; }
	public $saves = 0;
	public function get_order_number() { return (string) $this->id; }
	public function get_edit_order_url() { return ''; }
	// WooCommerce: a paid order goes to processing from on-hold/pending/failed/cancelled.
	public function payment_complete($txn = '') { if (!in_array($this->status, ['on-hold', 'pending', 'failed', 'cancelled'], true)) { return false; } $this->paid = true; $this->status = 'processing'; return true; }
}
class WPDB_Stub {
	public $prefix = 'wp_'; public $row = null;
	public function prepare($q, ...$a) { return $q; }
	public function get_var($q) { return stripos($q, 'GET_LOCK') !== false ? '1' : null; }
	public function get_row($q, $o = null) { return $this->row; }
	public function query($q) { return true; }
}
$GLOBALS['wpdb'] = new WPDB_Stub();

$root = dirname(__DIR__) . '/';
require_once $root . 'includes/config.php';
require_once $root . 'includes/rt-bank-transfer-payment-gateway-utils.php';
// The engine without its require_once lines (the files are loaded above).
eval('?>' . preg_replace('/^require_once .*$/m', '', file_get_contents($root . 'includes/class-bank-transfer-payment-state-engine.php')));
// The cancel action lives in the plugin's main file, which boots the whole
// plugin; only the function itself is loaded here.
preg_match('/^function rtbtch_cancel_unpaid_order_action\(.*?^}/ms', file_get_contents($root . 'bank-transfer-payment-gateway.php'), $cancel_src);
eval($cancel_src[0]);
preg_match('/^function rtbtch_release_on_switch\(.*?^}/ms', file_get_contents($root . 'bank-transfer-payment-gateway.php'), $release_src);
eval($release_src[0]);

const PUB = 'pk_test_A';
const SEC = 'sk_test_A_SECRET';
const PAY = 'MGI5ZjNjNTEtMmYyZS00YTQzLTlkMzgtNWI3YzFhMWYwZTEx';

$results = [];
function check($name, $cond, $detail = '') { global $results; $results[] = (bool) $cond; echo ($cond ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " ($detail)" : '') . "\n"; }
function new_order($test = false) {
	$GLOBALS['options'] = [
		RTBTCH_SETTINGS_OPTION => ['enabled' => 'yes', 'sandbox' => $test ? 'yes' : 'no', 'order_status' => 'processing'],
		RTBTCH_ACCOUNTS_OPTION => [['title' => 'A', 'live_public_key' => 'pk_live_A', 'live_secret_key' => 'sk_live_A_SECRET', 'sandbox_public_key' => PUB, 'sandbox_secret_key' => SEC, 'has_sandbox' => 'on']],
	];
	$GLOBALS['transients'] = []; $GLOBALS['http_log'] = []; $GLOBALS['filters'] = []; $GLOBALS['env_type'] = 'production';
	$order = new WC_Order(42);
	$order->meta['_rtbtch_public_key'] = PUB;
	$order->meta['_rtbtch_pay_id'] = PAY;
	$order->meta['_rtbtch_active_pay_id'] = PAY;
	if ($test) { $order->meta['_rtbtch_is_test_order'] = true; }
	$GLOBALS['orders'] = [42 => $order];
	return $order;
}
function event($status, $type = 'webhook_update') { return RTBTCH_State_Engine::handle_event(42, $type, ['status' => $status, 'payment_token' => PAY]); }
function has_note($order, $needle) { return count(array_filter($order->notes, fn($n) => str_contains($n, $needle))); }

echo "== Successful payment -> Processing / Completed, never On hold\n";
$order = new_order();
event('processing');
check('S1 debit in flight -> On hold (money not here yet)', $order->get_status() === 'on-hold' && !$order->paid);
event('success');
check('S2 debit settled -> Processing + paid', $order->get_status() === 'processing' && $order->paid, $order->get_status());

$order = new_order(true);
event('processing');
event('success');
check('S3 TEST payment settled on a "production" site -> Processing, not On hold', $order->get_status() === 'processing' && $order->paid, $order->get_status());
check('S3b the test order says no real money was received', has_note($order, 'TEST payment') === 1 && $order->get_meta('_rtbtch_sandbox_success_held') === '');

$order = new_order(true);
event('success', 'api_update');
check('S4 TEST payment confirmed by a status lookup from Pending -> Processing', $order->get_status() === 'processing' && $order->paid);

$order = new_order(true);
$GLOBALS['options'][RTBTCH_SETTINGS_OPTION]['order_status'] = 'completed';
event('success');
check('S5 "Completed" as the configured paid status is honoured', $order->get_status() === 'completed' && $order->paid, $order->get_status());

$order = new_order(true);
$GLOBALS['filters']['rt_bank_transfer_hold_test_payments'] = fn($hold, $o) => true;
event('success');
check('S6 a shop that opts in keeps test payments On hold', $order->get_status() === 'on-hold' && !$order->paid && $order->get_meta('_rtbtch_sandbox_success_held') === 'yes');

echo "== Failed / cancelled / expired reported by RT (unchanged)\n";
$order = new_order(); event('failed');
check('F1 failed -> Failed', $order->get_status() === 'failed');
$order = new_order(); event('cancelled');
check('F2 cancelled at RT -> Cancelled', $order->get_status() === 'cancelled');
$order = new_order(); event('expired');
check('F3 expired -> Failed', $order->get_status() === 'failed');
$order = new_order(); event('success'); event('failed', 'api_update');
check('F4 a paid order is not failed by a status lookup', $order->get_status() === 'processing' && $order->paid);

echo "== Order cancelled in WooCommerce -> RT is told\n";
$answer = function (array $body, $code = 200) { return function ($url, $args) use ($body, $code) { return ['response' => ['code' => $code], 'body' => json_encode($body)]; }; };

$order = new_order(true); $order->status = 'cancelled';
$GLOBALS['http'] = $answer(['status' => true, 'updated_status' => 'canceled', 'message' => 'Order payment link has been cancelled successfully']);
rtbtch_cancel_unpaid_order_action(42);
[$url, $req] = $GLOBALS['http_log'][0] ?? ['', ['body' => '{}', 'headers' => []]];
$sent = json_decode($req['body'], true);
check('C1 RT is called at cancel-order-link with the order, its payment id and the mode', count($GLOBALS['http_log']) === 1 && str_ends_with($url, '/cancel-order-link') && $sent === ['order_id' => 42, 'order_uuid' => PAY, 'status' => 'canceled', 'is_sandbox' => 1], $req['body']);
check('C1b the call is signed and carries no secret', !str_contains($req['body'], SEC) && hash_hmac('sha256', $req['headers']['X-Timestamp'] . '.' . $req['body'], SEC) === $req['headers']['X-Signature'] && $req['headers']['Authorization'] === 'Bearer ' . PUB);
check('C1c the order notes that RT cancelled the link', has_note($order, 'cancelled in your RT account') === 1);

$order = new_order(true); $order->status = 'cancelled';
$GLOBALS['http'] = $answer(['status' => false, 'updated_status' => 'reconciliation_required', 'message' => 'Payment has started.']);
rtbtch_cancel_unpaid_order_action(42);
check('C2 RT refuses (transfer on its way): the order says so, and is not noted as cancelled at RT', has_note($order, 'could not be stopped') === 1 && has_note($order, 'cancelled in your RT account') === 0);

$order = new_order(true); $order->status = 'cancelled';
$GLOBALS['http'] = $answer(['status' => false, 'message' => 'Payment link not found']);
rtbtch_cancel_unpaid_order_action(42);
check('C3 an HTTP 200 whose body says "not cancelled" is not taken for success', has_note($order, 'cancelled in your RT account') === 0);

$order = new_order(true); $order->status = 'cancelled'; $order->method = 'cod';
rtbtch_cancel_unpaid_order_action(42);
check('C4 another gateway\'s order is left alone', count($GLOBALS['http_log']) === 0);

$order = new_order(true); $order->status = 'cancelled'; $order->meta['_rtbtch_pay_id'] = '';
rtbtch_cancel_unpaid_order_action(42);
check('C5 no payment link was ever raised: RT is not called', count($GLOBALS['http_log']) === 0);

echo "== Customer chose another payment method -> the bank link is cancelled at RT\n";
$ok = ['status' => true, 'updated_status' => 'canceled', 'message' => 'Order payment link has been cancelled successfully'];
$order = new_order(true); $order->changes = ['payment_method' => 'rt_card'];
$GLOBALS['http'] = $answer($ok);
rtbtch_release_on_switch($order);
[$url, $req] = $GLOBALS['http_log'][0] ?? ['', ['body' => '{}', 'headers' => []]];
check('R1 switch away: cancel-order-link is called, signed, for this payment', count($GLOBALS['http_log']) === 1 && str_ends_with($url, '/cancel-order-link') && json_decode($req['body'], true) === ['order_id' => 42, 'order_uuid' => PAY, 'status' => 'canceled', 'is_sandbox' => 1] && hash_hmac('sha256', $req['headers']['X-Timestamp'] . '.' . $req['body'], SEC) === $req['headers']['X-Signature'], $req['body']);
check('R1b one note, link remembered, order not changed', has_note($order, 'unused bank payment link was cancelled') === 1 && $order->meta['_rtbtch_released_pay_id'] === PAY && $order->get_status() === 'pending' && !$order->paid);
rtbtch_release_on_switch($order);
check('R2 a second save does not call RT again', count($GLOBALS['http_log']) === 1);

$order = new_order(true); $order->changes = ['payment_method' => 'rt_card'];
$GLOBALS['http'] = $answer(['status' => false, 'updated_status' => 'reconciliation_required', 'message' => 'Payment has started.']);
rtbtch_release_on_switch($order);
check('R3 RT refuses (transfer on its way): the merchant is told, nothing remembered', has_note($order, 'could not cancel their bank payment link') === 1 && ($order->meta['_rtbtch_released_pay_id'] ?? '') === '');

$order = new_order(true); $order->changes = ['payment_method' => RTBTCH_LEGACY_GATEWAY_ID];
rtbtch_release_on_switch($order);
$order2 = new_order(true); $order2->changes = ['customer_note' => 'x'];
rtbtch_release_on_switch($order2);
$order3 = new_order(true); $order3->method = 'rt_card'; $order3->changes = ['payment_method' => 'rt_usdc'];
rtbtch_release_on_switch($order3);
check('R4 no call: switch to its own legacy id, no payment-method change, or another plugin\'s order', count($GLOBALS['http_log']) === 0);

$order = new_order(true); $order->paid = true; $order->changes = ['payment_method' => 'rt_card'];
rtbtch_release_on_switch($order);
check('R5 a paid order is never released', count($GLOBALS['http_log']) === 0);

$failed = count(array_filter($results, fn($x) => !$x));
echo "\n" . count($results) . " checks, $failed failed\n";
exit($failed ? 1 : 0);
