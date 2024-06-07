<?php

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}


class Payop extends PaymentModule
{
    private $_html = '';
    private $_postErrors = array();

    /**
     * @var
     */
    public $address;

    /**
     * Payop constructor.
     *
     * Set the information about this module
     */
    public function __construct()
    {
        $this->name                   = 'payop';
        $this->tab                    = 'payments_gateways';
        $this->version                = '1.0.1';
        $this->author                 = 'PAYOP';
        $this->controllers            = array('payment', 'validation', 'failPage', 'callback');
        $this->currencies             = true;
        $this->currencies_mode        = 'checkbox';
        $this->bootstrap              = true;
        $this->displayName            = 'PayOp';
        $this->description            = 'Make payments via PayOp';
        $this->confirmUninstall       = 'Are you sure you want to uninstall this module?';
        $this->ps_versions_compliancy = array('min' => '1.7.0', 'max' => _PS_VERSION_);

        parent::__construct();
    }

    /**
     * Install this module and register the following Hooks:
     *
     * @return bool
     */
    public function install()
    {
        $this->installOrderState();
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn');
    }

    /**
     * Uninstall this module and remove it from all hooks
     *
     * @return bool
     */
    public function uninstall()
    {
        return parent::uninstall();
    }

    /**
     * Handle the submission of the module
     * configuration form
     */
    public function processConfiguration()
    {
        if (Tools::isSubmit('pc_form')) {
            $directMethods = $this->getPaymentsMethods() ? $this->getPaymentsMethods() : array();
            $enablePayments = Tools::getValue('enablePayments');
            $displayName = Tools::getValue('displayName');
            $description = Tools::getValue('description');
            $publicKey = Tools::getValue('publicKey');
            $secretKey = Tools::getValue('secretKey');
            $jwtToken = Tools::getValue('jwtToken');
            $directPay = Tools::getValue('directPay');
            $directPayId = array_search($directPay, $directMethods) ? array_search($directPay, $directMethods) : '';
            $language = Tools::getValue('language');
            Configuration::updateValue('PAYOP_ENABLE', $enablePayments);
            Configuration::updateValue('PAYOP_NAME', $displayName);
            Configuration::updateValue('DESCRIPTION', $description);
            Configuration::updateValue('PAYOP_PUBLIC_KEY', $publicKey);
            Configuration::updateValue('PAYOP_SECRET_KEY', $secretKey);
            Configuration::updateValue('JWT_TOKEN', $jwtToken);
            Configuration::updateValue('DIRECTPAY', $directPay);
            Configuration::updateValue('DIRECTPAY_ID', $directPayId);
            Configuration::updateValue('PAYOP_LANGUAGE', $language);
            $this->context->smarty->assign('directMethods', $directMethods);
            $this->context->smarty->assign('confirmation', 'ok');
        }
    }

    /**
     * Assign configuration to smarty
     *
     */
    public function assignConfiguration()
    {
        $enablePayments = Configuration::get('PAYOP_ENABLE');
        $displayName = Configuration::get('PAYOP_NAME');
        $description = Configuration::get('DESCRIPTION');
        $publicKey = Configuration::get('PAYOP_PUBLIC_KEY');
        $secretKey = Configuration::get('PAYOP_SECRET_KEY');
        $language = Configuration::get('PAYOP_LANGUAGE');
        $jwtToken = Configuration::get('JWT_TOKEN');
        $directPay = Configuration::get('DIRECTPAY');
        $directMethods = $this->getPaymentsMethods();
        $this->context->smarty->assign('enablePayments', $enablePayments);
        $this->context->smarty->assign('displayName', $displayName);
        $this->context->smarty->assign('description', $description);
        $this->context->smarty->assign('publicKey', $publicKey);
        $this->context->smarty->assign('secretKey', $secretKey);
        $this->context->smarty->assign('jwtToken', $jwtToken);
        $this->context->smarty->assign('language', $language);
        $this->context->smarty->assign('directMethods', $directMethods);
        $this->context->smarty->assign('directPay', $directPay);
    }

