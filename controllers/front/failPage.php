<?php

class PayopFailPageModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->setTemplate('module:payop/views/templates/front/failPage.tpl');
    }
}
