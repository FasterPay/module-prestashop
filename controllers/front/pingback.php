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

class FasterPayPingbackModuleFrontController extends ModuleFrontController
{
    const OK_RESPONSE = 'OK';

    private $paymentService;
    private $deliveryService;

    public function postProcess()
    {
        try {
            $pingbackData = $this->getPaymentService()->retrievePingbackData();
            $orderId = $pingbackData['payment_order']['merchant_order_id'];
            $transactionId = $pingbackData['payment_order']['id'];

            $history = new OrderHistory();
            $history->id_order = $orderId;

            $order = new Order((int)$orderId);
            if ($order->current_state != Configuration::get('FASTERPAY_ORDER_STATUS')) {
                $history->changeIdOrderState(Configuration::get('FASTERPAY_ORDER_STATUS'), $orderId);
                $history->addWithemail(true, []);

                $payments = $order->getOrderPayments();
                $orderPayment = new OrderPayment($payments[0]->id);

                if ($orderPayment) {
                    $orderPayment->transaction_id = $transactionId;
                    $orderPayment->update();
                }

                $this->getDeliveryService()->updateDeliveryData(
                    $order,
                    $order->isVirtual() ? DeliveryConfirmationService::STATUS_DELIVERED : DeliveryConfirmationService::STATUS_ORDER_PLACED
                );
            }
            $response = self::OK_RESPONSE;
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                '[FasterPayPingback] Pingback failed' .
                (isset($orderId) ? ' Order #' . $orderId : '') .
                (isset($transactionId) ? ' FP Transaction #' . $orderId : '') .
                ', ' . $e->getMessage(),
                3,
                null,
                null,
                null,
                true
            );
            $response = 'NOK';
        }
        exit($response);
    }

    private function getPaymentService()
    {
        if (!$this->paymentService) {
            require_once(dirname(__FILE__) . '/../../src/PaymentService.php');
            $this->paymentService = new PaymentService();
        }
        return $this->paymentService;
    }

    private function getDeliveryService()
    {
        if (!$this->deliveryService) {
            require_once(dirname(__FILE__) . '/../../src/DeliveryConfirmationService.php');
            $this->deliveryService = new DeliveryConfirmationService();
        }
        return $this->deliveryService;
    }
}
