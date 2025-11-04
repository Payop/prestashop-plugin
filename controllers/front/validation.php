<?php

class PayopValidationModuleFrontController extends ModuleFrontController
{
	public function postProcess()
	{
		$context = $this->context;
		$cart = $context->cart;
		$cartId = $cart->id;
		$customer = new Customer($cart->id_customer);
		$language = Configuration::get('PAYOP_LANGUAGE') ?: 'en';

		if (!$this->module->active || $cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice  == 0) {
			Tools::redirect('index.php?controller=order&step=1');
		}

		$customer = new Customer($cart->id_customer);
		if (!Validate::isLoadedObject($customer)) {
			Tools::redirect('index.php?controller=order&step=1');
		}

		// Create order in PrestaShop
		$this->module->validateOrder(
			(int) $this->context->cart->id,
			Configuration::get('PS_OS_PAYOP_PENDING_STATE'),
			(float) $this->context->cart->getOrderTotal(true, Cart::BOTH),
			$this->module->displayName,
			null,
			null,
			(int) $this->context->currency->id,
			false,
			$customer->secure_key
		);

		$idOrder = (int) $this->module->currentOrder;

		if (!$idOrder) {
			Tools::redirect('index.php?controller=order&step=1');
		}

		try {
			$order = new Order($idOrder);
			$address = new Address($cart->id_address_delivery);
			$currency = Currency::getCurrency($order->id_currency);

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
					'id' => (string) $this->module->currentOrder,
					'amount' => number_format((float) $order->total_paid, 2, '.', ''),
					'currency' => $currency['iso_code'],
					'description' => 'Payment order #' . $this->module->currentOrder,
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
				'resultUrl' => $this->context->link->getPageLink('order-confirmation', true, null, [
					'id_cart' => (int) $cart->id,
					'id_module' => (int) $this->module->id,
					'id_order' => (int) $this->module->currentOrder,
					'key' => $customer->secure_key,
				]),
				'failPath' => _PS_BASE_URL_ . __PS_BASE_URI__ . "index.php?fc=module&module=payop&controller=failPage&id_order=" . (int)$this->module->currentOrder,
				'signature' => $this->generateSignature(
					(string) $this->module->currentOrder,
					(float) $order->total_paid,
					$currency['iso_code'],
					Configuration::get('PAYOP_SECRET_KEY')
				),
				'language' => $language,
			];

			// Send request to Payop API
			$url = 'https://api.payop.com/v1/invoices/create';
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			$result = curl_exec($ch);
			curl_close($ch);

			$response = json_decode($result, true);

			// Redirect user to payment page or failure page
			if (!empty($response['data'])) {
				$invoiceId = $response['data'];

				Tools::redirect('https://checkout.payop.com/' . $language . '/payment/invoice-preprocessing/' . $invoiceId);
			} else {
				Tools::redirect(_PS_BASE_URL_ . __PS_BASE_URI__ . "index.php?fc=module&module=payop&controller=failPage");
			}
		} catch (Exception $e) {
			PrestaShopLogger::addLog("[Payop] Exception: " . $e->getMessage());
			Tools::redirect(_PS_BASE_URL_ . __PS_BASE_URI__ . "index.php?fc=module&module=payop&controller=failPage&cart_id=" . $cartId);
		}
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
