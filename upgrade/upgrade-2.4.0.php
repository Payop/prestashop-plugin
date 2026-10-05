<?php

function upgrade_module_2_4_0($module)
{
    return $module->ensureInvoiceHistory()
        && $module->registerHook('actionFrontControllerInitBefore');
}
