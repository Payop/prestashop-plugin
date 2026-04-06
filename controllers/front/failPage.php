<?php

class PayopFailPageModuleFrontController extends ModuleFrontController
{

	public function initContent()
	{
		parent::initContent();
		$this->processFailedPayment();
		$this->setTemplate('module:payop/views/templates/front/failPage.tpl');
	}

	private function processFailedPayment()
	{
		$orderId = (int) Tools::getValue('id_order');
		$cartId = (int) Tools::getValue('id_cart');
		$secureKey = (string) Tools::getValue('key');
		$signature = (string) Tools::getValue('signature');

		if (!$orderId || !$cartId || $secureKey === '' || $signature === '') {
			return;
		}

		$order = new Order($orderId);
		if (!Validate::isLoadedObject($order)) {
			return;
		}

		if ((string) $order->module !== (string) $this->module->name) {
			PrestaShopLogger::addLog('[Payop] Fail page rejected for non-Payop order #' . $orderId . '.');
			return;
		}

		if ((int) $order->id_cart !== $cartId || !hash_equals((string) $order->secure_key, $secureKey)) {
			PrestaShopLogger::addLog('[Payop] Fail page validation failed for order #' . $orderId . '.');
			return;
		}

		$expectedSignature = $this->module->generateFailSignature($orderId, $cartId, $secureKey);
		if (!hash_equals($expectedSignature, $signature)) {
			PrestaShopLogger::addLog('[Payop] Invalid fail page signature for order #' . $orderId . '.');
			return;
		}

		$pendingStatus = (int) Configuration::get('PS_OS_PAYOP_PENDING_STATE');
		if ((int) $order->getCurrentState() !== $pendingStatus) {
			return;
		}

		$orderMeta = $this->module->getOrderMetaByOrderId($orderId);
		if (!$orderMeta || empty($orderMeta['transaction_id'])) {
			return;
		}

		$transaction = $this->module->fetchTransaction($orderMeta['transaction_id']);
		$verification = $this->module->verifyTransactionForOrder($transaction, $order, null, $orderMeta['transaction_id']);
		if (empty($verification['ok'])) {
			$message = isset($verification['error']) ? $verification['error'] : 'verification mismatch';
			PrestaShopLogger::addLog('[Payop] Fail page transaction verification failed for order #' . $orderId . ': ' . $message . '.');
			return;
		}

		$state = (int) (isset($transaction['state']) ? $transaction['state'] : 0);
		if (in_array($state, [3, 5], true)) {
			$this->changeOrderState($order, (int) Configuration::get('PS_OS_ERROR'), true);
		} elseif ($state === 15) {
			$this->changeOrderState($order, (int) Configuration::get('PS_OS_CANCELED'));
		}
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
}
