<?php

class PayopValidationModuleFrontController extends ModuleFrontController
{

    /**
     * Process the order
     *
     * @throws \Exception
     */
    public function postProcess()
    {
        /**
         * Get current cart object from session
         */
        $cart = $this->context->cart;
        $authorized = false;

        /**
         * Verify if this module is enabled and if the cart has
         * a valid customer, delivery address and invoice address
         */
        if (!$this->module->active || $cart->id_customer == 0 || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        /**
         * Verify if this payment module is authorized
         */
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'payop') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this->l('This payment method is not available.'));
        }

        /** @var CustomerCore $customer */
        $customer = new Customer($cart->id_customer);

        /**
         * Check if this is a valid customer account
         */
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        /**
         * Place the order
         */
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

        try {
            $order = new Order($cart->id);
            $currency = Currency::getCurrency($order->id_currency);
            $order_products = $order->getProducts();
            $payop_order_items = array();
            foreach ($order_products as $product) {
                $item = array(
                    'id' => $product['product_id'],
                    'name' => $product['product_name'],
                    'price' => $product['unit_price_tax_incl']
                );
                array_push($payop_order_items, $item);
            }
            $language = Configuration::get('PAYOP_LANGUAGE');
            $address = new Address($cart->id_address_delivery);

            $request = array();
            $request['publicKey'] = Configuration::get('PAYOP_PUBLIC_KEY');
            $request['order']['id'] = strval($this->module->currentOrder);
            $request['order']['amount'] = $order->total_paid;
            $request['order']['currency'] = $currency['iso_code'];
            $request['order']['description'] = $this->l('Payment order #').$this->module->currentOrder;
            $request['order']['items'] = $payop_order_items;
            $request['payer']['email'] = $customer->email;
            $request['payer']['name'] = $customer->firstname.' '.$customer->lastname;
            $request['payer']['phone'] = $address->phone;
            if (!empty(Configuration::get('DIRECTPAY_ID'))) {
                $request['paymentMethod'] = Configuration::get('DIRECTPAY_ID');
            }
            $request['resultUrl'] = _PS_BASE_URL_.__PS_BASE_URI__.$language.
              "/order-confirmation?id_cart=".(int)$cart->id."&id_module=".
              (int)$this->module->id."&id_order=".$this->module->currentOrder."&key=".$customer->secure_key;
            $request['failPath'] = _PS_BASE_URL_.__PS_BASE_URI__."index.php?fc=module&module=payop&controller=failPage";
            $request['signature'] = $this->generateSignature(
                strval($this->module->currentOrder),
                $order->total_paid,
                $currency['iso_code'],
                Configuration::get('PAYOP_SECRET_KEY')
            );
            $request['language'] = $language;

            $url = 'https://payop.com/v1/invoices/create';
            $request = json_encode($request);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $result = curl_exec($ch);
            PrestaShopLogger::addLog("".$result);
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($result, 0, $header_size);
            PrestaShopLogger::addLog($headers);
            $headers = explode("\r\n", $headers);
            $invoice_identifier = preg_grep("/^identifier/", $headers);
            $invoice_identifier = implode(',', $invoice_identifier);
            $invoice_identifier = substr($invoice_identifier, strrpos($invoice_identifier, ':')+2);
            curl_close($ch);
            Tools::redirect('https://payop.com/'.$language.'/payment/invoice-preprocessing/'.
              $invoice_identifier);
        } catch (PrestaShopDatabaseException $e) {
            PrestaShopLogger::addLog($e);
        } catch (PrestaShopException $e) {
            PrestaShopLogger::addLog($e);
        }
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
