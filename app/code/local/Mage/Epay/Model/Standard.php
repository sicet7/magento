<?php
/**
 * Copyright (c) 2017. All rights reserved ePay A/S (a Bambora Company).
 *
 * This program is free software. You are allowed to use the software but NOT allowed to modify the software.
 * It is also not legal to do any changes to the software and distribute it in your own name / brand.
 *
 * All use of the payment modules happens at your own risk. We offer a free test account that you can use to test the module.
 *
 * @author    ePay A/S (a Bambora Company)
 * @copyright Bambora (http://bambora.com) (http://www.epay.dk)
 * @license   ePay A/S (a Bambora Company)
 *
 */
class Mage_Epay_Model_Standard extends Mage_Payment_Model_Method_Abstract
{
    const METHOD_CODE = 'epay_standard';
    const PSP_REFERENCE = 'epayReference';

    protected $_code = self::METHOD_CODE;
    protected $_formBlockType = 'epay/standard_form';
    protected $_infoBlockType = 'epay/standard_info';

    protected $_isGateway                = true;
    protected $_canCapture                = true;
    protected $_canCapturePartial        = true;
    protected $_canRefund                = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canOrder                = true;
    protected $_canVoid                 = true;


    //
    // Allowed currency types
    //
    protected $_allowCurrencyCode = array(
        'ADP','AED','AFA','ALL','AMD','ANG','AOA','ARS','AUD','AWG','AZM','BAM','BBD','BDT','BGL','BGN','BHD','BIF','BMD','BND','BOB',
        'BOV','BRL','BSD','BTN','BWP','BYR','BZD','CAD','CDF','CHF','CLF','CLP','CNY','COP','CRC','CUP','CVE','CYP','CZK','DJF','DKK',
        'DOP','DZD','ECS','ECV','EEK','EGP','ERN','ETB','EUR','FJD','FKP','GBP','GEL','GHC','GIP','GMD','GNF','GTQ','GWP','GYD','HKD',
        'HNL','HRK','HTG','HUF','IDR','ILS','INR','IQD','IRR','ISK','JMD','JOD','JPY','KES','KGS','KHR','KMF','KPW','KRW','KWD','KYD',
        'KZT','LAK','LBP','LKR','LRD','LSL','LTL','LVL','LYD','MAD','MDL','MGF','MKD','MMK','MNT','MOP','MRO','MTL','MUR','MVR','MWK',
        'MXN','MXV','MYR','MZM','NAD','NGN','NIO','NOK','NPR','NZD','OMR','PAB','PEN','PGK','PHP','PKR','PLN','PYG','QAR','ROL','RUB',
        'RUR','RWF','SAR','SBD','SCR','SDD','SEK','SGD','SHP','SIT','SKK','SLL','SOS','SRG','STD','SVC','SYP','SZL','THB','TJS','TMM',
        'TND','TOP','TPE','TRL','TRY','TTD','TWD','TZS','UAH','UGX','USD','UYU','UZS','VEB','VND','VUV','XAF','XCD','XOF','XPF','YER',
        'YUM','ZAR','ZMK','ZWD'
    );

    /**
     * @var Mage_Epay_Helper_Data
     */
    private $epayHelper;

    // Default constructor
    //
    public function __construct()
    {
        $this->epayHelper = Mage::helper('epay');
    }

    public function validate()
    {
        parent::validate();

        $currencyCode = $this->getQuote()->getBaseCurrencyCode();
        if (isset($currencyCode)) {
            if (!in_array($currencyCode, $this->_allowCurrencyCode)) {
                Mage::throwException(sprintf($this->epayHelper->__("Selected currency code (%s) is not compatabile with ePay"), $currencyCode));
            }
        }

        return $this;
    }

    /**
     * Get the decrypted remote password
     *
     * @param Mage_Sales_Model_Order $order
     * @return string
     */
    public function getRemotePassword($storeId)
    {
        $passwordEnc = $this->getConfigData('remoteinterfacepassword', $storeId);
        return Mage::helper('core')->decrypt($passwordEnc);
    }

    /**
     * Turn the payment request array into a string
     * @param mixed $paymentRequest
     * @return string
     */
    public function getPaymentRequestAsString($paymentRequest)
    {
        $keyValueArray = array();
        foreach ($paymentRequest as $key => $value) {
            $keyValueArray[] = "'" . $key . "':'" . $value . "'";
        }

        return implode(",\n", $keyValueArray);
    }