    /**
     * Returns a string containing the HTML necessary to
     * generate a configuration screen on the admin
     *
     * @return string
     */
    public function getContent()
    {
        $this->processConfiguration();
        $this->assignConfiguration();
        return $this->display(__FILE__, 'getContent.tpl');
    }

    private function getPaymentsMethods()
    {
        $public_key = Configuration::get('PAYOP_PUBLIC_KEY');
        $requestUrl = 'https://api.payop.com/v1/instrument-settings/payment-methods/available-for-application/'. str_replace('application-', '', $public_key);
        $jwtToken = "Authorization: Bearer ".Configuration::get('JWT_TOKEN');
        PrestaShopLogger::addlog($jwtToken);
        $ch = curl_init($requestUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $jwtToken ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);
        PrestaShopLogger::addlog("".$response);
        $response = json_decode($response, true);
        if (!isset($response['data'])) {
            return;
        }
        if ($response['data']) {
            foreach ($response['data'] as $item) {
                $methodOptions[$item['identifier']] = $item['title'];
            }
        } else {
            return;
        }
        return $methodOptions;
    }

    /**
     * Adds PayOp to payment options
     *
     * @return array|void
     */
    public function hookPaymentOptions()
    {
        if (!$this->active) {
            return;
        }

        if (!(Configuration::get('PAYOP_ENABLE')  === "1")) {
            return;
        }

        /**
         * Form action URL. The form data will be sent to the
         * validation controller when the user finishes
         * the order process.
         */
        $formAction = $this->context->link->getModuleLink($this->name, 'validation', array(), true);

        /**
         * Assign the url form action to the template var $action
         */
        $this->smarty->assign(['action' => $formAction]);

        /**
         *  Load form template to be displayed in the checkout step
         */
        $paymentForm = $this->fetch('module:payop/views/templates/hook/payment_options.tpl');

        /**
         * Create a PaymentOption object containing the necessary data
         * to display this module in the checkout
         */
        $newOption = new PaymentOption();
        $newOption->setModuleName($this->displayName)
          ->setCallToActionText(Configuration::get('PAYOP_NAME'))
          ->setAction($formAction)
          ->setForm($paymentForm);

        $payment_options = array(
          $newOption
        );

        return $payment_options;
    }

    /**
     * Add pending payment order state
     *
     * @return bool
     * @throws \PrestaShopDatabaseException
     * @throws \PrestaShopException
     */
    public function installOrderState()
    {
        if (Configuration::get('PS_OS_PAYOP_PENDING_STATE') < 1) {
            $order_state = new OrderState();
            $order_state->send_email = false;
            $order_state->module_name = $this->name;
            $order_state->invoice = true;
            $order_state->color = '#D3D3D3';
            $order_state->logable = true;
            $order_state->shipped = false;
            $order_state->unremovable = false;
            $order_state->delivery = false;
            $order_state->hidden = false;
            $order_state->paid = false;
            $order_state->deleted = false;
            $order_state->name = array((int) Configuration::get('PS_LANG_DEFAULT') =>
                pSQL($this->l('Pending Payment')));
            if ($order_state->add()) {
                Configuration::updateValue('PS_OS_PAYOP_PENDING_STATE', $order_state->id);
                copy(dirname(__FILE__) . 'logo.png', dirname(__FILE__)
                  . '/../../img/os/' . $order_state->id . '.png');
                copy(dirname(__FILE__) . 'logo.png', dirname(__FILE__)
                  . '/../../img/tmp/order_state_mini_' . $order_state->id
                  . '.png');
            } else {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Display a message in the paymentReturn hook
     *
     * @param array $params
     * @return string
     */
    public function hookPaymentReturn($params)
    {
        /**
         * Verify if this module is enabled
         */
        if (!$this->active) {
            return;
        }

        return $this->fetch('module:payop/views/templates/hook/payment_return.tpl');
    }
}
