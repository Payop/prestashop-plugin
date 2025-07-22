<?php

class PayopValidationModuleFrontController extends ModuleFrontController
{
	public function postProcess()
	{
		$cart = $this->context->cart;
		$language = Configuration::get('PAYOP_LANGUAGE') ?: 'en';
		$cartId = (int) $cart->id;

		if (!$this->module->active || $cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice  == 0) {
			Tools::redirect('index.php?controller=order&step=1');
		}

		$customer = new Customer($cart->id_customer);
		if (!Validate::isLoadedObject($customer)) {
			Tools::redirect('index.php?controller=order&step=1');
		}

		$items = [];
		foreach ($cart->getProducts() as $product) {
			$items[] = [
				'id' => (string) $product['id_product'],
				'name' => $product['name'],
				'price' => number_format((float) $product['price_wt'], 2, '.', ''),
				'quantity' => (int) $product['cart_quantity'],
			];
		}

		$address = new Address($cart->id_address_delivery);

		$amount = number_format((float) $cart->getOrderTotal(true, Cart::BOTH), 2, '.', '');
		$currencyObj = Currency::getCurrency($cart->id_currency);
		$currency = $currencyObj['iso_code'];

		$request = [
			'publicKey' => Configuration::get('PAYOP_PUBLIC_KEY'),
			'order' => [
				'id' => (string) $cartId,
				'amount' => $amount,
				'currency' => $currency,
				'description' => 'Payment order #' . $cartId,
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
			'resultUrl' => $this->context->link->getModuleLink($this->module->name, 'createOrder', [
				'cart_id' => $cartId,
				'key' => $customer->secure_key,
			], true),
    	'failPath' => $this->context->link->getModuleLink($this->module->name, 'failPage', ['cart_id' => $cartId], true),
			'signature' => $this->generateSignature(
				(string) $cartId,
				$amount,
				$currency,
				Configuration::get('PAYOP_SECRET_KEY')
			),
			'language' => $language,
		];

		try {
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
			if (isset($response['data'])) {
				Tools::redirect('https://checkout.payop.com/' . $language . '/payment/invoice-preprocessing/' . $response['data']);
			} else {
				Tools::redirect(_PS_BASE_URL_ . __PS_BASE_URI__ . "index.php?fc=module&module=payop&controller=failPage&cart_id=" . $cartId);
			}
		} catch (Exception $e) {
			PrestaShopLogger::addLog("[Payop] Exception: " . $e->getMessage());
			Tools::redirect(_PS_BASE_URL_ . __PS_BASE_URI__ . "index.php?fc=module&module=payop&controller=failPage&cart_id=" . $cartId);
		}
	}

	/**
	 * Signature generation
	 * Payop requires sorting parameters before signing
	 *
	 * @param string $orderId
	 * @param float $amount
	 * @param string $currency
	 * @param string $secretKey
	 *
	 * @return string
	 */
	private function generateSignature($orderId, $amount, $currency, $secretKey)
	{
		$data = [
			'id' => (string) $orderId,
			'amount' => number_format((float) $amount, 2, '.', ''),
			'currency' => $currency,
		];

		ksort($data, SORT_STRING);
		$stringToSign = implode(':', array_values($data)) . ':' . $secretKey;

		return hash('sha256', $stringToSign);
	}
}
