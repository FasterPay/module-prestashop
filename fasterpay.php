<?php
/**
 * Plugin Name: FasterPay
 * Plugin URI: https://docs.fasterpay.com/integration/plugins/prestashop
 * Description: Accept payments from all over the world using FasterPay. FasterPay is a global E-Wallet which allows customers to pay using Debit / Credit card payments and Wallet balance.
 * Version: 1.0.0
 * @author The FasterPay Team
 * @copyright FasterPay
 * Author URI: http://www.fasterpay.com/
 * @license The MIT License (MIT)
 *
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class FasterPay extends PaymentModule
{
    const ORDER_PROCESSING = 3;

    public $checkName;
    public $address;

    private $paymentService;
    private $deliveryService;
    private $html = '';
    private $postErrors = array();

    public function __construct()
    {
        $this->name = 'fasterpay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'FasterPay Team';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7.1',
            'max' => _PS_VERSION_
        ];

        $config = Configuration::getMultiple(array('CHEQUE_NAME', 'CHEQUE_ADDRESS'));
        if (isset($config['CHEQUE_NAME'])) {
            $this->checkName = $config['CHEQUE_NAME'];
        }
        if (isset($config['CHEQUE_ADDRESS'])) {
            $this->address = $config['CHEQUE_ADDRESS'];
        }

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('FasterPay');
        $this->description = $this->l('Accept payments from all over the world using FasterPay. FasterPay is a global E-Wallet which allows customers to pay using Debit / Credit card payments and Wallet balance.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        Configuration::updateValue('FASTERPAY_PUBLIC_KEY', '');
        Configuration::updateValue('FASTERPAY_PRIVATE_KEY', '');
        Configuration::updateValue('FASTERPAY_TEST_MODE', 0);
        Configuration::updateValue('FASTERPAY_ORDER_STATUS', self::ORDER_PROCESSING);
        Configuration::updateValue('FASTERPAY_ORDER_AWAITING', (int)$this->createOrderStatus());

        if (!$this->addHooks()) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        Configuration::deleteByName('FASTERPAY_PUBLIC_KEY', $this->l('Public key'));
        Configuration::deleteByName('FASTERPAY_PRIVATE_KEY', $this->l('Private key'));
        Configuration::deleteByName('FASTERPAY_TEST_MODE', $this->l('Test mode'));
        Configuration::deleteByName('FASTERPAY_ORDER_STATUS', $this->l('Order status'));
        Configuration::deleteByName('FASTERPAY_ORDER_AWAITING', $this->l('Order awaiting'));
        $this->removeHooks();
        $this->removeOrderStatus();

        if (!parent::uninstall()) {
            return false;
        }

        return true;
    }

    public function hookDisplayBackOfficeHeader()
    {
        if (!self::isModuleTrusted($this->name)) {
            $this->makeModuleTrusted();
        }
    }

    protected function makeModuleTrusted()
    {
        if (version_compare(_PS_VERSION_, '1.6.0.7', '<')
            || !@filemtime(_PS_ROOT_DIR_.Module::CACHE_FILE_TRUSTED_MODULES_LIST)
            || !@filemtime(_PS_ROOT_DIR_.Module::CACHE_FILE_UNTRUSTED_MODULES_LIST)
            || !@filemtime(_PS_ROOT_DIR_.Module::CACHE_FILE_TAB_MODULES_LIST)
            || !class_exists('SimpleXMLElement')
        ) {
            return;
        }
        // Remove untrusted
        $untrustedXml = @simplexml_load_file(_PS_ROOT_DIR_.Module::CACHE_FILE_UNTRUSTED_MODULES_LIST);
        if (!is_object($untrustedXml)) {
            return;
        }
        $module = $untrustedXml->xpath('//module[@name="'.$this->name.'"]');
        if (empty($module)) {
            // Module list has not been refreshed, return
            return;
        }
        unset($module[0][0]);
        @$untrustedXml->saveXML(_PS_ROOT_DIR_.Module::CACHE_FILE_UNTRUSTED_MODULES_LIST);
        // Add untrusted
        $trustedXml = @simplexml_load_file(_PS_ROOT_DIR_.Module::CACHE_FILE_TRUSTED_MODULES_LIST);
        if (!is_object($trustedXml)) {
            return;
        }
        /** @var SimpleXMLElement $modules */
        @$modules = $trustedXml->xpath('//modules');
        if (!empty($modules)) {
            $modules = $modules[0];
        } else {
            return;
        }
        /** @var SimpleXMLElement $module */
        $module = $modules->addChild('module');
        $module->addAttribute('name', $this->name);
        @$trustedXml->saveXML(_PS_ROOT_DIR_.Module::CACHE_FILE_TRUSTED_MODULES_LIST);
        // Add to active payments list
        $modulesTabXml = @simplexml_load_file(_PS_ROOT_DIR_.Module::CACHE_FILE_TAB_MODULES_LIST);
        if (!is_object($modulesTabXml)) {
            return;
        }
        $moduleFound = $modulesTabXml->xpath('//tab[@class_name="AdminPayment"]/module[@name="'.$this->name.'"]');
        if (!empty($moduleFound)) {
            return;
        }
        // Find highest position
        /** @var array $modules */
        $modules = $modulesTabXml->xpath('//tab[@class_name="AdminPayment"]/module');
        $highestPosition = 0;
        foreach ($modules as $module) {
            /** @var SimpleXMLElement $module */
            foreach ($module->attributes() as $name => $attribute) {
                if ($name == 'position' && $attribute[0] > $highestPosition) {
                    $highestPosition = (int) $attribute[0];
                }
            }
        }
        $highestPosition++;
        /** @var SimpleXMLElement $modules */
        @$modules = $modulesTabXml->xpath('//tab[@class_name="AdminPayment"]');
        if (!empty($modules)) {
            $modules = $modules[0];
        } else {
            return;
        }

        $module = $modules->addChild('module');
        $module->addAttribute('name', $this->name);
        $module->addAttribute('position', $highestPosition);
        @$modulesTabXml->saveXML(_PS_ROOT_DIR_.Module::CACHE_FILE_TAB_MODULES_LIST);
    }

    public function hookActionAdminOrdersTrackingNumberUpdate($params)
    {
        if (empty($params['order'])) {
            return;
        }

        if (!empty($params['order']->module) && $params['order']->module != $this->name) {
            return;
        }

        $order = $params['order'];
        $customer = !empty($params['customer']) ? $params['customer'] : [] ;
        $carrier = !empty($params['carrier']) ? $params['carrier'] : [];

        $this->getDeliveryService()->updateDeliveryData(
            $order,
            DeliveryConfirmationService::STATUS_ORDER_SHIPPED,
            $carrier,
            $customer
        );
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active || empty($params['cart'])) {
            return;
        }

        if (!$this->checkCurrency($params['cart'])) {
            return;
        }

        $paymentOptions = [
            $this->getExternalPaymentOption()
        ];

        return $paymentOptions;
    }

    protected function getExternalPaymentOption()
    {
        $externalOption = new PaymentOption();
        $externalOption->setCallToActionText($this->l('Pay by FasterPay'))
            ->setAction($this->context->link->getModuleLink($this->name, 'form', [], true));

        return $externalOption;
    }

    protected function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getContent()
    {
        $this->html = '<h2><img src="' . $this->_path . 'logo.png" style="width: 34px; vertical-align: top;"> FASTERPAY</h2>';

        if (Tools::getValue('submitAddconfiguration')) {
            if (!Tools::getValue('FASTERPAY_PUBLIC_KEY')) {
                $this->postErrors[] = $this->l('Public key is required.');
            }
            if (!Tools::getValue('FASTERPAY_PRIVATE_KEY')) {
                $this->postErrors[] = $this->l('Private key is required.');
            }

            if (!sizeof($this->postErrors)) {
                Configuration::updateValue('FASTERPAY_PUBLIC_KEY', Tools::getValue('FASTERPAY_PUBLIC_KEY'));
                Configuration::updateValue('FASTERPAY_PRIVATE_KEY', Tools::getValue('FASTERPAY_PRIVATE_KEY'));
                Configuration::updateValue('FASTERPAY_TEST_MODE', Tools::getValue('FASTERPAY_TEST_MODE'));
                Configuration::updateValue('FASTERPAY_ORDER_STATUS', Tools::getValue('FASTERPAY_ORDER_STATUS'));
                $this->html .= $this->displayConfirmation($this->l('Settings updated'));
            } else {
                foreach ($this->postErrors as $error) {
                    $this->html .= $this->displayError($error);
                }
            }
        }

        $this->displayFormSettings();
        return $this->html;
    }

    protected function displayFormSettings()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings FasterPay'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Public key'),
                        'name' => 'FASTERPAY_PUBLIC_KEY',
                        'class' => 'fixed-width-xl'
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Private key'),
                        'name' => 'FASTERPAY_PRIVATE_KEY',
                        'class' => 'fixed-width-xl'
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Order status'),
                        'name' => 'FASTERPAY_ORDER_STATUS',
                        'options' => $this->getOrderStates()
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Test mode'),
                        'name' => 'FASTERPAY_TEST_MODE',
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        )
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Save')
                )
            )
        );

        $helper = new HelperForm();
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        ];
        $this->html .= $this->display(__FILE__, '/views/templates/admin/information.tpl');
        $this->html .= $helper->generateForm([$fields_form]);
    }

    protected function getConfigFieldsValues()
    {
        $shopGroupId = Shop::getContextShopGroupID();
        $shopId = Shop::getContextShopID();

        return array(
            'FASTERPAY_PUBLIC_KEY' => Tools::getValue('FASTERPAY_PUBLIC_KEY', Configuration::get('FASTERPAY_PUBLIC_KEY', null, $shopGroupId, $shopId)),
            'FASTERPAY_PRIVATE_KEY' => Tools::getValue('FASTERPAY_PRIVATE_KEY', Configuration::get('FASTERPAY_PRIVATE_KEY', null, $shopGroupId, $shopId)),
            'FASTERPAY_TEST_MODE' => Tools::getValue('FASTERPAY_TEST_MODE', Configuration::get('FASTERPAY_TEST_MODE', null, $shopGroupId, $shopId)),
            'FASTERPAY_ORDER_STATUS' => Tools::getValue('FASTERPAY_ORDER_STATUS', Configuration::get('FASTERPAY_ORDER_STATUS', null, $shopGroupId, $shopId)),
        );
    }

    public function hookActionOrderSlipAdd($params)
    {
        if (empty($params['order']) || empty($params['productList'])) {
            return;
        }

        if (!empty($params['order']->module) && $params['order']->module != $this->name) {
            return;
        }

        $order = $params['order'];
        $productList = $params['productList'];

        // parital refund handler
        $transactionId = $this->getPaymentService()->getTransactionIdFromOrder($order);
        if (!$transactionId) {
            PrestaShopLogger::addLog(
                'Order #' . $order->id . ': Missing Transaction ID',
                3,
                null,
                null,
                null,
                true
            );
        }

        $refundAmount = 0;
        foreach ($productList as $orderDetailId => $detail) {
            $refundAmount += $detail['amount'];
        }

        if (Tools::getValue('partialRefundShippingCost')) {
            $refundAmount += Tools::getValue('partialRefundShippingCost');
        }

        $this->getPaymentService()->refund($transactionId, $refundAmount);
    }

    public function hookActionOrderStatusUpdate(&$params)
    {
        if (empty($params['newOrderStatus']) || empty($params['id_order'])) {
            return;
        }

        $newOrderState = $params['newOrderStatus'];
        $orderId = $params['id_order'];
        
        if ($newOrderState->id == Configuration::get('PS_OS_REFUND')) {
            // full refund handler
            $order = new Order((int)$orderId);

            if (!empty($order->module) && $order->module != $this->name) {
                return;
            }

            $refundAmount = $order->total_paid;
            $transactionId = $this->getPaymentService()->getTransactionIdFromOrder($order);
            if (!$transactionId) {
                PrestaShopLogger::addLog(
                    'Order #' . $order->id . ': Missing Transaction ID',
                    3,
                    null,
                    null,
                    null,
                    true
                );
            }
            $this->getPaymentService()->refund($transactionId, $refundAmount);
        }
    }

    private function createOrderStatus()
    {
        $orderState = new OrderState();
        $orderState->name = array_fill(0, 10, "Awaiting FasterPay payment");
        $orderState->template = array_fill(0, 10, "fasterpay");
        $orderState->module_name = $this->name;
        $orderState->send_email = 0;
        $orderState->invoice = 1;
        $orderState->color = "#4169E1";
        $orderState->unremovable = false;
        $orderState->logable = 0;
        $orderState->add();
        return $orderState->id;
    }

    private function removeOrderStatus()
    {
        $orderState = new OrderState(Configuration::get('FASTERPAY_ORDER_AWAITING'));
        $orderState->delete();
    }

    private function getOrderStates()
    {
        $states = OrderState::getOrderStates($this->context->language->id);
        $orderStatus = array();

        if (!empty($states)) {
            foreach ($states as $state) {
                $orderStatus[] = array(
                    'id' => $state['id_order_state'],
                    'name' => $state['name'],
                );
            }
        }
        $options = array(
            'id' => 'id',
            'name' => 'name',
            'query' => $orderStatus,
        );
        return $options;
    }

    private function getDeliveryService()
    {
        if (!$this->deliveryService) {
            require_once(dirname(__FILE__) . '/src/DeliveryConfirmationService.php');
            $this->deliveryService = new DeliveryConfirmationService();
        }
        return $this->deliveryService;
    }

    private function getPaymentService()
    {
        if (!$this->paymentService) {
            require_once(dirname(__FILE__) . '/src/PaymentService.php');
            $this->paymentService = new PaymentService();
        }
        return $this->paymentService;
    }

    protected function removeHooks()
    {
        $this->unregisterHook('paymentOptions');
        $this->unregisterHook('actionAdminOrdersTrackingNumberUpdate');
        $this->unregisterHook('actionOrderSlipAdd');
        $this->unregisterHook('actionOrderStatusUpdate');
        $this->unregisterHook('displayBackOfficeHeader');
    }

    protected function addHooks()
    {
        if (!$this->registerHook('paymentOptions')
            || !$this->registerHook('actionAdminOrdersTrackingNumberUpdate')
            || !$this->registerHook('actionOrderSlipAdd')
            || !$this->registerHook('actionOrderStatusUpdate')
            || !$this->registerHook('displayBackOfficeHeader')
        ) {
            return false;
        }
    }
}
