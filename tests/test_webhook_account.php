<?php
/**
 * BANK-05: a shop with several RT accounts. The webhook for a link raised
 * under an older account must be verified with THAT account's secret.
 *
 * Run: php -n tests/test_webhook_account.php
 * Not shipped (see .distignore); refuses to run inside WordPress.
 */
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
$GLOBALS['options'] = [];
$GLOBALS['transients'] = [];
function plugin_dir_path($f) { return rtrim(dirname($f), '/') . '/'; }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function maybe_unserialize($v) { return $v; }
function apply_filters($tag, $v) { return $v; }
function add_action() {}
function add_filter() {}
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function __($s) { return $s; }
class WP_Error { private $c; public function __construct($c, $m = '') { $this->c = $c; } public function get_error_code() { return $this->c; } }
class WC_Order { public $meta = []; public function get_id() { return 42; } public function get_meta($k) { return $this->meta[$k] ?? ''; } }
class WP_REST_Request { public $h = []; public $b = ''; public function get_header($k) { return $this->h[$k] ?? ''; } public function get_body() { return $this->b; } }
class Bank_Transfer_Payment_Gateway_Logger { public static function __callStatic($n, $a) {} }
class BANK_TRANSFER_PAYMENT_ENGINE { public static function __callStatic($n, $a) {} }

$dir = dirname(__DIR__) . '/includes/';
require_once $dir . 'config.php';
require_once $dir . 'bank-transfer-payment-gateway-utils.php';
$src = file_get_contents($dir . 'class-bank-transfer-payment-gateway-rest-api.php');
// Load the class without its require_once lines (stubs above stand in).
$src = preg_replace('/^require_once .*$/m', '', $src);
eval('?>' . $src);

$results = [];
function check($name, $cond) { global $results; $results[] = $cond; echo ($cond ? 'PASS ' : 'FAIL ') . $name . "\n"; }

$GLOBALS['options'][BANK_TRANSFER_ACCOUNTS_OPTION] = [
	['title' => 'Main',   'live_public_key' => 'pk_live_A', 'live_secret_key' => 'sk_live_A'],
	['title' => 'Backup', 'live_public_key' => 'pk_live_B', 'live_secret_key' => 'sk_live_B'],
];
$order = new WC_Order();
// Link 1 under account A, then failover: link 2 under account B (latest).
$order->meta['_bank_transfer_pay_id_history'] = [
	['pay_id' => 'PAY-1', 'public_key' => 'pk_live_A'],
	['pay_id' => 'PAY-2', 'public_key' => 'pk_live_B'],
];
$order->meta['_bank_transfer_public_key'] = 'pk_live_B';
$order->meta['_bank_transfer_pay_id'] = 'PAY-2';

$api = (new ReflectionClass('BANK_TRANSFER_PAYMENT_GATEWAY_REST_API'))->newInstanceWithoutConstructor();
$verify = new ReflectionMethod($api, 'bank_transfer_verify_webhook');
$verify->setAccessible(true);
$signed = function ($secret, $body) { $r = new WP_REST_Request(); $ts = (string) time(); $r->b = $body; $r->h = ['x-timestamp' => $ts, 'x-signature' => hash_hmac('sha256', $ts . '.' . $body, $secret)]; return $r; };

check('B1 link-history lookup names account A for PAY-1', bank_transfer_public_key_for_pay_id($order, 'PAY-1') === 'pk_live_A');
check('B2 webhook account for PAY-1 is A, for PAY-2 is B', bank_transfer_get_webhook_account($order, 'PAY-1')['secret_key'] === 'sk_live_A' && bank_transfer_get_webhook_account($order, 'PAY-2')['secret_key'] === 'sk_live_B');
check('B3 webhook for the OLDER link signed by A is accepted', $verify->invoke($api, $signed('sk_live_A', '{"pay_id":"PAY-1","n":1}'), $order, 'PAY-1') === true);
check('B4 webhook for the newer link signed by B is accepted', $verify->invoke($api, $signed('sk_live_B', '{"pay_id":"PAY-2","n":2}'), $order, 'PAY-2') === true);
$r = $verify->invoke($api, $signed('sk_live_B', '{"pay_id":"PAY-1","n":3}'), $order, 'PAY-1');
check('B5 webhook for PAY-1 signed by the wrong account (B) is refused', is_wp_error($r) && $r->get_error_code() === 'signature_mismatch');
$r = $verify->invoke($api, $signed('sk_live_A', '{"pay_id":"PAY-2","n":4}'), $order, 'PAY-2');
check('B6 webhook for PAY-2 signed by A is refused', is_wp_error($r) && $r->get_error_code() === 'signature_mismatch');
check('B7 unknown pay id falls back to the latest account (B), as before', bank_transfer_get_webhook_account($order, 'PAY-X')['secret_key'] === 'sk_live_B');
$legacy = new WC_Order(); $legacy->meta['_bank_transfer_public_key'] = 'pk_live_A';
check('B8 order without history uses its own account', bank_transfer_get_webhook_account($legacy, 'PAY-1')['secret_key'] === 'sk_live_A');
$gone = new WC_Order(); $gone->meta['_bank_transfer_pay_id_history'] = [['pay_id' => 'PAY-9', 'public_key' => 'pk_removed']];
check('B9 link under a removed account has no account (refused, not guessed)', bank_transfer_get_webhook_account($gone, 'PAY-9') === null);
$r = $verify->invoke($api, $signed('sk_live_A', '{"pay_id":"PAY-1","n":1}'), $order, 'PAY-1');
check('B10 replay of the same signed delivery is still refused', is_wp_error($r));

$failed = count(array_filter($results, fn($x) => !$x));
echo "\n" . count($results) . " checks, $failed failed\n";
exit($failed ? 1 : 0);
