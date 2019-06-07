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

use Mage_Epay_Helper_EpayConstant as EpayConstant;

class Mage_Epay_StandardController extends Mage_Core_Controller_Front_Action
{
    /**
     * @var Mage_Epay_Helper_Data
     */
    private $epayHelper;

    protected function _construct()
    {
        $this->epayHelper = Mage::helper('epay');
    }

    /**
     * Get singleton with epay strandard order transaction information
     *
     * @return Mage_Epay_Model_Standard
     */
    private function getMethod()
    {
        return Mage::getSingleton('epay/standard');
    }

    /**
     * Redirect Action
     */
    public function redirectAction()
    {
        $session = Mage::getSingleton('checkout/session');
        try {
            $session->setEpayStandardQuoteId($session->getQuoteId());

            $orderModel = Mage::getModel('sales/order');
            /** @var Mage_Sales_Model_Order */
            $order = $orderModel->loadByIncrementId($session->getLastRealOrderId());

            $payment = $order->getPayment();
            $pspReference = null;
            if ($payment instanceof Mage_Sales_Model_Order_Payment) {
                $pspReference = $payment->getAdditionalInformation(Mage_Epay_Model_Standard::PSP_REFERENCE);
            }

            $lastSuccessfullQuoteId = $session->getLastSuccessQuoteId();
            $orderId = $order->getIncrementId();
            if (!empty($pspReference) || empty($lastSuccessfullQuoteId)) {
                $this->_redirect('checkout/cart');
            } else {
                $dbReader = Mage::getSingleton('core/resource')->getConnection('core_read');
                $statusRow = $dbReader->fetchRow("SELECT status, orderid FROM epay_order_status WHERE orderid = '{$orderId}'");
                if (!$statusRow || $statusRow['status'] == '0') {
                    $dbWriter = Mage::getSingleton('core/resource')->getConnection('core_write');
                    $query = "INSERT INTO epay_order_status (orderid) VALUES (:orderid)";
                    $binds = array(':orderid' => $orderId);
                    $dbWriter->query($query, $binds);
                }

                $paymentMethod = $this->getMethod();
                $paymentRequest = $paymentMethod->getPaymentRequest($order);
                $paymentRequestString = $paymentMethod->getPaymentRequestAsString($paymentRequest);

                $paymentData = array("paymentRequest"=> $paymentRequestString,
                                     "headerText"=> $this->epayHelper->__("Thank you for using Bambora Online ePay"),
                                     "headerText2"=> $this->epayHelper->__("Please wait..."));

                $this->loadLayout();
                $block = $this->getLayout()->createBlock('epay/standard_redirect', 'epayredirect', $paymentData);
                $this->getLayout()->getBlock('content')->append($block);
                $this->renderLayout();
            }
        }
        catch (Exception $e) {
            $session->addError($this->epayHelper->__("An error occured. Please try again!"));
            Mage::logException($e);
            $this->_redirect("epay/standard/cancel");
        }
    }

    /**
     * Cancel Action
     */
    public function cancelAction()
    {
        /** @var Mage_Checkout_Model_Session */
        $session = Mage::getSingleton('checkout/session');
        $cart = Mage::getSingleton('checkout/cart');
        $larstOrderId = $session->getLastRealOrderId();
        /** @var Mage_Sales_Model_Order */
        $order = Mage::getModel('sales/order')->loadByIncrementId($larstOrderId);
        if ($order->getId()) {
            /** @var Mage_Sales_Model_Order_Payment */
            $orderPayment = $order->getPayment();
            $pspReference = $orderPayment->getAdditionalInformation(Mage_Epay_Model_Standard::PSP_REFERENCE);
            if(empty($pspReference)){
                $session->getQuote()->setIsActive(0)->save();
                $session->clear();
                try {
                    $order->setActionFlag(Mage_Sales_Model_Order::ACTION_FLAG_CANCEL, true);
                    $order->cancel()->save();
                }
                catch (Mage_Core_Exception $e) {
                    Mage::logException($e);
                }

                $items = $order->getItemsCollection();
                foreach ($items as $item) {
                    try {
                        $cart->addOrderItem($item);
                    }
                    catch (Mage_Core_Exception $e) {
                        $session->addError($this->__($e->getMessage()));
                        Mage::logException($e);
                        continue;
                    }
                }
                $cart->save();
            }
        }

        $this->_redirect('checkout/cart');
    }

    /**
     * Success Action
     */
    public function successAction()
    {
        Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
        $this->_redirect('checkout/onepage/success');
    }

