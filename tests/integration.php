<?php
// Run only against a disposable PrestaShop installation.
if (PHP_SAPI !== 'cli' || getenv('PAYOP_TEST_SHOP') !== '1') {
    exit('Run from CLI with PAYOP_TEST_SHOP=1 against a disposable test shop.');
}
require dirname(__DIR__, 3) . '/config/config.inc.php';
require_once _PS_ROOT_DIR_ . '/app/AppKernel.php';
if (is_file(_PS_ROOT_DIR_ . '/app/AdminKernel.php')) {
    require_once _PS_ROOT_DIR_ . '/app/AdminKernel.php';
    $kernel = new AdminKernel('dev', true);
} else {
    $kernel = new AppKernel('dev', true);
}
$kernel->boot();
Context::getContext()->container = $kernel->getContainer();
require dirname(__DIR__) . '/payop.php';
require dirname(__DIR__) . '/controllers/front/callback.php';
require dirname(__DIR__) . '/controllers/front/validation.php';

class PaymentTestModule extends Payop
{
    public $lastInvoiceRequest = [];
    public $nextInvoiceId = '';
    public function createInvoice(array $request) { $this->lastInvoiceRequest = $request; return ['ok' => true, 'invoice_id' => $this->nextInvoiceId]; }
    public $transaction = [];
    public $invoiceMatches = true;
    public $methodsResponse = ['ok' => true, 'data' => []];
    public function fetchTransaction($id) { return $this->transaction; }
    public function verifyInvoiceTransaction(Order $order, $invoice, $transaction) { return $this->invoiceMatches; }
    protected function requestPaymentMethods($application, $token) { return $this->methodsResponse; }
}
class PaymentTestResponse extends RuntimeException
{
    public $status;
    public function __construct($status, $body) { $this->status = $status; parent::__construct($body); }
}
class PaymentTestCallback extends PayopCallbackModuleFrontController
{
    private $payload;
    public function __construct($module) { $this->module = $module; $this->context = Context::getContext(); }
    protected function readCallbackPayload() { return $this->payload; }
    protected function respond($status, $body = '') { $this->releaseCallbackLock(); throw new PaymentTestResponse($status, $body); }
    public function dispatchTest($payload) {
        $this->payload = json_decode(json_encode($payload));
        try { $this->callbackRequest(); } catch (PaymentTestResponse $r) { return [$r->status, $r->getMessage()]; }
    }
}
class PaymentTestValidation extends PayopValidationModuleFrontController
{
    public $redirectUrl;
    public function __construct($module) { $this->module = $module; $this->context = Context::getContext(); }
    protected function redirectPayment($url) { $this->redirectUrl = $url; }
}
function check($condition, $message) {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
function rejects($callback, $message) {
    try { $callback(); } catch (InvalidArgumentException $e) { check(true, $message); return; }
    check(false, $message);
}
function config($key, $value) { Configuration::updateValue($key, $value); }

$module = new PaymentTestModule();
check($module->ensureInvoiceHistory(), 'history schema available');
$db = Db::getInstance();
$db->execute('START TRANSACTION');
try {
    $lang = (int) Configuration::get('PS_LANG_DEFAULT');
    config('PAYOP_ENABLE', 1);
    config('PAYOP_NAME', 'Payop');
    config('PAYOP_PUBLIC_KEY', 'application-test');
    config('PAYOP_API_TOKEN', 'test-verification-token');
    config('PAYOP_METHODS_TOKEN', '');
    config('PAYOP_BUTTONS', '[]');
    config('PAYOP_METHODS_CACHE', '');
    $module->active = true;
    Context::getContext()->language = new Language($lang);
    Context::getContext()->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
    check(count($module->hookPaymentOptions()) === 1, 'legacy Hosted Page works without methods JWT');
    check(!isset($module->applyInvoicePaymentMethod(['paymentMethod' => '999'], $module->resolvePaymentButton('default'))['paymentMethod']), 'Hosted Page omits paymentMethod');
    check(empty($module->loadPaymentMethods()['ok']), 'missing methods JWT produces a clear warning');
    config('PAYOP_METHODS_TOKEN', 'test-methods-token');
    $module->methodsResponse = ['ok' => true, 'data' => [['identifier' => 100, 'title' => 'Crypto'], ['identifier' => 200, 'title' => 'Revolut']]];
    check($module->loadPaymentMethods(true)['ok'], 'project payment method catalogue loads');
    $buttons = [];
    foreach (['Crypto' => '100', 'Revolut' => '200', 'Other Hosted Page' => ''] as $name => $id) {
        $buttons[] = ['id' => 'button_' . bin2hex(random_bytes(8)), 'enabled' => true, 'type' => $id ? 'payment_method' : 'hosted', 'payment_method' => $id, 'names' => [$lang => $name], 'descriptions' => [$lang => 'Pay using ' . $name]];
    }
    $buttons = $module->validateButtons(json_encode($buttons));
    config('PAYOP_BUTTONS', json_encode($buttons));
    check(count($module->hookPaymentOptions()) === 4, 'general Hosted Page and three additional options coexist');
    $alternateLanguage = clone Context::getContext()->language;
    $alternateLanguage->id = $lang + 1000;
    $translated = $buttons;
    $translated[0]['names'][$alternateLanguage->id] = 'Translated crypto';
    config('PAYOP_BUTTONS', json_encode($translated));
    Context::getContext()->language = $alternateLanguage;
    check($module->resolvePaymentButton($buttons[0]['id'])['name'] === 'Translated crypto', 'current language customer title selected');
    check($module->resolvePaymentButton($buttons[1]['id'])['name'] === 'Revolut', 'missing customer translation falls back to shop default');
    Context::getContext()->language = new Language($lang);
    config('PAYOP_BUTTONS', json_encode($buttons));
    $button = $module->resolvePaymentButton($buttons[0]['id']);
    $_POST['paymentMethod'] = '999';
    check($module->applyInvoicePaymentMethod([], $button)['paymentMethod'] === '100', 'buyer-supplied paymentMethod cannot override stored method');
    check(!$module->resolvePaymentButton('unknown') && !$module->resolvePaymentButton(['default']), 'unknown or malformed option rejected');
    $buttons[1]['enabled'] = false;
    config('PAYOP_BUTTONS', json_encode($buttons));
    check(count($module->hookPaymentOptions()) === 3 && !$module->resolvePaymentButton($buttons[1]['id']), 'disabled option omitted and cannot be submitted');
    $buttons[0]['names'][$lang] = 'Digital currencies';
    $buttons = $module->validateButtons(json_encode($buttons));
    config('PAYOP_BUTTONS', json_encode($buttons));
    check($module->resolvePaymentButton($buttons[0]['id'])['name'] === 'Digital currencies', 'button title can be edited');
    $missing = $buttons; $missing[0]['payment_method'] = '';
    rejects(function () use ($module, $missing) { $module->validateButtons(json_encode($missing)); }, 'method ID required only for method-specific type');
    $unknown = $buttons; $unknown[0]['payment_method'] = '999';
    rejects(function () use ($module, $unknown) { $module->validateButtons(json_encode($unknown)); }, 'new arbitrary method ID rejected');
    $module->methodsResponse = ['ok' => false, 'error' => 'Invalid JWT; saved buttons retained.'];
    $before = Configuration::get('PAYOP_BUTTONS');
    check(!$module->loadPaymentMethods(true)['ok'] && Configuration::get('PAYOP_BUTTONS') === $before, 'invalid JWT preserves saved buttons');
    config('PAYOP_METHODS_CACHE', '');
    check(count($module->validateButtons(json_encode($buttons))) === 3, 'saved method IDs remain editable when catalogue is unavailable');
    $many = [];
    for ($i = 0; $i < 120; $i++) $many[] = ['id' => 'button_' . bin2hex(random_bytes(8)), 'enabled' => true, 'type' => 'hosted', 'names' => [$lang => 'Button ' . $i]];
    check(count($module->validateButtons(json_encode($many))) === 120, 'no artificial button count limit');
    config('PAYOP_BUTTONS', json_encode(array_slice($buttons, 0, 1)));
    check(count($module->hookPaymentOptions()) === 2, 'removed buttons no longer appear');

    // Use a synthetic order with no products: no stock movements or real payment API calls.
    $customerId = (int) $db->getValue('SELECT id_customer FROM ' . _DB_PREFIX_ . 'customer ORDER BY id_customer');
    $addressId = (int) $db->getValue('SELECT id_address FROM ' . _DB_PREFIX_ . 'address WHERE id_customer=' . $customerId);
    $cart = new Cart();
    $cart->id_customer = $customerId;
    $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
    $cart->id_lang = $lang;
    $cart->id_shop = Context::getContext()->shop->id;
    $cart->id_address_delivery = $addressId;
    $cart->id_address_invoice = $addressId;
    check($cart->add(), 'isolated cart fixture created');
    $order = new Order();
    $order->id_cart = $cart->id;
    $order->id_customer = $customerId;
    $order->id_address_delivery = $addressId;
    $order->id_address_invoice = $addressId;
    $order->id_currency = $cart->id_currency;
    $order->id_lang = $lang;
    $order->id_shop = $cart->id_shop;
    $order->id_shop_group = Context::getContext()->shop->id_shop_group;
    $order->id_carrier = 0;
    $order->module = 'payop';
    $order->payment = 'Payop';
    $order->secure_key = (new Customer($customerId))->secure_key;
    $order->reference = strtoupper(substr(bin2hex(random_bytes(5)), 0, 9));
    $order->total_paid = $order->total_paid_tax_incl = $order->total_paid_tax_excl = 10;
    $order->total_paid_real = 0;
    $order->total_products = $order->total_products_wt = 10;
    $order->conversion_rate = 1;
    $order->current_state = (int) Configuration::get('PS_OS_PAYOP_PENDING_STATE');
    check($order->add(), 'isolated order fixture created');
    $oldInvoice = 'test-old-' . bin2hex(random_bytes(8));
    $newInvoice = 'test-new-' . bin2hex(random_bytes(8));
    $primary = $module->resolvePaymentButton('default');
    Context::getContext()->cart = $cart;
    Context::getContext()->customer = new Customer($customerId);
    $validation = new PaymentTestValidation($module);
    $module->nextInvoiceId = $oldInvoice;
    $_POST['payop_option'] = 'default';
    $validation->postProcess();
    check(strpos($validation->redirectUrl, $oldInvoice) !== false && !isset($module->lastInvoiceRequest['paymentMethod']), 'validation creates a Hosted Page invoice without paymentMethod');
    $module->nextInvoiceId = $newInvoice;
    $_POST['payop_option'] = $button['id'];
    $_POST['id_order'] = $order->id;
    $_POST['key'] = $order->secure_key;
    $_POST['signature'] = $module->generateFailSignature($order->id, $cart->id, $order->secure_key);
    $validation->postProcess();
    check(strpos($validation->redirectUrl, $newInvoice) !== false && $module->lastInvoiceRequest['paymentMethod'] === '100', 'return and switch creates a fresh method-specific invoice for the same order');
    unset($_POST['id_order'], $_POST['key'], $_POST['signature']);
    Context::getContext()->cart = new Cart();
    $backInvoice = 'test-back-' . bin2hex(random_bytes(8));
    $module->nextInvoiceId = $backInvoice;
    $_POST['payop_option'] = 'default';
    $validation->postProcess();
    check(strpos($validation->redirectUrl, $backInvoice) !== false && !isset($module->lastInvoiceRequest['paymentMethod']), 'stale checkout submission restores own order from protected retry cookie');
    $methodInvoice = $newInvoice;
    $newInvoice = $backInvoice;
    unset($_POST['payop_option']);
    check($module->getInvoiceContext($order->id, $oldInvoice)['payment_method'] === '' && $module->getInvoiceContext($order->id, $methodInvoice)['payment_method'] === '100' && $module->getInvoiceContext($order->id, $backInvoice)['payment_method'] === '', 'immutable history retains both method contexts');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['signature'] = $module->getCallbackSignature();
    config('PS_MAIL_METHOD', 3);
    $callback = new PaymentTestCallback($module);
    $send = function ($invoice, $state, $tx, $overrides = []) use ($module, $order, $callback) {
        $module->transaction = ['ok' => true, 'orderId' => (string) $order->id, 'state' => $state, 'txid' => $tx, 'amount' => '10', 'currency' => (new Currency($order->id_currency))->iso_code];
        $module->transaction = array_merge($module->transaction, $overrides);
        return $callback->dispatchTest(['transaction' => ['id' => $tx, 'state' => $state, 'order' => ['id' => $order->id]], 'invoice' => ['id' => $invoice, 'txid' => $tx]]);
    };
    check($send('unknown', 2, 'tx-unknown')[0] === 409, 'unknown invoice rejected before payment');
    $_POST['signature'] = 'bad';
    check($send($oldInvoice, 2, 'tx-old')[0] === 403, 'invalid signed callback rejected');
    $_POST['signature'] = $module->getCallbackSignature();
    check($send($oldInvoice, 2, 'tx-old', ['amount' => '11'])[0] === 409, 'wrong transaction amount rejected');
    check($send($oldInvoice, 2, 'tx-old', ['currency' => 'XXX'])[0] === 409, 'wrong transaction currency rejected');
    check($send($oldInvoice, 2, 'tx-old', ['orderId' => '0'])[0] === 409, 'wrong transaction order rejected');
    check($send($oldInvoice, 2, 'tx-old', ['state' => 3])[0] === 409, 'wrong transaction state rejected');
    check($send($oldInvoice, 2, 'tx-old', ['txid' => 'other'])[0] === 409, 'wrong transaction identifier rejected');
    $module->invoiceMatches = false;
    check($send($oldInvoice, 2, 'tx-old')[0] === 409, 'invoice-to-transaction mismatch rejected');
    $module->invoiceMatches = true;
    check($send($oldInvoice, 3, 'tx-old')[0] === 200, 'late failure acknowledged');
    check((int) (new Order($order->id))->current_state === (int) $order->current_state, 'late failure does not cancel current attempt');
    check($send($oldInvoice, 15, 'tx-old')[0] === 200 && (int) (new Order($order->id))->current_state === (int) $order->current_state, 'late timeout does not cancel current attempt');
    check($send($oldInvoice, 2, 'tx-old')[0] === 200, 'late success for known invoice accepted after verification');
    check((int) (new Order($order->id))->current_state === (int) Configuration::get('PS_OS_PAYMENT'), 'verified late success pays the existing order');
    $payments = (int) $db->getValue("SELECT COUNT(*) FROM " . _DB_PREFIX_ . "order_payment WHERE order_reference='" . pSQL($order->reference) . "'");
    $history = (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'order_history WHERE id_order=' . (int) $order->id);
    check($send($oldInvoice, 2, 'tx-old')[0] === 200, 'duplicate success acknowledged');
    check($payments === (int) $db->getValue("SELECT COUNT(*) FROM " . _DB_PREFIX_ . "order_payment WHERE order_reference='" . pSQL($order->reference) . "'") && $history === (int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'order_history WHERE id_order=' . (int) $order->id), 'duplicate IPN creates no extra payments or state actions');
    check($send($newInvoice, 3, 'tx-new')[0] === 200 && (int) (new Order($order->id))->current_state === (int) Configuration::get('PS_OS_PAYMENT'), 'current failure cannot downgrade a paid order');
    $shippingOrder = new Order($order->id);
    $shippingOrder->current_state = (int) Configuration::get('PS_OS_SHIPPING');
    $shippingOrder->update();
    check($send($newInvoice, 15, 'tx-new')[0] === 200 && (int) (new Order($order->id))->current_state === (int) Configuration::get('PS_OS_SHIPPING'), 'paid protection persists after shipping state');
    check($module->getOrderMetaByOrderId($order->id)['invoice_id'] === $newInvoice, 'late IPN never overwrites current invoice pointer');
    check((int) $db->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders WHERE id_cart=' . (int) $cart->id) === 1, 'all attempts remain attached to one order');
    echo "All integration checks passed. Rolling back fixtures.\n";
} finally {
    $db->execute('ROLLBACK');
}
