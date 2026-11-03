<?php
// Standalone catalogue regression checks; no network or shop required.
require dirname(__DIR__) . '/classes/PayopPaymentOptions.php';
class Configuration
{
    public static $values = [];
    public static function get($key) { return isset(self::$values[$key]) ? self::$values[$key] : false; }
    public static function updateValue($key, $value) { self::$values[$key] = $value; }
}
class MethodsProbe
{
    use PayopPaymentOptions;
    public $calls = [];
    public $response = ['ok' => true, 'data' => [['identifier' => 100, 'title' => 'Card']]];
    public function l($text) { return $text; }
    public function getMethodsToken() { return Configuration::get('PAYOP_API_TOKEN'); }
    protected function requestPaymentMethods($project, $token) {
        $this->calls[] = [$project, $token];
        return $this->response;
    }
}
function check($condition, $message) {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
}
$project = '1e2d34f0-3aa6-472f-bea6-a67fe752b45a';
$other = '36c1fbc0-c9fd-4131-9afd-068613ee97f0';
Configuration::$values = ['PAYOP_PROJECT_ID' => $project, 'PAYOP_PUBLIC_KEY' => 'application-' . $other, 'PAYOP_API_TOKEN' => 'new-merchant-jwt'];
$module = new MethodsProbe();
check($module->loadPaymentMethods()['ok'] && $module->calls === [[$project, 'new-merchant-jwt']], 'transferred project uses explicit ID, never Public Key suffix');
check($module->loadPaymentMethods()['ok'] && count($module->calls) === 1, 'same project uses cached catalogue');
Configuration::$values['PAYOP_PROJECT_ID'] = $other;
check($module->getCachedPaymentMethods() === [], 'changing Project ID hides previous project catalogue');
check($module->loadPaymentMethods()['ok'] && $module->calls[1][0] === $other, 'changing Project ID bypasses previous response cache');
Configuration::$values['PAYOP_API_TOKEN'] = 'changed-jwt';
check($module->loadPaymentMethods()['ok'] && $module->calls[2][1] === 'changed-jwt', 'changing JWT refreshes catalogue');
Configuration::$values['PAYOP_PUBLIC_KEY'] = 'application-changed';
check($module->getCachedPaymentMethods() === [], 'changing project credentials invalidates catalogue');
foreach (['', 'not-a-project', '../project'] as $invalid) {
    Configuration::$values['PAYOP_PROJECT_ID'] = $invalid;
    $count = count($module->calls);
    $result = $module->loadPaymentMethods(true);
    check(!$result['ok'] && count($module->calls) === $count && strpos($result['error'], 'Project ID') !== false,
        'missing or malformed Project ID fails without deriving an ID or making a request');
}
Configuration::$values['PAYOP_PROJECT_ID'] = $project;
Configuration::$values['PAYOP_API_TOKEN'] = '';
$count = count($module->calls);
check(!$module->loadPaymentMethods(true)['ok'] && count($module->calls) === $count, 'missing JWT fails before request');
Configuration::$values['PAYOP_API_TOKEN'] = 'valid-jwt';
$module->loadPaymentMethods(true);
Configuration::$values['PAYOP_BUTTONS'] = '[{"id":"saved","payment_method":"100"}]';
$module->response = ['ok' => false, 'error' => 'Insufficient permissions'];
$result = $module->loadPaymentMethods(true);
check(!$result['ok'] && $result['methods'][0]['identifier'] === '100' && $module->getAdditionalButtons()[0]['id'] === 'saved', 'API permissions error preserves saved catalogue and buttons');
echo "All payment method checks passed.\n";