    /**
     * Generate a payment request based on the data from the order
     * @param mixed $order
     * @return array
     */
    public function getPaymentRequest($order)
    {
        $storeId = $order->getStoreId();
        $currencyCode = $order->getBaseCurrencyCode();
        $minorunits = $this->epayHelper->getCurrencyMinorunits($currencyCode);
        $amount = $order->getBaseTotalDue();

        $paymentRequest = array(
                           'encoding' => "UTF-8",
                           'cms' => $this->getCmsInfo(),
                           'windowstate' => "3",
                           'mobile' => $this->getConfigData('enablemobilepaymentwindow', $storeId),
                           'merchantnumber' => $this->getConfigData('merchantnumber', $storeId),
                           'windowid' => $this->getConfigData('windowid', $storeId),
                           'amount' => $this->epayHelper->convertPriceToMinorunits($amount, $minorunits, $this->getConfigData('roundingmode', $storeId)),
                           'currency' => $currencyCode,
                           'orderid' => $order->getIncrementId(),
                           'accepturl' => $this->getAcceptUrl(),
                           'cancelurl' => $this->getCancelUrl(),
                           'callbackurl' => $this->getCallbackUrl(),
                           'mailreceipt' => $this->getConfigData('authmail', $storeId),
                           'instantcapture' => $this->getConfigData('instantcapture', $storeId),
                           'language' => $this->calcLanguage(),
                           'ownreceipt' => $this->getConfigData('ownreceipt', $storeId),
                           'timeout' => "60",
						   'splitpayment' => intval($this->getConfigData('splitpayment', $storeId))
                           );

        if (intval($this->getConfigData('enableinvoicedata', $storeId)) == 1) {
            $paymentRequest['invoice'] = $this->createInvoice($order);
        }

        $md5key = $this->getConfigData('md5key');

        $paymentRequest['hash']  = $this->generateMD5Key($paymentRequest, $md5key);

        return $paymentRequest;
    }

    /**
     * Summary of createInvoice
     *
     * @param Mage_Sales_Model_Order $order
     * @return string
     */
    public function createInvoice($order)
    {
        $billingData = $order->getBillingAddress();
        $shippingData = $order->getShippingAddress();

        if($billingData) {
            $invoice["customer"]["emailaddress"] = $order->getCustomerEmail();
            $invoice["customer"]["firstname"] = $this->removeSpecialCharacters($billingData->getFirstname());
            $invoice["customer"]["lastname"] = $this->removeSpecialCharacters($billingData->getLastname());
            $invoice["customer"]["address"] = $this->removeSpecialCharacters($billingData->getStreetFull());
            $invoice["customer"]["zip"] = $this->removeSpecialCharacters($billingData->getPostcode());
            $invoice["customer"]["city"] = $this->removeSpecialCharacters($billingData->getCity());
            $invoice["customer"]["country"] = $this->removeSpecialCharacters($billingData->getCountryId());
        }

        if($shippingData) {
            $invoice["shippingaddress"]["firstname"] = $this->removeSpecialCharacters($shippingData->getFirstname());
            $invoice["shippingaddress"]["lastname"] = $this->removeSpecialCharacters($shippingData->getLastname());
            $invoice["shippingaddress"]["address"] = $this->removeSpecialCharacters($shippingData->getStreetFull());
            $invoice["shippingaddress"]["zip"] = $this->removeSpecialCharacters($shippingData->getPostcode());
            $invoice["shippingaddress"]["city"] = $this->removeSpecialCharacters($shippingData->getCity());
            $invoice["shippingaddress"]["country"] = $this->removeSpecialCharacters($shippingData->getCountryId());
        }

        $currencyCode = $order->getBaseCurrencyCode();
        $minorunits = $this->epayHelper->getCurrencyMinorunits($currencyCode);
        $storeId = $order->getStoreId();
        $roundingMode = $this->getConfigData('roundingmode', $storeId);
        $invoice["lines"] = array();
        $items = $order->getAllVisibleItems();
        foreach ($items as $item) {
            $description = empty($item->getDescription()) ? $item->getName() : $item->getDescription();
            $invoice["lines"][] = array(
                    "id" =>$item->getSku(),
                    "description" => $this->removeSpecialCharacters($description),
                    "quantity" => intval($item->getQtyOrdered()),
                    "price" => $this->epayHelper->convertPriceToMinorunits($item->getBasePrice(), $minorunits, $roundingMode),
                    "vat" => floatval($item->getTaxPercent())
                );
        }
        // add shipment as line
        $shippingText = __("Shipping");
        $shippingDescription = $order->getShippingDescription();
        $shippingTaxClass = Mage::getStoreConfig('tax/classes/shipping_tax_class');
        $shippingTaxPercent = $this->getTaxRate($order, $shippingTaxClass);
        $invoice["lines"][] = array(
                   "id" => $shippingText,
                   "description" => isset($shippingDescription) ? $shippingDescription : $shippingText,
                   "quantity" => 1,
                   "price" => $this->epayHelper->convertPriceToMinorunits($order->getBaseShippingAmount(), $minorunits, $roundingMode),
                   "vat" => $shippingTaxPercent
               );

        $baseDiscountAmount = $order->getBaseDiscountAmount();
        if($baseDiscountAmount != 0) {
            $discountDescription = $order->getDiscountDescription();
            $invoice["lines"][] = array(
                "id" => "discount",
                "description" => isset($discountDescription) ? __("Discount") . " ". $discountDescription : __("Discount"),
                 "quantity" => 1,
                 "price" => $this->epayHelper->convertPriceToMinorunits($baseDiscountAmount, $minorunits, $roundingMode),
                  );
        }

        return json_encode($invoice, JSON_UNESCAPED_UNICODE);
    }