    /**
     * Callback action
     *
     * @return Zend_Controller_Response_Abstract
     */
    public function callbackAction()
    {
        $message ='';
        $responseCode = '400';
        $order = null;
        $request = $this->getRequest();
        $getQuery = $request->getQuery();
        if ($this->validateCallback($getQuery, $message, $order)) {
            $message = $this->processCallback($getQuery, $responseCode);
        } else {
            if (isset($order) && $order->getId()) {
                $order->addStatusHistoryComment("Callback from ePay returned with an error: ". $message);
                $order->save();
            }
        }

        $this->getResponse()->setHeader('HTTP/1.0', $responseCode, true)
            ->setHeader('Content-type', 'application/json', true)
            ->setHeader('X-EPay-System', $this->getMethod()->getCmsInfo())
            ->setBody($message);

        return $this->_response;
    }

    /**
     * Validate the callback
     *
     * @param array $getQuery
     * @param string $message
     * @param Mage_Sales_Model_Order $order
     * @return boolean
     */
    private function validateCallback($getQuery, &$message, &$order)
    {
        if (!$getQuery['txnid']) {
            $message = "No GET(txnid) was supplied to the system!";
            return false;
        }

        if (!$getQuery['orderid']) {
            $message = "No GET(orderid) was supplied to the system!";
            return false;
        }

        if (!$getQuery['amount']) {
            $message = "No GET(amount) supplied to the system!";
            return false;
        }

        if (!$getQuery['currency']) {
            $message = "No GET(currency) supplied to the system!";
            return false;
        }

        $order = Mage::getModel('sales/order')->loadByIncrementId($getQuery['orderid']);
        if (!isset($order) || !$order->getId()) {
            $message = "The order object could not be loaded";
            return false;
        }

        if ($order->getIncrementId() != $getQuery['orderid']) {
            $message = "The loaded order id does not match the callback GET(orderId)";
            return false;
        }

        $method = $this->getMethod();
        $storeId = $order->getStoreId();
        $storeMd5 = $method->getConfigData('md5key', $storeId);
        if (!empty($storeMd5)) {
            $var = "";
            foreach ($getQuery as $key => $value) {
                if ($key === "hash") {
                    break;
                }
                $var .= $value;
            }

            $storeHash = md5($var . $storeMd5);
            if ($storeHash != $getQuery['hash']) {
                $message = "Hash validation failed - Please check your MD5 key";
                return false;
            }
        }

        return true;
    }

    /**
     * Process the callback
     *
     * @param array $getQuery
     * @param string $responseCode
     */
    private function processCallback($getQuery, &$responseCode)
    {
        try{
            $message = '';
            /** @var Mage_Sales_Model_Order */
            $order = Mage::getModel('sales/order')->loadByIncrementId($getQuery['orderid']);
            $payment = $order->getPayment();
            try {
                $pspReference = $payment->getAdditionalInformation(Mage_Epay_Model_Standard::PSP_REFERENCE);
                if (empty($pspReference) && !$order->isCanceled()) {
                    $method = $this->getMethod();
                    $storeId = $order->getStoreId();

                    $this->persistDataInEpayDBTable($getQuery);

                    $orderStatusAfterPayment = $method->getConfigData('order_status_after_payment', $storeId);
                    $this->updatePaymentData($order, $getQuery, $orderStatusAfterPayment);

                    if (intval($method->getConfigData('enablesurcharge', $storeId)) == 1 && $getQuery['txnfee'] && floatval($getQuery['txnfee']) > 0) {
                        $this->addSurchargeToOrder($order, $method);
                    }

                    if (intval($method->getConfigData('sendmailorderconfirmation', $storeId) == 1)) {
                        $this->sendOrderEmail($order);
                    }

                    if (intval($method->getConfigData('instantinvoice', $storeId)) == 1) {
                        $this->createInvoice($order);
                    }

                    $message = "Callback Success - Order created";
                } else {
                    if ($order->isCanceled()) {
                        $message = "Callback Success - Order was canceled by Magento";
                    } else {
                        $message = "Callback Success - Order already created";
                    }
                }

                $responseCode = '200';
            }
            catch (Exception $e) {
                Mage::logException($e);
                $message = "Callback Failed: " .$e->getMessage();
                $order->addStatusHistoryComment($message);
                $payment->setAdditionalInformation(Mage_Epay_Model_Standard::PSP_REFERENCE, "");
                $payment->save();
                $order->save();
                $responseCode = '500';
            }

            return $message;
        }
        catch(Exception $e) {
            throw $e;
        }
    }

