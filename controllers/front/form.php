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

class FasterPayFormModuleFrontController extends ModuleFrontController
{
    private $paymentService;

    public function postProcess()
    {
        $cart = $this->context->cart;

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'fasterpay') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            die($this->trans('This payment method is not available.', [], 'Modules.Fasterpay.Shop'));
        }

        $customer = new Customer($cart->id_customer);

        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $currency = $this->context->currency;
        $total = (float)$cart->getOrderTotal(true, Cart::BOTH);

        $mailVars = [
            '{check_name}' => Configuration::get('CHEQUE_NAME'),
            '{check_address}' => Configuration::get('CHEQUE_ADDRESS'),
            '{check_address_html}' => str_replace("\n", '<br />', Configuration::get('CHEQUE_ADDRESS'))
        ];

        $this->module->validateOrder(
            (int)$cart->id,
            Configuration::get('FASTERPAY_ORDER_AWAITING'),
            $total,
            $this->module->displayName,
            null,
            $mailVars,
            (int)$currency->id,
            false,
            $customer->secure_key
        );

        $this->context->smarty->assign(
            'form',
            $this->getPaymentService()->generateForm(
                $cart,
                $total,
                $customer,
                $this->module
            )
        );

        $this->setTemplate('module:fasterpay/views/templates/front/form.tpl');
    }

    private function getPaymentService()
    {
        if (!$this->paymentService) {
            require_once(dirname(__FILE__) . '/../../src/PaymentService.php');
            $this->paymentService = new PaymentService();
        }
        return $this->paymentService;
    }
}
