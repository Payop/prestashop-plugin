<?php

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
	exit;
}

require_once __DIR__ . '/classes/PayopPaymentOptions.php';
require_once __DIR__ . '/classes/PayopInvoiceHistory.php';

class Payop extends PaymentModule
{
	use PayopPaymentOptions;
	use PayopInvoiceHistory;

	const ORDER_META_TABLE = 'payop_order_meta';

	protected $logger;
	protected $translator;

	public function __construct()
	{
		$this->name = 'payop';
		$this->tab = 'payments_gateways';
		$this->version = '2.4.0';
		$this->author = 'PAYOP';
		$this->controllers = ['payment', 'validation', 'failPage', 'callback', 'success'];
		$this->currencies = true;
		$this->currencies_mode = 'checkbox';
		$this->bootstrap = true;
		$this->displayName = 'Payop';
		$this->description = 'Make payments via Payop';
		$this->confirmUninstall = 'Are you sure you want to uninstall this module?';
		$this->ps_versions_compliancy = ['min' => '8.0.0'];

		parent::__construct();
	}

	public function install()
	{
		return parent::install()
			&& $this->registerHook('paymentOptions')
			&& $this->registerHook('displayPaymentReturn')
			&& $this->registerHook('actionFrontControllerInitBefore')
			&& $this->installOrderState()
			&& $this->ensureStorageTable()
			&& $this->ensureInvoiceHistory();
	}

	public function uninstall()
	{
		return parent::uninstall();
	}

	public function getSettingsToken()
	{
		$employee = $this->context->employee;
		return hash_hmac('sha256', 'payop-settings|' . (int) $employee->id . '|' . (string) $employee->passwd, _COOKIE_KEY_);
	}

	public function processConfiguration()
	{
		if (!Tools::isSubmit('pc_form') && !Tools::isSubmit('payop_refresh_methods')) {
			return;
		}
		$provided = Tools::getValue('payop_settings_token');
		if (!is_string($provided) || !hash_equals($this->getSettingsToken(), $provided)) {
			$this->context->smarty->assign('payop_error', $this->l('Invalid settings token. Reload the page and try again.'));
			return;
		}
		if (Tools::isSubmit('payop_refresh_methods')) {
			$result = $this->loadPaymentMethods(true);
			$this->context->smarty->assign(empty($result['ok']) ? 'payop_error' : 'payop_notice', empty($result['ok']) ? $result['error'] : $this->l('Payment methods refreshed.'));
			return;
		}
		try {
			$buttons = $this->validateButtons(Tools::getValue('payop_buttons_json', '[]'));
			foreach (['enablePayments', 'displayName', 'description', 'publicKey', 'secretKey', 'apiToken'] as $field) {
				if (!is_scalar(Tools::getValue($field, ''))) {
					throw new InvalidArgumentException($this->l('Invalid settings value.'));
				}
			}
		} catch (InvalidArgumentException $e) {
			$this->context->smarty->assign('payop_error', $e->getMessage());
			return;
		}
		Configuration::updateValue('PAYOP_ENABLE', (int) Tools::getValue('enablePayments'));
		foreach (['PAYOP_NAME' => 'displayName', 'DESCRIPTION' => 'description', 'PAYOP_PUBLIC_KEY' => 'publicKey', 'PAYOP_SECRET_KEY' => 'secretKey', 'PAYOP_API_TOKEN' => 'apiToken'] as $key => $field) {
			Configuration::updateValue($key, trim((string) Tools::getValue($field, '')));
		}
		// Once the unified field is saved, do not revive a hidden legacy token.
		Configuration::updateValue('PAYOP_METHODS_TOKEN', '');
		Configuration::updateValue('PAYOP_BUTTONS', json_encode($buttons));
		$this->getCallbackSignature();
		$this->context->smarty->assign('confirmation', 'ok');
	}

