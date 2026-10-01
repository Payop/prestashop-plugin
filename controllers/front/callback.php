<?php

class PayopCallbackModuleFrontController extends ModuleFrontController
{
	private $lockedCartId = 0;

	public function initContent()
	{
		parent::initContent();
		try {
			$this->callbackRequest();
		} catch (Throwable $e) {
			PrestaShopLogger::addLog('[Payop] Callback processing failed for a verified request.');
			$this->respond(409, 'processing_failed');
		}
		$this->setTemplate('module:payop/views/templates/front/callback.tpl');
	}

	protected function callbackRequest()
	{
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			$this->respond(405, 'method_not_allowed');
		}

		if (!$this->isValidCallbackSignature()) {
			PrestaShopLogger::addLog('[Payop] Callback signature validation failed.');
			$this->respond(403, 'invalid_signature');
		}

		$callback = $this->readCallbackPayload();
		if (!$callback || !isset($callback->transaction->order->id, $callback->transaction->state, $callback->invoice->id)
			|| !is_scalar($callback->transaction->order->id) || !is_scalar($callback->transaction->state)
			|| !is_scalar($callback->invoice->id)
			|| (isset($callback->transaction->id) && !is_scalar($callback->transaction->id))
			|| (isset($callback->invoice->txid) && !is_scalar($callback->invoice->txid))) {
			$this->respond(400, 'invalid_payload');
		}

		$orderId = (int) $callback->transaction->order->id;
		$state = (int) $callback->transaction->state;
		if (!in_array($state, [1, 2, 3, 4, 5, 9, 15], true)) {
			$this->respond(400, 'unsupported_state');
		}
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

		if (!$this->module->acquireCartLock($order->id_cart)) {
			$this->respond(409, 'payment_busy');
		}
		$this->lockedCartId = (int) $order->id_cart;
		// Reload after the lock: another IPN may have completed payment meanwhile.
		$order = new Order($orderId);
		$invoiceContext = $this->module->getInvoiceContext($orderId, $invoiceId);
		if (!$invoiceContext || (int) $invoiceContext['id_cart'] !== (int) $order->id_cart) {
			$this->respond(409, 'unknown_invoice');
		}

		if (!empty($invoiceContext['transaction_id']) && !hash_equals($invoiceContext['transaction_id'], $transactionId)) {
			$this->respond(409, 'transaction_binding_mismatch');
		}

		if ($transactionId === '') {
			PrestaShopLogger::addLog('[Payop] Callback rejected for order #' . $orderId . ' because transaction ID is missing.');
			$this->respond(409, 'missing_transaction_id');
		}

		$invoice = $this->module->fetchInvoice($invoiceId);
		$verification = $this->module->verifyInvoiceForOrder($invoice, $order, $invoiceId, $transactionId, $state);
		if (empty($verification['ok'])) {
			PrestaShopLogger::addLog('[Payop] Invoice verification failed for order #' . $orderId . '.');
			$this->respond(409, 'invoice_verification_failed');
		}
		$state = (int) $verification['state'];
		if (!$this->module->mayChangeInvoiceOrderState($order, $invoiceId, $state)) {
			if ($state === 2) {
				$this->addPaymentIfNeeded($order, $transactionId);
			}
			// Keep the immutable invoice context, including verified late events.
			if (!$this->module->recordInvoiceTransaction($orderId, $invoiceId, $transactionId, $state)) {
				$this->respond(409, 'metadata_persist_failed');
			}
			$this->respond(200, 'OK');
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
				}
				$this->addPaymentIfNeeded($order, $transactionId);
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

		if (!$this->module->recordInvoiceTransaction($orderId, $invoiceId, $transactionId, $state)) {
			$this->respond(409, 'metadata_persist_failed');
		}
		$this->respond(200, 'OK');
	}

	private function isValidCallbackSignature()
	{
		$providedSignature = Tools::getValue('signature');
		// Legacy merchants can keep their existing unsigned callback URL.
		// Every event still requires full server-side invoice verification below.
		if ($providedSignature === false || $providedSignature === null || $providedSignature === '') {
			return true;
		}
		$expectedSignature = (string) $this->module->getCallbackSignature();

		return is_string($providedSignature) && $providedSignature !== '' && $expectedSignature !== '' && hash_equals($expectedSignature, $providedSignature);
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
		} else {
			$history->add();
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
		$paymentWithoutTransaction = null;

		foreach ($payments as $payment) {
			if (!empty($payment->transaction_id) && $payment->transaction_id === $transactionId) {
				return;
			}

			if ((float) $payment->amount === $orderTotal) {
				if (!empty($payment->transaction_id)) {
					return;
				}

				if ($paymentWithoutTransaction === null) {
					$paymentWithoutTransaction = $payment;
				}
			}
		}

		if ($paymentWithoutTransaction instanceof OrderPayment) {
			$paymentWithoutTransaction->transaction_id = pSQL((string) $transactionId);
			if (empty($paymentWithoutTransaction->payment_method)) {
				$paymentWithoutTransaction->payment_method = (string) $order->payment;
			}

			if (!$paymentWithoutTransaction->update()) {
				throw new RuntimeException('Failed to update order payment transaction ID.');
			}

			return;
		}

		$paymentMethod = trim((string) $order->payment);
		if ($paymentMethod === '') {
			$paymentMethod = (string) $this->module->displayName;
		}

		if (!$order->addOrderPayment($orderTotal, $paymentMethod, $transactionId, $currency)) {
			throw new RuntimeException('Failed to persist order payment.');
		}
	}

	protected function readCallbackPayload()
	{
		return json_decode(file_get_contents('php://input'));
	}

	protected function releaseCallbackLock()
	{
		if ($this->lockedCartId) {
			$this->module->releaseCartLock($this->lockedCartId);
			$this->lockedCartId = 0;
		}
	}

	protected function respond($statusCode, $body = '')
	{
		$this->releaseCallbackLock();
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
