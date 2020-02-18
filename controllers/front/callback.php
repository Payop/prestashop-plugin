<?php

class PayopCallbackModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->callbackRequest();
        $this->setTemplate('module:payop/views/templates/front/callback.tpl');
    }

    /**
     *
     * @throws \PrestaShopDatabaseException
     * @throws \PrestaShopException
     */
    private function callbackRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $callback = json_decode(file_get_contents('php://input'));
            $callback = json_encode($callback);
            $callback = json_decode($callback, false);
            if (is_object($callback)) {
                if (isset($callback->invoice)) {
                    if ($this->callbackCheck($callback) === 'valid') {
                        $history = new OrderHistory();
                        $order_id = $callback->transaction->order->id;
                        $order = new Order($order_id);
                        $history->id_order = $callback->transaction->order->id;
                        if ($callback->transaction->state === 2) {
                            $history->changeIdOrderState(2, $callback->transaction->order->id);
                            $order->setCurrentState(2);
                        } elseif ($callback->transaction->state === 3 or $callback->transaction->state === 5) {
                            $history->changeIdOrderState(8, $callback->transaction->order->id);
                            $order->setCurrentState(6);
                        }
                    } else {
                        PrestaShopLogger::addLog("Callback is not valid");
                    }
                } else {
                    PrestaShopLogger::addLog("Old API detected. Please contact PayOp support");
                }
            } else {
                PrestaShopLogger::addLog("Callback is not an object");
            }
        } else {
            PrestaShopLogger::addLog("Invalid server request");
        }
    }

    /**
     * Check callback
     *
     * @param $callback
     *
     * @return string
     */
    private function callbackCheck($callback)
    {
        $invoiceId = !empty($callback->invoice->id) ? $callback->invoice->id : null;
        $txid = !empty($callback->invoice->txid) ? $callback->invoice->txid : null;
        $orderId = !empty($callback->transaction->order->id) ? $callback->transaction->order->id : null;
        $state = !empty($callback->transaction->state) ? $callback->transaction->state : null;

        if (!$invoiceId) {
            return 'Empty invoice id';
        }
        if (!$txid) {
            return 'Empty transaction id';
        }
        if (!$orderId) {
            return 'Empty order id';
        }
        if (!(1 <= $state && $state <= 5)) {
            return 'State is not valid';
        }
        return 'valid';
    }

    /**
     * Generate signature
     *
     * @param $orderId
     * @param $amount
     * @param $currency
     * @param $secretKey
     *
     * @return string
     */
    private function generateSignature($orderId, $amount, $currency, $secretKey)
    {
        $sign_str = ['id' => $orderId, 'amount' => $amount, 'currency' => $currency];
        ksort($sign_str, SORT_STRING);
        $sign_data = array_values($sign_str);
        array_push($sign_data, $secretKey);
        return hash('sha256', implode(':', $sign_data));
    }
}
