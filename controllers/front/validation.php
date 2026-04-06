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

		if (!$this->module->active || !(bool) Configuration::get('PAYOP_ENABLE') || $cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice  == 0) {
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
				'resultUrl' => $successUrl,
				'failPath' => $failUrl,
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
			$responseHeaders = [];
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, $headerLine) use (&$responseHeaders) {
				$headerLength = strlen($headerLine);
				$headerParts = explode(':', $headerLine, 2);
				if (count($headerParts) === 2) {
					$responseHeaders[Tools::strtolower(trim($headerParts[0]))] = trim($headerParts[1]);
				}

				return $headerLength;
			});
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($ch, CURLOPT_TIMEOUT, 20);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
			$result = curl_exec($ch);
			$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curlError = curl_error($ch);
			curl_close($ch);

			$response = json_decode($result, true);

			// Redirect user to payment page or failure page
				$invoiceId = '';
				if (!empty($responseHeaders['identifier'])) {
					$invoiceId = (string) $responseHeaders['identifier'];
				} elseif (isset($response['data']) && !is_array($response['data'])) {
					$invoiceId = (string) $response['data'];
				} elseif (!empty($response['data']['id'])) {
					$invoiceId = (string) $response['data']['id'];
				}

			if ($httpCode >= 200 && $httpCode < 300 && $invoiceId !== '') {
				if (!$this->module->saveOrderMeta($idOrder, (int) $cart->id, $invoiceId)) {
					throw new Exception('Unable to persist Payop invoice metadata.');
				}

				Tools::redirect('https://checkout.payop.com/' . $language . '/payment/invoice-preprocessing/' . $invoiceId);
			} else {
				if ($curlError) {
					PrestaShopLogger::addLog('[Payop] Invoice creation failed: ' . $curlError);
				} else {
					PrestaShopLogger::addLog('[Payop] Unexpected invoice creation response. HTTP code: ' . $httpCode);
				}

				Tools::redirect($failUrl);
			}
		} catch (Exception $e) {
			PrestaShopLogger::addLog("[Payop] Exception: " . $e->getMessage());
			Tools::redirect($this->module->getFailUrl($idOrder, (int) $cartId, $customer->secure_key));
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
