<?php

class PayopFailPageModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->context->smarty->assign('payop_retry_options', []);
        $orderId = (int) Tools::getValue('id_order');
        $cartId = (int) Tools::getValue('id_cart');
        $key = Tools::getValue('key');
        $signature = Tools::getValue('signature');
        $order = new Order($orderId);
        if (Validate::isLoadedObject($order) && $order->module === $this->module->name
            && (int) $order->id_cart === $cartId && is_string($key) && is_string($signature)
            && hash_equals((string) $order->secure_key, $key)
            && hash_equals($this->module->generateFailSignature($orderId, $cartId, $key), $signature)
            && (int) $order->id_customer === (int) $this->context->customer->id) {
            if ($this->module->isOrderSettled($order)) {
                Tools::redirect($this->module->getSuccessUrl($orderId, $cartId, $key));
            }
            if ($this->module->active && (bool) Configuration::get('PAYOP_ENABLE')) {
                $options = $this->module->getCheckoutButtons();
                foreach ($options as &$option) {
                    $option['action'] = $this->module->getFrontControllerUrl('validation', [
                        'id_order' => $orderId, 'key' => $key, 'signature' => $signature,
                        'payop_option' => $option['id'],
                    ]);
                }
                unset($option);
                $this->context->smarty->assign('payop_retry_options', $options);
            }
        }
        // Browser returns never change payment state; verified IPN is authoritative.
        $this->setTemplate('module:payop/views/templates/front/failPage.tpl');
    }
}
