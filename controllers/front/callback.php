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
		$rawData = file_get_contents('php://input');

		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			header("HTTP/1.1 400 Bad Request");
			exit;
		}

		$callback = json_decode($rawData, false);
		if (!$callback) {
			header("HTTP/1.1 400 Bad Request");
			exit;
		}

		if (!isset($callback->transaction->order->id, $callback->transaction->state, $callback->invoice->id)) {
			header("HTTP/1.1 400 Bad Request");
			exit;
		}

		$order_id = (int) $callback->transaction->order->id;
		$state = (int) $callback->transaction->state;
		$invoice_id = $callback->invoice->id;
		$transaction_id = isset($callback->transaction->id) ? $callback->transaction->id : $invoice_id;

		// Fetch the order
		$order = new Order($order_id);
		if (!Validate::isLoadedObject($order)) {
			header("HTTP/1.1 400 Bad Request");
			exit;
		}

		// Get PrestaShop order statuses
		$paid_status = Configuration::get('PS_OS_PAYMENT'); // Paid
		$failed_status = Configuration::get('PS_OS_ERROR'); // Failed
		$pending_status = Configuration::get('PS_OS_PAYOP_PENDING_STATE'); // Pending
		$timeout_status = Configuration::get('PS_OS_CANCELED'); // Timeout

		$history = new OrderHistory();
		$history->id_order = $order_id;

		// Handle transaction states
		switch ($state) {
			case 1: // new
			case 4: // pending
			case 9: // pre-approved → Use pending
				$history->changeIdOrderState($pending_status, $order);
				break;

			case 2: // accepted (paid successfully)
				$history->changeIdOrderState($paid_status, $order);
				$history->addWithemail();

				// Add payment ONLY if it is not already there
				$order_total = (float) $order->getOrdersTotalPaid();
				$currency = new Currency($order->id_currency);
				$paymentMethod = 'PayOp';
				$payments = $order->getOrderPayments();

				$needAddPayment = true;
				foreach ($payments as $payment) {
					// If there is already a payment for the same amount OR with the same transaction_id, a second one is not needed
					if (
						(float) $payment->amount == $order_total ||
						(!empty($payment->transaction_id) && $payment->transaction_id === $transaction_id)
					) {
						$needAddPayment = false;
						break;
					}
				}

				// ---- Add a payment if it doesn't exist yet ----
				if ($needAddPayment) {
					$order->addOrderPayment(
						$order_total,
						$paymentMethod,
						$transaction_id,
						$currency
					);
				}
				break;

			case 3: // failed
			case 5: // failed (payment error)
				$history->changeIdOrderState($failed_status, $order);
				$history->addWithemail();
				break;

			case 15: // timeout
				$history->changeIdOrderState($timeout_status, $order);
				break;

			default:
				header("HTTP/1.1 400 Bad Request");
				exit;
		}

		header("HTTP/1.1 200 OK");
		exit;
	}
}
