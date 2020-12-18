<?php
/**
 *
 * Fasterpay for Prestashop
 *
 * Description: Official Fasterpay module for Prestashop.
 * Plugin URI: https://docs.fasterpay.com/integration/plugins/prestashop
 * @author The FasterPay Team
 * @copyright FasterPay
 * @license The MIT License (MIT)
 *
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FasterPaySuccessModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        if (!empty(Tools::getValue('params'))) {
            Tools::redirect('index.php?controller=order-confirmation&' . http_build_query(json_decode(Tools::getValue('params'))));
        }

        die;
    }
}