	public function assignConfiguration()
	{
		$methods = $this->loadPaymentMethods();
		$editor = [
			'buttons' => $this->getAdditionalButtons(),
			'languages' => Language::getLanguages(false),
			'defaultLanguage' => (int) Configuration::get('PS_LANG_DEFAULT'),
			'methods' => $methods['methods'],
		];
		$this->context->smarty->assign([
			'enablePayments' => (int) Configuration::get('PAYOP_ENABLE'),
			'displayName' => Configuration::get('PAYOP_NAME'),
			'description' => Configuration::get('DESCRIPTION'),
			'publicKey' => Configuration::get('PAYOP_PUBLIC_KEY'),
			'secretKey' => Configuration::get('PAYOP_SECRET_KEY'),
			'apiToken' => $this->getMethodsToken(),
			'callbackUrl' => $this->getCallbackUrl(),
			'payop_settings_token' => $this->getSettingsToken(),
			'payop_buttons_json' => json_encode($this->getAdditionalButtons()),
			'payop_editor_json' => json_encode($editor, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
			'payop_methods_error' => empty($methods['ok']) ? $methods['error'] : '',
		]);
	}

	public function getContent()
	{
		$this->processConfiguration();
		$this->assignConfiguration();
		$this->context->controller->addJS($this->_path . 'views/js/payment-buttons.js');
		return $this->fetch('module:payop/views/templates/hook/getContent.tpl');
	}

	public function hookPaymentOptions()
	{
		if (!$this->active || !(bool) Configuration::get('PAYOP_ENABLE')) {
			return [];
		}
		$options = [];
		foreach ($this->getCheckoutButtons() as $button) {
			$action = $this->getFrontControllerUrl('validation', ['payop_option' => $button['id']]);
			$this->smarty->assign(['action' => $action, 'description' => $button['description']]);
			$option = new PaymentOption();
			$option->setModuleName($this->name)
				->setCallToActionText($button['name'])
				->setAction($action)
				->setForm($this->fetch('module:payop/views/templates/hook/payment_options.tpl'));
			$options[] = $option;
		}
		return $options;
	}

	public function hookActionFrontControllerInitBefore($params)
	{
		if (!isset($params['controller']->php_self) || $params['controller']->php_self !== 'order' || Tools::isSubmit('submitReorder')) {
			return;
		}
		$cartId = (int) $this->context->cookie->id_cart;
		$orderId = $cartId ? (int) Order::getIdByCartId($cartId) : 0;
		if (!$orderId && !$cartId) {
			$orderId = (int) $this->context->cookie->payop_retry_order;
		}
		if (!$orderId) {
			return;
		}
		$order = new Order($orderId);
		if (Validate::isLoadedObject($order) && $order->module === $this->name
			&& (int) $order->id_customer === (int) $this->context->customer->id && !$this->isOrderSettled($order)) {
			Tools::redirect($this->getFailUrl($order->id, $order->id_cart, $order->secure_key));
		}
	}

	private function installOrderState()
	{
		$configurationKey = 'PS_OS_PAYOP_PENDING_STATE';
		if (Configuration::getGlobalValue($configurationKey)) {
			return true;
		}

		$orderState = new OrderState();
		$orderState->module_name = $this->name;
		$orderState->color = '#D3D3D3';
		$orderState->logable = false;
		$orderState->paid = false;
		$orderState->invoice = false;
		$orderState->shipped = false;
		$orderState->delivery = false;
		$orderState->pdf_delivery = false;
		$orderState->pdf_invoice = false;
		$orderState->send_email = false;
		$orderState->hidden = false;
		$orderState->unremovable = true;
		$orderState->template = '';
		$orderState->deleted = false;

		$orderState->name = [];
		foreach (Language::getLanguages(false) as $language) {
			$orderState->name[(int) $language['id_lang']] = 'Pending Payment';
		}

		if (!$orderState->add()) {
			return false;
		}

		Configuration::updateGlobalValue($configurationKey, (int) $orderState->id);
		return true;
	}

	public function hookDisplayPaymentReturn($params)
	{
		if (!$this->active) {
			return;
		}
		return $this->fetch('module:payop/views/templates/hook/payment_return.tpl');
	}

	public function getCallbackSignature()
	{
		$signature = (string) Configuration::get('PAYOP_CALLBACK_SIGNATURE');
		if ($signature !== '') {
			return $signature;
		}

		try {
			$signature = bin2hex(random_bytes(32));
		} catch (Exception $e) {
			$signature = hash('sha256', $this->name . '|' . microtime(true) . '|' . mt_rand());
		}

		Configuration::updateValue('PAYOP_CALLBACK_SIGNATURE', $signature);
		return $signature;
	}

	public function getCallbackUrl()
	{
		return $this->getFrontControllerUrl('callback', [
			'signature' => $this->getCallbackSignature(),
		]);
	}

	public function getFailUrl($orderId, $cartId, $secureKey)
	{
		return $this->getFrontControllerUrl('failPage', [
			'id_order' => (int) $orderId,
			'id_cart' => (int) $cartId,
			'key' => (string) $secureKey,
			'signature' => $this->generateFailSignature($orderId, $cartId, $secureKey),
		]);
	}

	public function getSuccessUrl($orderId, $cartId, $secureKey)
	{
		return $this->getFrontControllerUrl('success', [
			'id_order' => (int) $orderId,
			'id_cart' => (int) $cartId,
			'key' => (string) $secureKey,
		]);
	}

	public function getOrderConfirmationUrl($orderId, $cartId, $secureKey)
	{
		return $this->getShopBaseUrl() . 'index.php?' . http_build_query([
			'controller' => 'order-confirmation',
			'id_cart' => (int) $cartId,
			'id_module' => (int) $this->id,
			'id_order' => (int) $orderId,
			'key' => (string) $secureKey,
		], '', '&');
	}

	public function getMethodsToken()
	{
		$token = trim((string) Configuration::get('PAYOP_API_TOKEN'));
		return $token !== '' ? $token : trim((string) Configuration::get('PAYOP_METHODS_TOKEN'));
	}

	public function generateFailSignature($orderId, $cartId, $secureKey)
	{
		return hash_hmac('sha256', implode('|', [
			(string) $orderId,
			(string) $cartId,
			(string) $secureKey,
		]), (string) Configuration::get('PAYOP_SECRET_KEY'));
	}

	public function ensureStorageTable()
	{
		static $isReady = null;
		if ($isReady !== null) {
			return $isReady;
		}

		$sql = 'CREATE TABLE IF NOT EXISTS `' . pSQL($this->getStorageTableName()) . '` (
			`id_payop_order_meta` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`id_order` INT UNSIGNED NOT NULL,
			`id_cart` INT UNSIGNED NOT NULL,
			`invoice_id` VARCHAR(191) NOT NULL DEFAULT \'\',
			`transaction_id` VARCHAR(191) NOT NULL DEFAULT \'\',
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			PRIMARY KEY (`id_payop_order_meta`),
			UNIQUE KEY `payop_order_meta_order` (`id_order`),
			KEY `payop_order_meta_invoice` (`invoice_id`),
			KEY `payop_order_meta_transaction` (`transaction_id`)
		) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

		$isReady = (bool) Db::getInstance()->execute($sql);
		return $isReady;
	}

