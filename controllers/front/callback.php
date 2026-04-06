<?php

class PayopCallbackModuleFrontController extends ModuleFrontController
{

	public function initContent()
	{
		parent::initContent();
		$this->callbackRequest();
		$this->setTemplate('module:payop/views/templates/front/callback.tpl');
	}

	private function callbackRequest()
	{
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			$this->respond(405, 'method_not_allowed');
		}

		if (!$this->isValidCallbackSignature()) {
			PrestaShopLogger::addLog('[Payop] Callback signature validation failed.');
			$this->respond(403, 'invalid_signature');
		}

		$rawData = file_get_contents('php://input');
		$callback = json_decode($rawData, false);
		if (!$callback || !isset($callback->transaction->order->id, $callback->transaction->state, $callback->invoice->id)) {
			$this->respond(400, 'invalid_payload');
		}

		$orderId = (int) $callback->transaction->order->id;
		$state = (int) $callback->transaction->state;
		$invoiceId = (string) $callback->invoice->id;
		$transactionId = '';

		if (!empty($callback->transaction->id)) {
			$transactionId = (string) $callback->transaction->id;
		} elseif (!empty($callback->invoice->txid)) {
			$transactionId = (string) $callback->invoice->txid;
		}

		$order = new Order($orderId);
		if (!Validate::isLoadedObject($order)) {
			$this->respond(400, 'order_not_found');
		}

		if ((string) $order->module !== (string) $this->module->name) {
			PrestaShopLogger::addLog('[Payop] Callback rejected for non-Payop order #' . $orderId . '.');
			$this->respond(403, 'invalid_order_module');
		}

		$orderMeta = $this->module->getOrderMetaByOrderId($orderId);
		$hasStoredInvoice = $orderMeta && !empty($orderMeta['invoice_id']);
		if ($hasStoredInvoice && !hash_equals((string) $orderMeta['invoice_id'], $invoiceId)) {
			PrestaShopLogger::addLog('[Payop] Invoice mismatch for order #' . $orderId . '.');
			$this->respond(409, 'invoice_mismatch');
		}

		if ($transactionId === '') {
			PrestaShopLogger::addLog('[Payop] Callback rejected for order #' . $orderId . ' because transaction ID is missing.');
			$this->respond(409, 'missing_transaction_id');
		}

		if (!$this->module->hasApiToken()) {
			PrestaShopLogger::addLog('[Payop] Callback rejected for order #' . $orderId . ' because API token is missing.');
			$this->respond(409, 'missing_api_token');
		}

		$transaction = $this->module->fetchTransaction($transactionId);
		if (empty($transaction['ok'])) {
			PrestaShopLogger::addLog('[Payop] Transaction API lookup failed for order #' . $orderId . ': ' . (isset($transaction['error']) ? $transaction['error'] : 'unknown error') . '.');
			$this->respond(409, 'transaction_lookup_failed');
		}

		$verification = $this->module->verifyTransactionForOrder($transaction, $order, $state, $transactionId);
		if (empty($verification['ok'])) {
			PrestaShopLogger::addLog('[Payop] Transaction verification failed for order #' . $orderId . ': ' . $verification['error'] . '.');
			$this->respond(409, 'transaction_verification_failed');
		}

		$boundCartId = $orderMeta ? (int) $orderMeta['id_cart'] : (int) $order->id_cart;
		if (!$this->module->saveOrderMeta($orderId, $boundCartId, $invoiceId, $transactionId)) {
			PrestaShopLogger::addLog('[Payop] Failed to persist callback metadata for order #' . $orderId . '.');
			$this->respond(409, 'metadata_persist_failed');
		}

		$currentState = (int) $order->getCurrentState();
		$paidStatus = (int) Configuration::get('PS_OS_PAYMENT');
		$failedStatus = (int) Configuration::get('PS_OS_ERROR');
		$pendingStatus = (int) Configuration::get('PS_OS_PAYOP_PENDING_STATE');
		$timeoutStatus = (int) Configuration::get('PS_OS_CANCELED');

		switch ($state) {
			case 1:
			case 4:
			case 9:
				if ($currentState !== $paidStatus) {
					$this->changeOrderState($order, $pendingStatus);
				}
				break;

			case 2:
				if ($currentState !== $paidStatus) {
					$this->changeOrderState($order, $paidStatus, true);
					$this->addPaymentIfNeeded($order, $transactionId);
				}
				break;

			case 3:
			case 5:
				if ($currentState === $paidStatus) {
					PrestaShopLogger::addLog('[Payop] Ignored downgrade to failed for paid order #' . $orderId . '.');
					break;
				}
				$this->changeOrderState($order, $failedStatus, true);
				break;

			case 15:
				if ($currentState === $paidStatus) {
					PrestaShopLogger::addLog('[Payop] Ignored timeout downgrade for paid order #' . $orderId . '.');
					break;
				}
				$this->changeOrderState($order, $timeoutStatus);
				break;

			default:
				$this->respond(400, 'unsupported_state');
		}

		$this->respond(200, 'OK');
	}

	private function isValidCallbackSignature()
	{
		$providedSignature = (string) Tools::getValue('signature');
		$expectedSignature = (string) $this->module->getCallbackSignature();

		return $providedSignature !== '' && $expectedSignature !== '' && hash_equals($expectedSignature, $providedSignature);
	}

	private function changeOrderState(Order $order, $stateId, $sendEmail = false)
	{
		if ((int) $order->getCurrentState() === (int) $stateId) {
			return;
		}

		$history = new OrderHistory();
		$history->id_order = (int) $order->id;
		$history->changeIdOrderState((int) $stateId, $order);

		if ($sendEmail) {
			$history->addWithemail();
		}
	}

	private function addPaymentIfNeeded(Order $order, $transactionId)
	{
		if ($transactionId === '') {
			return;
		}

		$orderTotal = (float) $order->total_paid;
		$currency = new Currency((int) $order->id_currency);
		$payments = $order->getOrderPayments();

		foreach ($payments as $payment) {
			if (
				(!empty($payment->transaction_id) && $payment->transaction_id === $transactionId) ||
				(float) $payment->amount === $orderTotal
			) {
				return;
			}
		}

		$order->addOrderPayment($orderTotal, 'PayOp', $transactionId, $currency);
	}

	private function respond($statusCode, $body = '')
	{
		$statusMap = [
			200 => 'OK',
			400 => 'Bad Request',
			403 => 'Forbidden',
			405 => 'Method Not Allowed',
			409 => 'Conflict',
		];

		$statusText = isset($statusMap[$statusCode]) ? $statusMap[$statusCode] : 'Error';
		header('HTTP/1.1 ' . (int) $statusCode . ' ' . $statusText);
		if ($body !== '') {
			header('Content-Type: text/plain; charset=utf-8');
			echo $body;
		}
		exit;
	}
}
