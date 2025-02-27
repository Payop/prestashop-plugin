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
		// Check if order ID is present
		if (!isset($_GET['id_order'])) {
			return;
		}

		$order_id = (int) $_GET['id_order'];

		// Validate order
		$order = new Order($order_id);
		if (!Validate::isLoadedObject($order)) {
			return;
		}

		// Get failed status from PrestaShop configuration
		$failed_status = Configuration::get('PS_OS_ERROR');

		// If order is not already set to FAILED, update it
		if ($order->getCurrentState() !== $failed_status) {
			$history = new OrderHistory();
			$history->id_order = $order_id;
			$history->changeIdOrderState($failed_status, $order_id);
			$history->addWithemail();
			$order->setCurrentState($failed_status);
			$order->save();
		}
	}
}