    public function getTaxRate($order, $taxClass)
    {
        // Load the customer so we can retrevice the correct tax class id
        $customer = Mage::getModel('customer/customer')
            ->load($order->getCustomerId());
        $calculation = Mage::getSingleton('tax/calculation');
        $request = $calculation->getRateRequest(
            $order->getShippingAddress(),
            $order->getBillingAddress(),
            $customer->getTaxClassId(),
            $order->getStore()
        );
        return $calculation->getRate($request->setProductClassId($taxClass));
    }

    private function canAction($actionOrder)
    {
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $row = $read->fetchRow("select * from epay_order_status where orderid = '" . $actionOrder->getIncrementId() . "'");
        if ($row["status"] == '1') {
            return true;
        }

        return false;
    }

    private function canOnlineAction($payment)
    {
        if (intval($this->getConfigData('remoteinterface', $payment->getOrder() ? $payment->getOrder()->getStoreId() : null)) === 1) {
            return true;
        }

        return false;
    }

    public function canCapture()
    {
        $captureOrder = $this->_data["info_instance"]->getOrder();

        if ($this->_canCapture && $this->canAction($captureOrder)) {
            return true;
        }

        return false;
    }

    public function capture(Varien_Object $payment, $amount)
    {
        try {
            $errorMessageBase = $this->epayHelper->__("The payment could not be captured by ePay:").' ';

            $read = Mage::getSingleton('core/resource')->getConnection('core_read');
            $row = $read->fetchRow("select * from epay_order_status where orderid = '" . $payment->getOrder()->getIncrementId() . "'");
            if ($row["status"] == '1') {


                $tid = $row["tid"];

                $isInstantCapure = $payment->getAdditionalInformation('instantcapture');
                if ($isInstantCapure === true) {
                    $payment->setTransactionId($tid . '-instantcapture')
                        ->setIsTransactionClosed(true)
                        ->setParentTransactionId($tid);
                    return $this;
                }

                if (!$this->canOnlineAction($payment)) {
                    throw new Exception($this->epayHelper->__("The capture action could not, be processed online. Please enable remote payment processing from the module configuration"));
                }

                /** @var Mage_Sales_Model_Order */
                $order = $payment->getOrder();
                $currency = $order->getBaseCurrencyCode();
                $minorunits = $this->epayHelper->getCurrencyMinorunits($currency);
                $storeId = $order->getStoreId();

                $amountInMinorunits = $this->epayHelper->convertPriceToMinorunits($amount, $minorunits, $this->getConfigData('roundingmode', $storeId));
                $param = array(
                    'merchantnumber' => $this->getConfigData('merchantnumber', $storeId),
                    'transactionid' => $tid,
                    'amount' => (string)$amountInMinorunits,
                    'group' => '',
                    'pbsResponse' => 0,
                    'epayresponse' => 0,
                    'pwd' => $this->getRemotePassword($storeId)
                );

                $client = new SoapClient('https://ssl.ditonlinebetalingssystem.dk/remote/payment.asmx?WSDL');
                $result = $client->capture($param);

                if ($result->captureResult === true) {
                    $payment->setTransactionId($tid .'-'. Mage_Sales_Model_Order_Payment_Transaction::TYPE_CAPTURE);
                    $payment->setIsTransactionClosed(1);
                    $payment->setParentTransactionId($tid);
                } else {
                    if ($result->epayresponse != -1) {
                        if ($result->epayresponse == -1019) {
                            throw new Exception($errorMessageBase . $this->epayHelper->__("Invalid password used for webservice access!"));
                        }

                        throw new Exception($errorMessageBase . '('.$result->epayresponse . ')' . $this->getEpayErrorText($result->epayresponse, $storeId));
                    } elseif ($result->pbsResponse != -1) {
                        throw new Exception($errorMessageBase . '('.$result->pbsResponse . ')' . $this->getPbsErrorText($result->pbsResponse, $storeId));
                    } else {
                        throw new Exception($errorMessageBase . $this->epayHelper->__("Unknown error!"));
                    }
                }
            } else {
                throw new Exception($errorMessageBase . $this->epayHelper->__("Order not found - please check the"). "epay_order_status table!");
            }

            return $this;
        }
        catch (Exception $e) {
            Mage::throwException($e->getMessage());
            return null;
        }
    }

