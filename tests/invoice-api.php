<?php
// Standalone API contract checks; no shop, credentials or network required.
require dirname(__DIR__) . '/classes/PayopInvoiceHistory.php';
class Configuration
{
    public static $values = ['PAYOP_PUBLIC_KEY' => 'test-public', 'PAYOP_SECRET_KEY' => 'test-secret', 'PAYOP_API_TOKEN' => ''];
    public static function get($key) { return isset(self::$values[$key]) ? self::$values[$key] : false; }
}
class PrestaShopLogger
{
    public static $messages = [];
    public static function addLog($message) { self::$messages[] = $message; }
}
class Order { public $id = 778; public $total_paid = '10.00'; public $id_currency = 1; }
class Currency { public $iso_code = 'EUR'; public function __construct($id) {} }
class InvoiceApiProbe
{
    use PayopInvoiceHistory;
    public $requests = [];
    public $response;
    protected function executeInvoiceDetailsRequest($url, array $options)
    {
        $this->requests[] = [$url, $options];
        return $this->response;
    }
}
function check($condition, $message)
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
$probe = new InvoiceApiProbe();
$data = ['identifier' => 'invoice/: ?', 'orderIdentifier' => '778', 'amount' => '10.00',
    'currency' => 'eur', 'status' => 1, 'transactionIdentifier' => 'tx-test', 'isOverdue' => true];
$probe->response = ['body' => json_encode(['data' => $data]), 'status' => 200, 'errno' => 0];
$invoice = $probe->fetchInvoice($data['identifier']);
check($invoice['ok'], 'valid invoice response accepted without JWT');
list($url, $options) = $probe->requests[0];
check($url === 'https://api.payop.com/v1/invoices/invoice%2F%3A%20%3F', 'invoice ID URL encoded');
check($options[CURLOPT_HTTPHEADER] === ['Accept: application/json', 'publicKey: test-public',
    'signature: ' . hash('sha256', 'invoice/: ?:test-secret')], 'exact publicKey and SHA-256 hex signature headers without JWT');
check($options[CURLOPT_SSL_VERIFYHOST] === 2 && $options[CURLOPT_SSL_VERIFYPEER] === true
    && $options[CURLOPT_TIMEOUT] > 0 && $options[CURLOPT_CONNECTTIMEOUT] > 0, 'TLS verification and finite timeouts retained');
$order = new Order();
foreach ([true, false] as $overdue) {
    $invoice['data']['isOverdue'] = $overdue;
    check($probe->verifyInvoiceForOrder($invoice, $order, $data['identifier'], 'tx-test', 2)['ok'],
        'paid invoice verified with isOverdue=' . ($overdue ? 'true' : 'false'));
}
foreach (['identifier' => 'other', 'orderIdentifier' => '779', 'amount' => '10.01', 'currency' => 'USD',
    'transactionIdentifier' => 'other', 'status' => 0] as $field => $value) {
    $bad = ['ok' => true, 'data' => array_merge($data, [$field => $value])];
    check(!$probe->verifyInvoiceForOrder($bad, $order, $data['identifier'], 'tx-test', 2)['ok'], $field . ' mismatch rejects payment');
    unset($bad['data'][$field]);
    check(!$probe->verifyInvoiceForOrder($bad, $order, $data['identifier'], 'tx-test', 2)['ok'], 'missing ' . $field . ' rejects payment');
    $bad['data'][$field] = [];
    check(!$probe->verifyInvoiceForOrder($bad, $order, $data['identifier'], 'tx-test', 2)['ok'], 'array ' . $field . ' rejects payment');
}
foreach ([
    [422, '{"message":"Wrong signature"}', 0],
    [422, '{"message":"Invoice not found"}', 0],
    [422, '{"message":"Invoice is overdue"}', 0],
    [500, '{}', 0], [0, false, 28], [0, false, 6],
    [200, 'invalid-json', 0], [200, '{"data":[]}', 0], [200, '{}', 0], [200, 'null', 0],
] as $case) {
    $probe->response = ['status' => $case[0], 'body' => $case[1], 'errno' => $case[2]];
    $failed = $probe->fetchInvoice($data['identifier']);
    check(!$failed['ok'] && !$probe->verifyInvoiceForOrder($failed, $order, $data['identifier'], 'tx-test', 2)['ok'],
        'API failure cannot confirm payment: ' . json_encode($case));
}
foreach (['PAYOP_PUBLIC_KEY', 'PAYOP_SECRET_KEY'] as $key) {
    $saved = Configuration::$values[$key];
    foreach (['', false, []] as $missing) {
        Configuration::$values[$key] = $missing;
        $count = count($probe->requests);
        check(!$probe->fetchInvoice('invoice')['ok'] && count($probe->requests) === $count, 'invalid ' . $key . ' fails before transport');
    }
    Configuration::$values[$key] = $saved;
}
foreach (['', ' ', null, []] as $id) {
    $count = count($probe->requests);
    check(!$probe->fetchInvoice($id)['ok'] && count($probe->requests) === $count, 'invalid invoice ID fails before transport');
}
Configuration::$values['PAYOP_PUBLIC_KEY'] = "test\r\nInjected: header";
$count = count($probe->requests);
check(!$probe->fetchInvoice('invoice')['ok'] && count($probe->requests) === $count, 'header injection rejected');
$logs = implode("\n", PrestaShopLogger::$messages);
check(strpos($logs, 'test-secret') === false && strpos($logs, hash('sha256', 'invoice/: ?:test-secret')) === false,
    'diagnostic logs contain no secret or signature');
echo "All invoice API checks passed.\n";
