<?php
class PayopCreateOrderModuleFrontController  extends ModuleFrontController
{
	public function postProcess()
	{
		$this->handleCustomerReturn();
	}

	private function handleCustomerReturn()
	{
		$cartId = (int) Tools::getValue('cart_id');

		if (!$cartId) {
			Tools::redirect($this->context->link->getPageLink('index', true));
		}

		$cart = new Cart($cartId);
		if (!Validate::isLoadedObject($cart)) {
			Tools::redirect($this->context->link->getPageLink('index', true));
		}

		if (!$cart->orderExists()) {
			$module = Module::getInstanceByName('payop');

			$module->validateOrder(
				$cartId,
				(int) Configuration::get('PS_OS_PAYMENT'),
				(float) $cart->getOrderTotal(true, Cart::BOTH),
				$module->displayName,
				null,
				null,
				(int) $this->context->currency->id,
				false,
				$cart->secure_key
			);
		}

		if ($cart->orderExists()) {
			$orderId = (int) Order::getOrderByCartId($cartId);
			Tools::redirect(
				'index.php?controller=order-confirmation'
				. '&id_cart=' . $cartId
				. '&id_order=' . $orderId
				. '&id_module=' . $this->module->id
				. '&key=' . $cart->secure_key
			);
		}

		$this->context->cookie->id_cart = $cartId;
		$this->context->cart = $cart;

		Tools::redirect($this->context->link->getPageLink('order', true, null, 'step=1'));
	}
}