    public function canRefund()
    {
        $creditOrder = $this->_data["info_instance"]->getOrder();

        if ($this->_canRefund && $this->canAction($creditOrder)) {
            return true;
        }

        return false;
    }


    public function refund(Varien_Object $payment, $amount)
    {
        try {
            if (!$this->canOnlineAction($payment)) {
                throw new Exception($this->epayHelper->__("The refund action could not, be processed online. Please enable remote payment processing from the module configuration"));
            }

            $errorMessageBase = $this->epayHelper->__("The payment could not be refunded by ePay:").' ';
            $read = Mage::getSingleton('core/resource')->getConnection('core_read');
            $row = $read->fetchRow("select * from epay_order_status where orderid = '" . $payment->getOrder()->getIncrementId() . "'");
            if ($row["status"] == '1') {

                /** @var Mage_Sales_Model_Order */
                $order = $payment->getOrder();
                $currency = $order->getBaseCurrencyCode();
                $minorunits = $this->epayHelper->getCurrencyMinorunits($currency);
                $storeId = $order->getStoreId();

                $amountInMinorunits = $this->epayHelper->convertPriceToMinorunits($amount, $minorunits, $this->getConfigData('roundingmode', $storeId));

                $tid = $row["tid"];
                $param = array(
                    'merchantnumber' => $this->getConfigData('merchantnumber', $storeId),
                    'transactionid' => $tid,
                    'amount' => (string)$amountInMinorunits,
                    'group' => '',
                    'pbsresponse' => 0,
                    'epayresponse' => 0,
                    'pwd' => $this->getRemotePassword($storeId)
                );
                $client = new SoapClient('https://ssl.ditonlinebetalingssystem.dk/remote/payment.asmx?WSDL');
                $result = $client->credit($param);

                if ($result->creditResult == 1) {
                    $payment->setTransactionId($tid .'-'. Mage_Sales_Model_Order_Payment_Transaction::TYPE_REFUND);
                    $payment->setIsTransactionClosed(1);
                    $payment->setParentTransactionId($tid);
                } else {
                    if ($result->epayresponse != -1) {
                        if ($result->epayresponse == -1019) {
                            throw new Exception($errorMessageBase . $this->epayHelper->__("Invalid password used for webservice access!"));
                        }

                        throw new Exception($errorMessageBase . '('.$result->epayresponse . ')' . $this->getEpayErrorText($result->epayresponse, $storeId));
                    } elseif ($result->pbsResponse != -1) {
                        throw new Exception($errorMessageBase . '('.$result->pbsResponse . ')' . $this->getPbsErrorText($result->pbsResponse, $storeId));
                    } else {
                        throw new Exception($errorMessageBase . $this->epayHelper->__("Unknown error!"));
                    }
                }
            } else {
                throw new Exception($errorMessageBase . $this->epayHelper->__("Order not found - please check the"). "epay_order_status table!");
            }

            return $this;
        }
        catch (Exception $e) {
            Mage::throwException($e->getMessage());
            return null;
        }
    }

