<?php

class PayopValidationModuleFrontController extends ModuleFrontController
{

	public function postProcess()
	{
		$button = $this->module->resolvePaymentButton(Tools::getValue('payop_option', 'default'));
		if (!$this->module->active || !(bool) Configuration::get('PAYOP_ENABLE') || !$button) {
			header('HTTP/1.1 400 Bad Request');
			exit('Payment option is unavailable.');
		}
		$retryOrderId = (int) Tools::getValue('id_order');
		$retryFromCookie = false;
		if (!$retryOrderId && !(int) $this->context->cart->id) {
			$retryOrderId = (int) $this->context->cookie->payop_retry_order;
			$retryFromCookie = $retryOrderId > 0;
		}
		if ($retryOrderId) {
			$order = new Order($retryOrderId);
			$key = Tools::getValue('key');
			$signature = Tools::getValue('signature');
			if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name
				|| (!$retryFromCookie && (!is_string($key) || !is_string($signature)
					|| !hash_equals((string) $order->secure_key, $key)
					|| !hash_equals($this->module->generateFailSignature($order->id, $order->id_cart, $key), $signature)))
				|| (int) $order->id_customer !== (int) $this->context->customer->id) {
				header('HTTP/1.1 403 Forbidden');
				exit('Invalid payment retry.');
			}
			$cart = new Cart((int) $order->id_cart);
		} else {
			$cart = $this->context->cart;
		}
		$cartId = (int) $cart->id;
		$customer = new Customer((int) $cart->id_customer);
		$language = Configuration::get('PAYOP_LANGUAGE') ?: 'en';
		if (!Validate::isLoadedObject($cart) || !Validate::isLoadedObject($customer)
			|| (int) $cart->id_customer !== (int) $this->context->customer->id
			|| !$cart->id_address_delivery || !$cart->id_address_invoice) {
			Tools::redirect('index.php?controller=order&step=1');
		}
		if (!$this->module->acquireCartLock($cartId)) {
			header('HTTP/1.1 409 Conflict');
			exit('Payment is being processed. Please retry shortly.');
		}
		$idOrder = 0;
		try {
			$idOrder = (int) Order::getIdByCartId($cartId);
			if (!$idOrder) {
				$this->context->cart = $cart;
				$this->module->validateOrder($cartId, Configuration::get('PS_OS_PAYOP_PENDING_STATE'),
					(float) $cart->getOrderTotal(true, Cart::BOTH), $this->module->displayName,
					null, null, (int) $cart->id_currency, false, $customer->secure_key);
				$idOrder = (int) $this->module->currentOrder;
			}
			$order = new Order($idOrder);
			if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name
				|| (int) $order->id_customer !== (int) $customer->id || $this->module->isOrderSettled($order)) {
				throw new Exception('Order is not available for another payment attempt.');
			}
			$this->context->cookie->payop_retry_order = $idOrder;
			$this->context->cookie->write();
			$address = new Address($cart->id_address_delivery);
			$currency = Currency::getCurrency($order->id_currency);
			$failUrl = $this->module->getFailUrl($idOrder, (int) $cart->id, $customer->secure_key);
			$successUrl = $this->module->getSuccessUrl($idOrder, (int) $cart->id, $customer->secure_key);

			// Retrieve order products
			$order_products = $order->getProducts();
			$items = [];
			foreach ($order_products as $product) {
				$items[] = [
					'id' => (string) $product['product_id'],
					'name' => $product['product_name'],
					'price' => number_format($product['unit_price_tax_incl'], 2, '.', ''),
					'quantity' => (int) $product['product_quantity'],
				];
			}

			// Prepare payment request data
			$request = [
				'publicKey' => Configuration::get('PAYOP_PUBLIC_KEY'),
				'order' => [
					'id' => (string) $idOrder,
					'amount' => number_format((float) $order->total_paid, 2, '.', ''),
					'currency' => $currency['iso_code'],
					'description' => 'Payment order #' . $idOrder,
					'items' => $items,
				],
				'payer' => [
					'email' => $customer->email,
					'name' => $customer->firstname . ' ' . $customer->lastname,
					'phone' => $address->phone ?: '',
					"extraFields" => [
						"date_of_birth" => $customer->birthday ?: '',
					]
				],
				'resultUrl' => $successUrl,
				'failPath' => $failUrl,
				'signature' => $this->generateSignature(
					(string) $idOrder,
					(float) $order->total_paid,
					$currency['iso_code'],
					Configuration::get('PAYOP_SECRET_KEY')
				),
				'language' => $language,
			];

			$request = $this->module->applyInvoicePaymentMethod($request, $button);

			$invoice = $this->module->createInvoice($request);
			if (empty($invoice['ok'])) {
				throw new RuntimeException('Payop invoice creation failed.');
			}
			$invoiceId = $invoice['invoice_id'];
			if (!$this->module->rememberInvoice($idOrder, $cartId, $invoiceId, $button)) {
				throw new RuntimeException('Unable to persist Payop invoice metadata.');
			}
			$this->module->releaseCartLock($cartId);
			return $this->redirectPayment('https://checkout.payop.com/' . rawurlencode($language) . '/payment/invoice-preprocessing/' . rawurlencode($invoiceId));

		} catch (Throwable $e) {
			PrestaShopLogger::addLog("[Payop] Exception: " . $e->getMessage());
			$this->module->releaseCartLock($cartId);
			return $this->redirectPayment($this->module->getFailUrl($idOrder, (int) $cartId, $customer->secure_key));
		}
	}

	protected function redirectPayment($url)
	{
		Tools::redirect($url);
	}

	private function generateSignature($orderId, $amount, $currency, $secretKey)
	{
		$data = [
			'id' => (string) $orderId,
			'amount' => number_format((float) $amount, 2, '.', ''),
			'currency' => $currency,
		];
		ksort($data, SORT_STRING);

		return hash('sha256', implode(':', array_values($data)) . ':' . $secretKey);
	}
}