    /**
     * Persist the callback data into the epay_order_status table
     *
     * @param array $getQuery
     */
    private function persistDataInEpayDBTable($getQuery)
    {
        try {
            $orderId = $getQuery['orderid'];
            $dbReader = Mage::getSingleton('core/resource')->getConnection('core_read');
            $dbWriter = Mage::getSingleton('core/resource')->getConnection('core_write');
            $ePayRow = $dbReader->fetchRow("SELECT status, orderid FROM epay_order_status WHERE orderid = '{$orderId}'");
            if (!$ePayRow && $getQuery['paymentrequest'] && strlen($getQuery['paymentrequest']) > 0) {
                $query = "INSERT INTO epay_order_status (orderid, status) VALUES (:orderid, :status)";
                $binds = array(
                    ':orderid'   =>  $orderId,
                    ':status'    =>  0);
                $dbWriter->query($query, $binds);
                $ePayRow = $dbReader->fetchRow("SELECT status, orderid FROM epay_order_status WHERE orderid = '{$orderId}'");
            }

            if (isset($getQuery['paymentrequest']) && strlen($getQuery['paymentrequest']) > 0) {
                //Mark as paid
                $paymentRequestUpdate = Mage::getModel('epay/paymentrequest')->load($getQuery['paymentrequest'])->setData('ispaid', "1");
                $paymentRequestUpdate->setId($getQuery['paymentrequest'])->save($paymentRequestUpdate);
            }

            if ($ePayRow['status'] == '0') {
                $txnId = array_key_exists('txnid', $getQuery) ? $getQuery['txnid'] : '0';
                $amount = array_key_exists('amount', $getQuery) ? $getQuery['amount'] : '0';
                $currency = array_key_exists('currency', $getQuery) ? $getQuery['currency'] : '0';
                $date = array_key_exists('date', $getQuery) ? $getQuery['date'] : '0';
                $eKey = array_key_exists('hash', $getQuery) ? $getQuery['hash'] : '0';
                $fraud = array_key_exists('fraud', $getQuery) ? $getQuery['fraud'] : '0';
                $subscriptionId = array_key_exists('subscriptionid', $getQuery) ? $getQuery['subscriptionid'] : '0';
                $cardId = array_key_exists('paymenttype', $getQuery) ? $getQuery['paymenttype'] : '0';
                $cardNoPostfix = array_key_exists('cardno', $getQuery) ? $getQuery['cardno'] : '';
                $transFee = array_key_exists('txnfee', $getQuery) ? $getQuery['txnfee'] : '0';

                $query = "UPDATE epay_order_status SET ".
                    "tid = :tid, status = :status, amount = :amount, cur = :cur, ".
                    "date = :date, eKey = :eKey, fraud = :fraud, subscriptionid = :subscriptionid, ".
                    "cardid = :cardid, cardnopostfix = :cardnopostfix, transfee = :transfee ".
                    "WHERE orderid = '{$orderId}'";

                $binds = array(
                    ':tid'               =>  $txnId,
                    ':status'            =>  1,
                    ':amount'            =>  $amount,
                    ':cur'               =>  $currency,
                    ':date'              =>  $date,
                    ':eKey'              =>  $eKey,
                    ':fraud'             =>  $fraud,
                    ':subscriptionid'    =>  $subscriptionId,
                    ':cardid'            =>  $cardId,
                    ':cardnopostfix'     =>  $cardNoPostfix,
                    ':transfee'          =>  $transFee,
                );

                $dbWriter->query($query, $binds);
            }
        }
        catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Update the payment data
     *
     * @param Mage_Sales_Model_Order $order
     * @param array $getQuery
     * @param string $orderStatusAfterPayment
     */
    private function updatePaymentData($order, $getQuery, $orderStatusAfterPayment)
    {
        try{
            $methodInstance = $this->getMethod();
            $txnId = $getQuery['txnid'];
            /** @var Mage_Sales_Model_Order_Payment */
            $payment = $order->getPayment();
            $payment->setTransactionId($txnId);
            $payment->setIsTransactionClosed(false);
            $payment->setAdditionalInformation(Mage_Epay_Model_Standard::PSP_REFERENCE, $txnId);
            $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH);

            if (array_key_exists('cardno', $getQuery)) {
                $cardNo = $getQuery['cardno'];
                $encodedCardNo = Mage::helper('core')->encrypt($cardNo);
                $payment->setCcNumberEnc($encodedCardNo);
            }

            if (array_key_exists('paymenttype', $getQuery)) {
                $payment->setCcType($methodInstance->calcCardtype($getQuery['paymenttype']));
            }

            if (array_key_exists('fraud', $getQuery) && $getQuery['fraud'] == 1) {
                $payment->setIsFraudDetected(true);
                $message = $this->epayHelper->__("Fraud was detected on the payment");
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Sales_Model_Order::STATUS_FRAUD, $message, false);
            } else {
                $message = $this->epayHelper->__("Payment authorization was a success.") . ' ' . $this->epayHelper->__("Transaction ID").': '.$txnId;
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, $orderStatusAfterPayment, $message, false);
            }