	public function saveOrderMeta($orderId, $cartId, $invoiceId = null, $transactionId = null)
	{
		if (!$this->ensureStorageTable()) {
			return false;
		}

		$orderId = (int) $orderId;
		$cartId = (int) $cartId;
		$existing = $this->getOrderMetaByOrderId($orderId);
		$now = date('Y-m-d H:i:s');

		$data = [
			'id_order' => $orderId,
			'id_cart' => $cartId,
			'updated_at' => $now,
		];

		if ($invoiceId !== null) {
			$data['invoice_id'] = pSQL((string) $invoiceId);
		}

		if ($transactionId !== null) {
			$data['transaction_id'] = pSQL((string) $transactionId);
		}

		if ($existing) {
			return (bool) Db::getInstance()->update(self::ORDER_META_TABLE, $data, '`id_order` = ' . $orderId);
		}

		$data['invoice_id'] = isset($data['invoice_id']) ? $data['invoice_id'] : '';
		$data['transaction_id'] = isset($data['transaction_id']) ? $data['transaction_id'] : '';
		$data['created_at'] = $now;

		return (bool) Db::getInstance()->insert(self::ORDER_META_TABLE, $data);
	}

	public function getOrderMetaByOrderId($orderId)
	{
		if (!$this->ensureStorageTable()) {
			return false;
		}

		return Db::getInstance()->getRow(
			'SELECT * FROM `' . pSQL($this->getStorageTableName()) . '` WHERE `id_order` = ' . (int) $orderId
		);
	}

	public function getFrontControllerUrl($controller, array $params = [])
	{
		$query = array_merge([
			'fc' => 'module',
			'module' => $this->name,
			'controller' => (string) $controller,
		], $params);

		return $this->getShopBaseUrl() . 'index.php?' . http_build_query($query, '', '&');
	}

	private function getStorageTableName()
	{
		return _DB_PREFIX_ . self::ORDER_META_TABLE;
	}

	private function getShopBaseUrl()
	{
		$useSsl = (bool) Configuration::get('PS_SSL_ENABLED') || (bool) Configuration::get('PS_SSL_ENABLED_EVERYWHERE');
		$host = $useSsl && defined('_PS_BASE_URL_SSL_') && _PS_BASE_URL_SSL_ ? _PS_BASE_URL_SSL_ : _PS_BASE_URL_;

		return rtrim($host, '/') . __PS_BASE_URI__;
	}
}