    public function cancel(Varien_Object $payment)
    {
        try {
            if (Mage::app()->getRequest()->getActionName() == 'save' && (int)$this->getConfigData('cancelonedit', $payment->getOrder()->getStoreId()) === 0) {
                $this->adminMessageHandler()->addSuccess($this->epayHelper->__("The payment have not been voided for").' ('.$payment->getOrder()->getIncrementId() .')');
                return $this;
            }

            $this->void($payment);
            $this->adminMessageHandler()->addSuccess($this->epayHelper->__("The payment have been voided for").' ('.$payment->getOrder()->getIncrementId() .')');
        }
        catch (Exception $e) {
            $this->adminMessageHandler()->addError($e->getMessage());
        }

        return $this;
    }

    public function canVoid(Varien_Object $payment)
    {
        $voidOrder = $payment->getOrder();
        if (!isset($voidOrder)) {
            $voidOrder = $this->_data["info_instance"]->getOrder();
        }

        if ($this->_canVoid && $this->canAction($voidOrder)) {
            return true;
        }

        return false;
    }

    public function void(Varien_Object $payment)
    {
        try {
            if (!$this->canOnlineAction($payment)) {
                throw new Exception($this->epayHelper->__("The void action could not, be processed online. Please enable remote payment processing from the module configuration"));
            }

            $errorMessageBase = $this->epayHelper->__("The payment could not be deleted by ePay:").' ';
            $read = Mage::getSingleton('core/resource')->getConnection('core_read');
            $row = $read->fetchRow("select * from epay_order_status where orderid = '" . $payment->getOrder()->getIncrementId() . "'");

            if ($row["status"] == '1') {
                $storeId = $payment->getOrder()->getStoreId();
                $tid = $row["tid"];
                $param = array(
                    'merchantnumber' => $this->getConfigData('merchantnumber', $storeId),
                    'transactionid' => $tid,
                    'group' => '',
                    'epayresponse' => 0,
                    'pwd' => $this->getRemotePassword($storeId)
                );

                $client = new SoapClient('https://ssl.ditonlinebetalingssystem.dk/remote/payment.asmx?WSDL');
                $result = $client->delete($param);

                if ($result->deleteResult == 1) {
                    $payment->setTransactionId($tid .'-'. Mage_Sales_Model_Order_Payment_Transaction::TYPE_VOID);
                    $payment->setIsTransactionClosed(1);
                    $payment->setParentTransactionId($tid);
                } else {
                    if ($result->epayresponse != -1) {
                        if ($result->epayresponse == -1019) {
                            throw new Exception($errorMessageBase . $this->epayHelper->__("Invalid password used for webservice access!"));
                        }
                        throw new Exception($errorMessageBase . '('.$result->epayresponse . ')' . $this->getEpayErrorText($result->epayresponse, $storeId));
                    } else {
                        throw new Exception($errorMessageBase . $this->epayHelper->__("Unknown error!"));
                    }
                }
            } else {
                throw new Exception($errorMessageBase . $this->epayHelper->__("Order not found - please check the"). "epay_order_status table!");
            }

            return $this;
        }
        catch (Exception $e) {
            Mage::throwException($e->getMessage());
            return null;
        }
    }

    public function getEpayErrorText($errorcode, $storeId)
    {
        $res = "Unable to lookup errorcode";

        try {
            $client = new SoapClient('https://ssl.ditonlinebetalingssystem.dk/remote/payment.asmx?WSDL');

            $param = array(
                'merchantnumber' => $this->getConfigData('merchantnumber', $storeId),
                'language' => $this->calcLanguage(Mage::app()->getLocale()->getLocaleCode()),
                'epayresponsecode' => $errorcode,
                'epayresponsestring' => '',
                'epayresponse' => -1,
                'pwd' => $this->getRemotePassword($storeId)
            );

            $client = new SoapClient('https://ssl.ditonlinebetalingssystem.dk/remote/payment.asmx?WSDL');
            $result = $client->getEpayError($param);

            if ($result->getEpayErrorResult == 1) {
                $res = $result->epayresponsestring;
            }
        }
        catch (Exception $e) {
            return $res;
        }

        return $res;
    }

