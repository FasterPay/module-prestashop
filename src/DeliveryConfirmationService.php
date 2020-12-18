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

if (!defined('_PS_VERSION_')) {
    exit;
}

class DeliveryConfirmationService
{
    const STATUS_DELIVERED = 'delivered';
    const STATUS_ORDER_SHIPPED = 'order_shipped';
    const STATUS_ORDER_PLACED = 'order_placed';
    const SUCCESS_CODE = '00';

    private $paymentService;

    public function updateDeliveryData(Order $order, $status, Carrier $carrier = null, Customer $customer = null)
    {
        try {
            $gateway = $this->getPaymentService()->getGateway();
            $payload = $this->prepareDeliveryData($order, $status, $carrier, $customer);

            $response = $gateway->callApi(
                'api/v1/deliveries',
                $payload,
                'POST',
                [
                    'content-type: application/json',
                    'x-apikey: ' . $gateway->getConfig()->getPrivateKey(),
                ]
            );
            $json = $response->getDecodeResponse();

            if (!is_array($json) || !isset($json['code']) || $json['code'] != self::SUCCESS_CODE) {
                PrestaShopLogger::addLog(
                    '[FasterPayDelivery]' .
                    ' Order #' . $order->id .
                    ', Request:' . json_encode($payload),
                    3,
                    null,
                    null,
                    null,
                    true
                );

                PrestaShopLogger::addLog(
                    '[FasterPayDelivery]' .
                    ' Order #' . $order->id .
                    ', Response:' . $response->getRawResponse(),
                    3,
                    null,
                    null,
                    null,
                    true
                );
            }
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                '[FasterPayDelivery]' .
                ' Order #' . $order->id .
                ', ' . $e->getMessage(),
                3,
                null,
                null,
                null,
                true
            );
        }
    }

    private function prepareDeliveryData(Order $order, $status, Carrier $carrier = null, Customer $customer = null)
    {
        $gateway = $this->getPaymentService()->getGateway();
        $transactionId = $this->getPaymentService()->getTransactionIdFromOrder($order);
        if (!$transactionId) {
            throw new \Exception('missing Transaction ID');
        }

        $invoiceAddress = new Address((int)$order->id_address_invoice);

        if (!$customer) {
            $customer = $order->getCustomer();
        }

        if (!$carrier) {
            $carrier = new Carrier((int)$order->id_carrier);
        }

        $deliveryAddress = new Address((int)$order->id_address_delivery);

        // country code finder
        $countryCode = $this->getCountryCodeFromAddresses(
            $deliveryAddress,
            $invoiceAddress
        );

        // city finder
        $city = $this->getDataFromAddressesAndCustomer(
            $deliveryAddress,
            $invoiceAddress,
            $customer,
            'city',
            'N/A'
        );

        // zip finder
        $zip = $this->getDataFromAddressesAndCustomer(
            $deliveryAddress,
            $invoiceAddress,
            $customer,
            'postcode',
            'N/A'
        );

        // state finder
        $state = $this->getStateNameFromAddresses(
            $deliveryAddress,
            $invoiceAddress
        );

        // street finder
        $street = $this->getDataFromAddressesAndCustomer(
            $deliveryAddress,
            $invoiceAddress,
            $customer,
            'address1',
            'N/A'
        );

        // phone finder
        $phone = $this->getPhoneFromAddresses(
            $deliveryAddress,
            $invoiceAddress
        );

        // first name finder
        $firsName = $this->getDataFromAddressesAndCustomer(
            $deliveryAddress,
            $invoiceAddress,
            $customer,
            'firstname',
            'N/A'
        );

        // last name finder
        $lastName = $this->getDataFromAddressesAndCustomer(
            $deliveryAddress,
            $invoiceAddress,
            $customer,
            'lastname',
            'N/A'
        );

        // email finder
        $email = !empty($customer->email) ? $customer->email : 'N/A';

        return [
            "payment_order_id" => $transactionId,
            "merchant_reference_id" => (string)$order->id,
            "status" => $status,
            "refundable" => true,
            "details" => 'prestashop delivery action',
            "reason" => 'None',
            "estimated_delivery_datetime" => date('Y-m-d H:i:s O'),
            "carrier_tracking_id" => ($trackingNumber = $order->getWsShippingNumber()) ? $trackingNumber : "N/A",
            "carrier_type" => ($trackingNumber && !empty($carrier->name)) ? $carrier->name : "N/A",
            "shipping_address" => [
                "country_code" => $countryCode,
                "city" => $city,
                "zip" => $zip,
                "state" => $state,
                "street" => $street,
                "phone" => $phone,
                "first_name" => $firsName,
                "last_name" => $lastName,
                "email" => $email
            ],
            'attachments' => ['N/A'],
            "type" => !$order->isVirtual() ? "physical" : "digital",
            "public_key" => $gateway->getConfig()->getPublicKey(),
        ];
    }

    private function getPaymentService()
    {
        if (!$this->paymentService) {
            require_once(dirname(__FILE__) . '/PaymentService.php');
            $this->paymentService = new PaymentService();
        }
        return $this->paymentService;
    }

    private function getCountryCodeFromAddresses(Address $invoiceAddress, Address $deliveryAddress)
    {
        $countryId = $this->getDataFromMultiSources(
            [
                [
                    'object' => $invoiceAddress,
                    'property' => 'id_country'
                ],
                [
                    'object' => $deliveryAddress,
                    'property' => 'id_country'
                ]
            ],
            0
        );

        return $this->getDataFromMultiSources(
            [
                [
                    'object' => new Country((int)$countryId),
                    'property' => 'iso_code'
                ]
            ],
            'N/A'
        );
    }

    private function getStateNameFromAddresses(Address $invoiceAddress, Address $deliveryAddress)
    {
        $stateId = $this->getDataFromMultiSources(
            [
                [
                    'object' => $invoiceAddress,
                    'property' => 'id_state'
                ],
                [
                    'object' => $deliveryAddress,
                    'property' => 'id_state'
                ]
            ],
            0
        );

        return $this->getDataFromMultiSources(
            [
                [
                    'object' => new State((int)$stateId),
                    'property' => 'name'
                ]
            ],
            'N/A'
        );
    }

    private function getPhoneFromAddresses(Address $invoiceAddress, Address $deliveryAddress)
    {
        return $this->getDataFromMultiSources(
            [
                [
                    'object' => $invoiceAddress,
                    'property' => 'phone'
                ],
                [
                    'object' => $deliveryAddress,
                    'property' => 'phone'
                ],
                [
                    'object' => $invoiceAddress,
                    'property' => 'phone_mobile'
                ],
                [
                    'object' => $deliveryAddress,
                    'property' => 'phone_mobile'
                ]
            ],
            'N/A'
        );
    }

    private function getDataFromAddressesAndCustomer(
        Address $invoiceAddress,
        Address $deliveryAddress,
        Customer $customer,
        $field = null,
        $defaultValue = 'N/A'
    ) {
        return $this->getDataFromMultiSources(
            [
                [
                    'object' => $invoiceAddress,
                    'property' => $field
                ],
                [
                    'object' => $deliveryAddress,
                    'property' => $field
                ],
                [
                    'object' => $customer,
                    'property' => $field
                ]
            ],
            $defaultValue
        );
    }

    private function getDataFromMultiSources($map, $defaultValue)
    {
        foreach ($map as $row) {
            if (!empty($row['object'])
                && !empty($row['property'])
                && is_object($row['object'])
                && !empty($row['object']->{$row['property']})
            ) {
                return $row['object']->{$row['property']};
            }
        }

        return $defaultValue;
    }
}
