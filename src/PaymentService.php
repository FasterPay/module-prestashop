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

require_once(dirname(__FILE__) . '/../fasterpay-php/lib/autoload.php');

use FasterPay\Gateway;
use FasterPay\Services\Signature;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaymentService
{
    private $gateway;

    public function getGateway()
    {
        if (!$this->gateway) {
            $this->gateway = new Gateway([
                'publicKey' => Configuration::get('FASTERPAY_PUBLIC_KEY'),
                'privateKey' => Configuration::get('FASTERPAY_PRIVATE_KEY'),
                'isTest' => Configuration::get('FASTERPAY_TEST_MODE'),
            ]);
        }

        return $this->gateway;
    }

    public function generateForm(Cart $cart, $total, Customer $customer, Module $module)
    {
        $context = Context::getContext();
        $currency = $context->currency;
        $paymentReturnParams = [
            'id_cart' => $cart->id,
            'id_module' => $module->id,
            'id_order' => $module->currentOrder,
            'key' => $customer->secure_key
        ];

        $successUrl = $context->link->getModuleLink($module->name, 'success', ['params' => json_encode($paymentReturnParams)], true);
        $pingbackUrl = $context->link->getModuleLink($module->name, 'pingback', [], true);
        $orderId = $module->currentOrder;

        return $this->getGateway()->paymentForm()->buildForm(
            [
                'description' => 'Order #' . $orderId,
                'amount' => $total,
                'currency' => $currency->iso_code,
                'merchant_order_id' => $orderId,
                'success_url' => $successUrl,
                'pingback_url' => $pingbackUrl,
                'sign_version' => Signature::SIGN_VERSION_2
            ],
            [
                'autoSubmit' => true,
                'hidePayButton' => true
            ]
        );
    }

    public function retrievePingbackData()
    {
        $signVersion = Signature::SIGN_VERSION_1;
        if (!empty($_SERVER['HTTP_X_FASTERPAY_SIGNATURE_VERSION'])) {
            $signVersion = $_SERVER['HTTP_X_FASTERPAY_SIGNATURE_VERSION'];
        }

        $pingbackData = null;
        $validationParams = [];

        switch ($signVersion) {
            case Signature::SIGN_VERSION_1:
                $validationParams = ["apiKey" => $_SERVER["HTTP_X_APIKEY"]];
                $pingbackData = $_REQUEST;
                break;
            case Signature::SIGN_VERSION_2:
                $validationParams = [
                    'pingbackData' => Tools::file_get_contents('php://input'),
                    'signVersion' => $signVersion,
                    'signature' => $_SERVER["HTTP_X_FASTERPAY_SIGNATURE"],
                ];
                $pingbackData = json_decode(Tools::file_get_contents('php://input'), 1);
                break;
            default:
                throw new \Exeption('NOK');
        }

        if (empty($pingbackData)) {
            throw new \Exception('Empty Pingback data!');
        }

        if (!$this->getGateway()->pingback()->validate($validationParams)) {
            throw new \Exception('Cannot pass Pingback validator!');
        }

        return $pingbackData;
    }

    public function getTransactionIdFromOrder(Order $order)
    {
        $payments = $order->getOrderPayments();
        foreach ($payments as $payment) {
            if (Tools::strtolower($payment->payment_method) == 'fasterpay') {
                return $payment->transaction_id;
            }
        }

        return false;
    }

    public function refund($paymentOrderId, $amount)
    {
        try {
            $gateway = $this->getGateway();
            $response = $gateway->paymentService()->refund($paymentOrderId, $amount);

            if (!$response->isSuccessful()) {
                throw new \Exception($response->getErrors()->getMessage());
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                '[FasterPayRefund] Refund failed' .
                ' FP Transaction #' . $paymentOrderId .
                ', amount: ' . $amount .
                ', ' . $e->getMessage(),
                3,
                null,
                null,
                null,
                true
            );
            return false;
        }

        return true;
    }
}