    public function getPbsErrorText($errorcode, $storeId)
    {
        $res = "Unable to lookup errorcode";

        try {
            $param = array(
                'merchantnumber' => $this->getConfigData('merchantnumber', $storeId),
                'language' => $this->calcLanguage(Mage::app()->getLocale()->getLocaleCode()),
                'pbsresponsecode' => $errorcode,
                'epayresponsestring' => 0,
                'epayresponse' => 0,
                'pwd' => $this->getRemotePassword($storeId)
            );
            $client = new SoapClient('https://ssl.ditonlinebetalingssystem.dk/remote/payment.asmx?WSDL');
            $result = $client->getPbsError($param);
            if ($result->getPbsErrorResult == 1) {
                $res = $result->pbsresponsestring;
            }
        }
        catch (Exception $e) {
            return $res;
        }

        return $res;
    }


    /**
     * Removes all special charactors from a string and replace them with a spacing
     *
     * @param string $value
     * @return string
     */
    private function removeSpecialCharacters($value)
    {
        return preg_replace('/[^\p{Latin}\d]/u', '', $value);
    }

    /**
     * Convert card id to name
     *
     * @param int $cardid
     * @return string
     */
    public function calcCardtype($cardid)
    {
        $cardIdArray = array(
            '1' => 'Dankort / VISA/Dankort',
            '2' => 'eDankort',
            '3' => 'VISA / VISA Electron',
            '4' => 'MasterCard',
            '6' => 'JCB',
            '7' => 'Maestro',
            '8' => 'Diners Club',
            '9' => 'American Express',
            '10' => 'ewire',
            '12' => 'Nordea e-betaling',
            '13' => 'Danske Netbetalinger',
            '14' => 'PayPal',
            '16' => 'MobilPenge',
            '17' => 'Klarna',
            '18' => 'Svea',
            '19' => 'SEB Direktbetalning',
            '20' => 'Nordea E-payment',
            '21' => 'Handelsbanken Direktbetalningar',
            '22' => 'Swedbank Direktbetalningar',
            '23' => 'ViaBill',
            '24' => 'NemPay',
            '25' => 'iDeal');

        return key_exists($cardid, $cardIdArray) ? $cardIdArray[$cardid] : '';
    }

    /**
     * Returns information about magento and module version
     *
     * @return string
     */
    public function getCmsInfo()
    {
        $bamboraVersion = (string) Mage::getConfig()->getNode()->modules->Mage_Epay->version;
        $magentoVersion = Mage::getVersion();
        $result = 'Magento/' . $magentoVersion . ' Module/' . $bamboraVersion . ' PHP/' . phpversion();

        return $result;
    }

    public function generateMD5Key($paymentRequest, $md5Key)
    {
        $valueString = implode($paymentRequest).$md5Key;
        return md5($valueString);
    }

    /**
     * Convert country code to a number
     *
     * @param mixed $lan
     * @return string
     */
    public function calcLanguage($lan = null)
    {
        if (!isset($lan)) {
            $lan = Mage::app()->getLocale()->getLocaleCode();
        }

        $languageArray = array(
            'da_DK' => '1',
            'de_CH' => '7',
            'de_DE' => '7',
            'en_AU' => '2',
            'en_GB' => '2',
            'en_NZ' => '2',
            'en_US' => '2',
            'sv_SE' => '3',
            'nn_NO' => '4',
            );

        return key_exists($lan, $languageArray) ? $languageArray[$lan] : '2';
    }

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('epay/standard/redirect');
    }


    public function getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    public function getQuote()
    {
        return $this->getCheckout()->getQuote();
    }

    public function getStore()
    {
        return Mage::app()->getStore();
    }

    public function getAcceptUrl()
    {
        return Mage::getUrl('epay/standard/success', array('_nosid' => true));
    }

    public function getCancelUrl()
    {
        return Mage::getUrl('epay/standard/cancel', array('_nosid' => true));
    }

    public function getCallbackUrl()
    {
        return Mage::getUrl('epay/standard/callback', array('_nosid' => true));
    }

    public function adminMessageHandler()
    {
        return Mage::getSingleton('adminhtml/session');
    }
}
