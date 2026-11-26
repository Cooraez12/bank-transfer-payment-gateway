<?php
/**
 * Late bank payment for an order the customer then paid another way.
 * The order is never changed; the shop owner gets one note; RT gets 200.
 *
 * Run: php -n tests/test_other_method_report.php
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
function esc_html__($s) { return $s; }
function home_url() { return 'https://shop.test/'; }
class WP_Error { private $c; public function __construct($c, $m = '') { $this->c = $c; } public function get_error_code() { return $this->c; } }
class WP_REST_Response { public $data; public $status; public function __construct($d, $s = 200) { $this->data = $d; $this->status = $s; } }
class WC_Order {
	public $meta = []; public $notes = []; public $status = 'pending'; public $paid = false; public $method = 'rt_card'; public $saves = 0;
	public function get_id() { return 42; }
	public function get_meta($k) { return $this->meta[$k] ?? ''; }
	public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
	public function add_order_note($n) { $this->notes[] = $n; }
	public function save() { $this->saves++; }
	public function get_status() { return $this->status; }
	public function get_payment_method() { return $this->method; }
	public function is_paid() { return $this->paid; }
}
class WP_REST_Request { public $h = []; public $b = ''; public function get_header($k) { return $this->h[$k] ?? ''; } public function get_body() { return $this->b; } }
class RTBTCH_Logger { public static function __callStatic($n, $a) {} }
$dir = dirname(__DIR__) . '/includes/';
require_once $dir . 'config.php';
require_once $dir . 'rt-bank-transfer-payment-gateway-utils.php';
// The real status words (the engine class without its require_once lines).
eval('?>' . preg_replace('/^require_once .*$/m', '', file_get_contents($dir . 'class-bank-transfer-payment-state-engine.php')));
eval('?>' . preg_replace('/^require_once .*$/m', '', file_get_contents($dir . 'class-bank-transfer-payment-gateway-rest-api.php')));

$results = [];
function check($name, $cond, $detail = '') { global $results; $results[] = $cond; echo ($cond ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " ($detail)" : '') . "\n"; }

$GLOBALS['options'][RTBTCH_ACCOUNTS_OPTION] = [['title' => 'Main', 'live_public_key' => 'pk_live_A', 'live_secret_key' => 'sk_live_A']];
$api = (new ReflectionClass('RTBTCH_GATEWAY_REST_API'))->newInstanceWithoutConstructor();
$report = new ReflectionMethod($api, 'rtbtch_other_method_report');
$signed = function ($secret, $body) { $r = new WP_REST_Request(); $ts = (string) time(); $r->b = $body; $r->h = ['x-timestamp' => $ts, 'x-signature' => hash_hmac('sha256', $ts . '.' . $body, $secret)]; return $r; };
$moved = function () { $o = new WC_Order(); $o->meta['_rtbtch_public_key'] = 'pk_live_A'; $o->meta['_rtbtch_pay_id'] = 'PAY-1'; return $o; };
$count = fn($o) => count(array_filter($o->notes, fn($n) => str_contains($n, 'also paid this order by bank transfer')));

$order = $moved();
$res = $report->invoke($api, $signed('sk_live_A', '{"order_id":42,"order_status":"success","pay_id":"PAY-1"}'), $order, 'success', 'PAY-1');
check('L1 signed late payment for a moved order: 200, one note, order unchanged', $res->status === 200 && ($res->data['engine_reason'] ?? '') === 'other_payment_method' && $count($order) === 1 && $order->status === 'pending' && !$order->paid && $order->method === 'rt_card', json_encode($res->data));
$res = $report->invoke($api, $signed('sk_live_A', '{"order_id":42,"order_status":"success","pay_id":"PAY-1","n":2}'), $order, 'success', 'PAY-1');
check('L1b a second delivery adds no second note', $res->status === 200 && $count($order) === 1);

$order = $moved();
$res = $report->invoke($api, $signed('sk_live_A', '{"order_id":42,"order_status":"processing","pay_id":"PAY-1"}'), $order, 'processing', 'PAY-1');
check('L1c a transfer on its way is noted too (the money is coming)', $res->status === 200 && $count($order) === 1);

$order = $moved();
$res = $report->invoke($api, $signed('sk_live_A', '{"order_id":42,"order_status":"expired","pay_id":"PAY-1"}'), $order, 'expired', 'PAY-1');
check('L1d the link closing (expired) is acknowledged without a note', $res->status === 200 && $count($order) === 0);

$order = $moved();
$res = $report->invoke($api, $signed('sk_wrong', '{"order_id":42,"order_status":"success","pay_id":"PAY-1"}'), $order, 'success', 'PAY-1');
check('L2 an unsigned (wrong secret) late report is refused, no note', $res->status === 401 && $count($order) === 0 && $order->saves === 0);

// One RT business, several shops: every bank link names its shop.
check('SH1 the shop tag is 8 hex of the home URL hash (trailing slash ignored)', rtbtch_store_ref() === substr(hash('sha256', 'https://shop.test'), 0, 8));
check('SH2 another shop gets another tag', substr(hash('sha256', 'https://second-shop.test'), 0, 8) !== rtbtch_store_ref());
check('SH3 request-payment sends meta_data.store_ref', str_contains(file_get_contents(dirname(__DIR__) . '/includes/class-bank-transfer-payment-gateway.php'), "'store_ref'      => rtbtch_store_ref(),"));

// Phones go to RT as E.164 from the billing country (UK 07700 900123 -> +447700900123).
foreach ([['07700 900123','+44','+447700900123'],['+44 7700 900123','+44','+447700900123'],['0044 7700 900123','+44','+447700900123'],['447700900123','44','+447700900123'],['(415) 555-0132','+1','+14155550132'],['1 415 555 0132','+1','+14155550132'],['','+1',''],['030 1234567','+49','+49301234567']] as [$in, $cc, $want]) { $got = rtbtch_e164_phone($in, $cc); check('PH ' . $in . ' [' . $cc . '] -> ' . $want, $got === $want, $got); }
check('PH payload sends the E.164 phone', str_contains(file_get_contents(dirname(__DIR__) . '/includes/class-bank-transfer-payment-gateway.php'), '$phone        = rtbtch_e164_phone($phone, $country_code);'));

$failed = count(array_filter($results, fn($x) => !$x));
echo "\n" . count($results) . " checks, $failed failed\n";
exit($failed ? 1 : 0);
