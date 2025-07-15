<?php

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
	exit;
}

class Payop extends PaymentModule
{
	protected $logger;
	protected $translator;

	public function __construct()
	{
		$this->name = 'payop';
		$this->tab = 'payments_gateways';
		$this->version = '2.0.0';
		$this->author = 'PAYOP';
		$this->controllers = ['payment', 'validation', 'failPage', 'callback', 'createOrder'];
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
			&& $this->registerHook('PaymentOptions')
			&& $this->registerHook('PaymentReturn')
			&& $this->installOrderState();
	}

	public function uninstall()
	{
		return parent::uninstall();
	}

	public function processConfiguration()
	{
		if (Tools::isSubmit('pc_form')) {
			Configuration::updateValue('PAYOP_ENABLE', (int) Tools::getValue('enablePayments'));
			Configuration::updateValue('PAYOP_NAME', Tools::getValue('displayName'));
			Configuration::updateValue('DESCRIPTION', Tools::getValue('description'));
			Configuration::updateValue('PAYOP_PUBLIC_KEY', Tools::getValue('publicKey'));
			Configuration::updateValue('PAYOP_SECRET_KEY', Tools::getValue('secretKey'));
			
			$this->context->smarty->assign('confirmation', 'ok');
		}
	}

	public function assignConfiguration()
	{
		$this->context->smarty->assign([
			'enablePayments' => (int) Configuration::get('PAYOP_ENABLE'),
			'displayName' => Configuration::get('PAYOP_NAME'),
			'description' => Configuration::get('DESCRIPTION'),
			'publicKey' => Configuration::get('PAYOP_PUBLIC_KEY'),
			'secretKey' => Configuration::get('PAYOP_SECRET_KEY')
		]);
	}

	public function getContent()
	{
		$this->processConfiguration();
		$this->assignConfiguration();
		return $this->fetch('module:payop/views/templates/hook/getContent.tpl');
	}

	public function hookPaymentOptions()
	{
		if (!$this->active) {
			return;
		}

		$formAction = $this->context->link->getModuleLink($this->name, 'validation', [], true);
		$this->smarty->assign(['action' => $formAction]);
		$paymentForm = $this->fetch('module:payop/views/templates/hook/payment_options.tpl');

		$newOption = new PaymentOption();
		$newOption->setModuleName($this->displayName)
			->setCallToActionText(Configuration::get('PAYOP_NAME'))
			->setAction($formAction)
			->setForm($paymentForm);

		return [$newOption];
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

	public function hookPaymentReturn($params)
	{
		if (!$this->active) {
			return;
		}
		return $this->fetch('module:payop/views/templates/hook/payment_return.tpl');
	}
}