            $isInstantCapture = intval($methodInstance->getConfigData('instantcapture', $order->getStoreId())) === 1 ? true : false;
            $payment->setAdditionalInformation('instantcapture', $isInstantCapture);

            $payment->save();
            $order->save();
        }
        catch(Exception $e) {
            throw $e;
        }
    }

    /**
     * Add Surcharge item to the order as a order line
     *
     * @param Mage_Sales_Model_Order $order
     * @param Mage_Epay_Model_Standard $method
     * @return void
     */
    private function addSurchargeToOrder($order, $method)
    {
        try{
            $methodInstance = $this->getMethod();
            $request = $this->getRequest();
            $currency = $order->getBaseCurrencyCode();
            $minorunits = $this->epayHelper->getCurrencyMinorunits($currency);

            $baseFeeAmountInMinorunits = (int)$request->getQuery('txnfee');
            $baseFeeAmount = $this->epayHelper->convertPriceFromMinorunits($baseFeeAmountInMinorunits, $minorunits);

            $feeAmount = Mage::helper('directory')->currencyConvert($baseFeeAmount, $order->getBaseCurrencyCode(), $order->getOrderCurrencyCode());

            foreach ($order->getAllItems() as $item) {
                if ($item->getSku() === 'surcharge_fee') {
                    return;
                }
            }
            $getQuery = $request->getQuery();
            $text = $this->epayHelper->__('Surcharge fee');
            if(array_key_exists('paymenttype', $getQuery)) {
                $text = $methodInstance->calcCardtype($getQuery['paymenttype']) . ' - ' . $this->epayHelper->__('Surcharge fee');
            }

            $feeMessage = "";
            $storeId = $order->getStoreId();

            if($method->getConfigData('surchargemode', $storeId) === EpayConstant::SURCHARGE_ORDER_LINE) {
                /** @var Mage_Sales_Model_Order_Item */
                $feeItem = $this->epayHelper->createFeeItem($baseFeeAmount, $feeAmount, $storeId, $order->getId(), $text);
                $order->addItem($feeItem);
                $order->setBaseSubtotal($order->getBaseSubtotal() + $baseFeeAmount);
                $order->setBaseSubtotalInclTax($order->getBaseSubtotalInclTax() + $baseFeeAmount);
                $order->setSubtotal($order->getSubtotal() + $feeAmount);
                $order->setSubtotalInclTax($order->getSubtotalInclTax() + $feeAmount);
            } else {
                //Add fee to shipment
                $order->setBaseShippingAmount($order->getBaseShippingAmount() + $baseFeeAmount);
                $order->setBaseShippingInclTax($order->getBaseShippingInclTax() + $baseFeeAmount);
                $order->setShippingAmount($order->getShippingAmount() + $feeAmount);
                $order->setShippingInclTax($order->getShippingInclTax() + $feeAmount);
            }

            $order->setBaseGrandTotal($order->getBaseGrandTotal() + $baseFeeAmount);
            $order->setGrandTotal($order->getGrandTotal() + $feeAmount);

            $feeMessage = $text . ' ' .__("added to order");
            $order->addStatusHistoryComment($feeMessage);
            $order->save();
        }
        catch(Exception $e) {
            throw $e;
        }
    }

    /**
     * Send an order confirmation to the customer
     *
     * @param Mage_Sales_Model_Order $order
     */
    private function sendOrderEmail($order)
    {
        try{
            $order->sendNewOrderEmail();
            $order->addStatusHistoryComment(sprintf($this->epayHelper->__("Notified customer about order #%s"), $order->getIncrementId()))
                ->setIsCustomerNotified(true);
            $order->save();
        }
        catch(Exception $e) {
            throw $e;
        }
    }

    /**
     * Create an invoice
     *
     * @param Mage_Sales_Model_Order $order
     */
    private function createInvoice($order)
    {
        try{
            if ($order->canInvoice()) {
                $method = $this->getMethod();
                $storeId = $order->getStoreId();
                $invoice = $order->prepareInvoice();

                if ((int)$method->getConfigData('instantcapture', $storeId) === 1) {
                    $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                }

                $invoice->register();
                $invoice->save();

                $transactionSave = Mage::getModel('core/resource_transaction')
                  ->addObject($invoice)
                  ->addObject($invoice->getOrder());
                $transactionSave->save();

                if (intval($this->getMethod()->getConfigData('instantinvoicemail', $storeId)) == 1) {
                    $invoice->sendEmail();
                    $order->addStatusHistoryComment(sprintf($this->epayHelper->__("Notified customer about invoice #%s"), $invoice->getId()))
                        ->setIsCustomerNotified(true);
                    $order->save();
                }
            }
        }
        catch(Exception $e) {
            throw $e;
        }
    }
}
