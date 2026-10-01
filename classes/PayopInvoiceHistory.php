<?php

trait PayopInvoiceHistory
{
    private $invoiceHistoryReady = false;
    public function ensureInvoiceHistory()
    {
        if ($this->invoiceHistoryReady) {
            return true;
        }
        if (!$this->ensureStorageTable()) {
            return false;
        }
        $table = _DB_PREFIX_ . 'payop_invoice_history';
        $sql = 'CREATE TABLE IF NOT EXISTS `' . pSQL($table) . '` (
            `id_payop_invoice` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT UNSIGNED NOT NULL,
            `id_cart` INT UNSIGNED NOT NULL,
            `invoice_id` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
            `option_id` VARCHAR(80) NOT NULL DEFAULT \'default\',
            `payment_method` VARCHAR(64) NOT NULL DEFAULT \'\',
            `transaction_id` VARCHAR(191) NOT NULL DEFAULT \'\',
            `verified_state` INT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_payop_invoice`),
            UNIQUE KEY `payop_invoice_identifier` (`invoice_id`),
            KEY `payop_invoice_order` (`id_order`),
            KEY `payop_invoice_cart` (`id_cart`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
        if (!Db::getInstance()->execute($sql)) {
            return false;
        }
        // Preserve the current invoice of installations upgraded from 2.3.x.
        $this->invoiceHistoryReady = (bool) Db::getInstance()->execute('INSERT IGNORE INTO `' . pSQL($table) . '`
            (`id_order`, `id_cart`, `invoice_id`, `transaction_id`, `created_at`)
            SELECT `id_order`, `id_cart`, `invoice_id`, `transaction_id`, `created_at`
            FROM `' . pSQL(_DB_PREFIX_ . self::ORDER_META_TABLE) . '` WHERE `invoice_id` <> \'\'');
        return $this->invoiceHistoryReady;
    }

    public function getInvoiceContext($orderId, $invoiceId)
    {
        if (!$this->ensureInvoiceHistory() || !is_string($invoiceId) || $invoiceId === '') {
            return false;
        }
        $context = Db::getInstance()->getRow('SELECT * FROM `' . pSQL(_DB_PREFIX_ . 'payop_invoice_history') . '` WHERE `id_order` = ' . (int) $orderId . ' AND `invoice_id` = \'' . pSQL($invoiceId) . '\'');
        return $context && hash_equals((string) $context['invoice_id'], $invoiceId) ? $context : false;
    }

    public function rememberInvoice($orderId, $cartId, $invoiceId, array $button)
    {
        if (!$this->ensureInvoiceHistory() || strlen($invoiceId) > 191 || $invoiceId === '') {
            return false;
        }
        $db = Db::getInstance();
        if (!$db->insert('payop_invoice_history', [
            'id_order' => (int) $orderId, 'id_cart' => (int) $cartId,
            'invoice_id' => pSQL($invoiceId), 'option_id' => pSQL($button['id']),
            'payment_method' => pSQL($button['payment_method']), 'created_at' => date('Y-m-d H:i:s'),
        ])) {
            return false;
        }
        // Only invoice creation changes the current pointer. Late IPN never does.
        return $this->saveOrderMeta($orderId, $cartId, $invoiceId, '');
    }

    public function recordInvoiceTransaction($orderId, $invoiceId, $transactionId, $state)
    {
        $invoice = $this->getInvoiceContext($orderId, $invoiceId);
        if (!$invoice || (!empty($invoice['transaction_id']) && !hash_equals($invoice['transaction_id'], $transactionId))) {
            return false;
        }
        return (bool) Db::getInstance()->update('payop_invoice_history', [
            'transaction_id' => pSQL($transactionId),
            'verified_state' => (int) $invoice['verified_state'] === 2 ? 2 : (int) $state,
        ], '`id_order` = ' . (int) $orderId . ' AND `invoice_id` = \'' . pSQL($invoiceId) . '\'');
    }

    public function isOrderSettled(Order $order)
    {
        if ($order->hasBeenPaid()) {
            return true;
        }
        if (!$this->ensureInvoiceHistory()) {
            // Fail closed when durable payment history is unavailable.
            return true;
        }
        return (bool) Db::getInstance()->getValue('SELECT 1 FROM `' . pSQL(_DB_PREFIX_ . 'payop_invoice_history') . '` WHERE `id_order` = ' . (int) $order->id . ' AND `verified_state` = 2');
    }

    public function acquireCartLock($cartId)
    {
        return (int) Db::getInstance()->getValue("SELECT GET_LOCK('payop_" . pSQL(_DB_PREFIX_) . 'cart_' . (int) $cartId . "', 10)") === 1;
    }

    public function releaseCartLock($cartId)
    {
        Db::getInstance()->getValue("SELECT RELEASE_LOCK('payop_" . pSQL(_DB_PREFIX_) . 'cart_' . (int) $cartId . "')");
    }

    public function mayChangeInvoiceOrderState(Order $order, $invoiceId, $state)
    {
        if ($this->isOrderSettled($order)) {
            return false;
        }
        if ((int) $state === 2) {
            return true;
        }
        $current = $this->getOrderMetaByOrderId($order->id);
        return $current && hash_equals((string) $current['invoice_id'], (string) $invoiceId);
    }

    public function createInvoice(array $request)
    {
        $ch = curl_init('https://api.payop.com/v1/invoices/create');
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($request),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $response = is_string($body) ? json_decode($body, true) : [];
        $id = isset($headers['identifier']) ? $headers['identifier'] : '';
        if ($id === '' && isset($response['data']) && is_scalar($response['data'])) {
            $id = (string) $response['data'];
        } elseif ($id === '' && isset($response['data']['id']) && is_scalar($response['data']['id'])) {
            $id = (string) $response['data']['id'];
        }
        if ($status < 200 || $status >= 300 || $id === '' || strlen($id) > 191) {
            PrestaShopLogger::addLog('[Payop] Invoice creation failed. HTTP code: ' . $status);
            return ['ok' => false];
        }
        return ['ok' => true, 'invoice_id' => $id];
    }

    public function fetchInvoice($invoiceId)
    {
        $response = $this->requestInvoiceDetails($invoiceId);
        if (empty($response['ok']) || !isset($response['data']) || !is_array($response['data'])) {
            return ['ok' => false, 'error' => 'Invoice lookup failed'];
        }
        return ['ok' => true, 'data' => $response['data']];
    }

    protected function requestInvoiceDetails($invoiceId)
    {
        $ch = curl_init('https://api.payop.com/v1/invoices/' . rawurlencode($invoiceId));
        // The invoice endpoint does not require JWT. Never couple payment
        // confirmation to credentials used to discover the project catalogue.
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json']]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $response = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !isset($response['data']) || !is_array($response['data'])) {
            return ['ok' => false];
        }
        return ['ok' => true, 'data' => $response['data']];
    }

    public function verifyInvoiceForOrder(array $invoice, Order $order, $invoiceId, $transactionId, $expectedState)
    {
        if (empty($invoice['ok']) || !isset($invoice['data']) || !is_array($invoice['data'])) {
            return ['ok' => false, 'error' => 'Invoice lookup failed'];
        }
        $data = $invoice['data'];
        foreach (['identifier', 'orderIdentifier', 'amount', 'currency', 'status', 'transactionIdentifier'] as $field) {
            if (!isset($data[$field]) || !is_scalar($data[$field])) {
                return ['ok' => false, 'error' => 'Missing invoice verification field'];
            }
        }
        if (!hash_equals($invoiceId, (string) $data['identifier'])
            || (string) $data['orderIdentifier'] !== (string) $order->id
            || !hash_equals($transactionId, (string) $data['transactionIdentifier'])) {
            return ['ok' => false, 'error' => 'Invoice/order/transaction binding mismatch'];
        }
        if (!is_numeric($data['amount']) || !is_finite((float) $data['amount'])
            || number_format((float) $data['amount'], 4, '.', '') !== number_format((float) $order->total_paid, 4, '.', '')) {
            return ['ok' => false, 'error' => 'Amount mismatch'];
        }
        $currency = new Currency((int) $order->id_currency);
        if (strtoupper((string) $data['currency']) !== strtoupper((string) $currency->iso_code)) {
            return ['ok' => false, 'error' => 'Currency mismatch'];
        }
        $status = (int) $data['status'];
        if (!in_array($status, [0, 1, 2, 4, 5], true)) {
            return ['ok' => false, 'error' => 'Unsupported invoice status'];
        }
        // Invoice statuses and transaction states use different enumerations.
        $state = $status === 1 ? 2 : (($status === 2 || !empty($data['isOverdue'])) ? 15 : ($status === 5 ? 5 : 4));
        $expectedState = (int) $expectedState;
        $matches = ($expectedState === 2 && $state === 2)
            || (in_array($expectedState, [3, 5], true) && in_array($state, [5, 15], true))
            || ($expectedState === 15 && $state === 15)
            || (in_array($expectedState, [1, 4, 9], true) && $state === 4);
        if (!$matches) {
            return ['ok' => false, 'error' => 'Invoice state mismatch'];
        }
        return ['ok' => true, 'state' => $state, 'txid' => (string) $data['transactionIdentifier']];
    }
}
