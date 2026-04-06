<?php

class PayopSuccessModuleFrontController extends ModuleFrontController
{

	public function initContent()
	{
		parent::initContent();

		$orderId = (int) Tools::getValue('id_order');
		$cartId = (int) Tools::getValue('id_cart');
		$secureKey = (string) Tools::getValue('key');

		if (!$orderId || !$cartId || $secureKey === '') {
			Tools::redirect('index.php?controller=order&step=1');
		}

		$order = new Order($orderId);
		if (!Validate::isLoadedObject($order)) {
			Tools::redirect('index.php?controller=order&step=1');
		}

		if ((int) $order->id_cart !== $cartId || !hash_equals((string) $order->secure_key, $secureKey)) {
			Tools::redirect('index.php?controller=order&step=1');
		}

		$this->context->smarty->assign([
			'order_id' => (int) $order->id,
			'order_reference' => (string) $order->reference,
			'order_history_url' => $this->context->link->getPageLink('history', true),
			'home_url' => $this->context->link->getPageLink('index', true),
			'is_customer_logged' => $this->context->customer && $this->context->customer->isLogged(),
		]);

		$this->setTemplate('module:payop/views/templates/front/success.tpl');
	}
}
