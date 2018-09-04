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

            if (!empty($pspReference) || empty($lastSuccessfullQuoteId)) {
                $this->_redirect('checkout/cart');
            } else {
                $read = Mage::getSingleton('core/resource')->getConnection('core_read');
                $row = $read->fetchRow("select * from epay_order_status where orderid = '" . $order->getIncrementId() . "'");
                if (!$row || $row['status'] == '0') {
                    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
                    $write->insert('epay_order_status', array('orderid'=> $order->getIncrementId()));
                }

                $paymentMethod = $this->getMethod();
                $paymentRequest = $paymentMethod->getPaymentRequest($order);
                $paymentRequestString = $paymentMethod->getPaymentRequestAsString($paymentRequest);

                $paymentData = array("paymentRequest"=> $paymentRequestString,
                                     "cancelUrl"=> $paymentRequest["cancelurl"],
                                     "headerText"=> $this->epayHelper->__("Thank you for using Bambora Online ePay"),
                                     "headerText2"=> $this->epayHelper->__("Please wait..."),
                                     "isOverlay"=> $paymentRequest["windowstate"]);

                $this->loadLayout();
                $block = $this->getLayout()->createBlock('epay/standard_redirect', 'epayredirect', $paymentData);
                $this->getLayout()->getBlock('content')->append($block);
                $this->renderLayout();
            }
        }
        catch (Exception $e) {
            $session->addError($this->bamboraHelper->_s("An error occured. Please try again!"));
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
        if ($this->validateCallback($message, $order)) {
            $message = $this->processCallback($responseCode);
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
     * @param string $message
     * @return boolean
     */
    private function validateCallback(&$message, &$order)
    {
        $request = $this->getRequest();
        if (!$request->getQuery('txnid')) {
            $message = "No GET(txnid) was supplied to the system!";
            return false;
        }

        if (!$request->getQuery('orderid')) {
            $message = "No GET(orderid) was supplied to the system!";
            return false;
        }

        if (!$request->getQuery('amount')) {
            $message = "No GET(amount) supplied to the system!";
            return false;
        }

        if (!$request->getQuery('currency')) {
            $message = "No GET(currency) supplied to the system!";
            return false;
        }

        $order = Mage::getModel('sales/order')->loadByIncrementId($request->getQuery('orderid'));
        if (!isset($order) || !$order->getId()) {
            $message = "The order object could not be loaded";
            return false;
        }

        if ($order->getIncrementId() != $request->getQuery('orderid')) {
            $message = "The loaded order id does not match the callback GET(orderId)";
            return false;
        }

        $method = $this->getMethod();
        $storeId = $order->getStoreId();
        $storeMd5 = $method->getConfigData('md5key', $storeId);
        if (!empty($storeMd5)) {
            $accept_params = $request->getQuery();
            $var = "";
            foreach ($accept_params as $key => $value) {
                if ($key != "hash") {
                    $var .= $value;
                }
            }

            $storeHash = md5($var . $storeMd5);
            if ($storeHash != $request->getQuery('hash')) {
                $message = "Hash validation failed - Please check your MD5 key";
                return false;
            }
        }

        return true;
    }

    /**
     * Process the callback
     *
     * @param string $responseCode
     */
    private function processCallback(&$responseCode)
    {
        try{
            $message = '';
            $request = $this->getRequest();
            /** @var Mage_Sales_Model_Order */
            $order = Mage::getModel('sales/order')->loadByIncrementId($request->getQuery('orderid'));
            $payment = $order->getPayment();
            try {
                $pspReference = $payment->getAdditionalInformation(Mage_Epay_Model_Standard::PSP_REFERENCE);
                if (empty($pspReference) && !$order->isCanceled()) {
                    $method = $this->getMethod();
                    $storeId = $order->getStoreId();

                    $this->persistDataInEpayDBTable();

                    $this->updatePaymentData($order, $method->getConfigData('order_status_after_payment', $storeId));

                    if (intval($method->getConfigData('enablesurcharge', $storeId)) == 1 && $request->getQuery('txnfee') && floatval($request->getQuery('txnfee')) > 0) {
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
     * Persist the callback data into the epay_prder_status table
     */
    private function persistDataInEpayDBTable()
    {
        try {
            $request = $this->getRequest();
            $read = Mage::getSingleton('core/resource')->getConnection('core_read');
            $row = $read->fetchRow("select * from epay_order_status where orderid = '" . $request->getQuery('orderid') . "'");

            if (!$row && $request->getQuery('paymentrequest') && strlen($request->getQuery('paymentrequest')) > 0) {
                $write = Mage::getSingleton('core/resource')->getConnection('core_write');
                $write->insert('epay_order_status', array('orderid'=>$request->getQuery('orderid')));

                $read = Mage::getSingleton('core/resource')->getConnection('core_read');
                $row = $read->fetchRow("select * from epay_order_status where orderid = '" . $request->getQuery('orderid') . "'");
            }

            if ($request->getQuery('paymentrequest') && strlen($request->getQuery('paymentrequest')) > 0) {
                //Mark as paid
                $paymentRequestUpdate = Mage::getModel('epay/paymentrequest')->load($request->getQuery('paymentrequest'))->setData('ispaid', "1");
                $paymentRequestUpdate->setId($request->getQuery('paymentrequest'))->save($paymentRequestUpdate);
            }

            if ($row['status'] == '0') {
                $getQuery = $request->getQuery();
                $txnId = array_key_exists('txnid', $getQuery) ? $getQuery['txnid'] : '0';
                $amount = array_key_exists('amount', $getQuery) ? $getQuery['amount'] : '0';
                $cur = array_key_exists('currency', $getQuery) ? $getQuery['currency'] : '0';
                $date = array_key_exists('date', $getQuery) ? $getQuery['date'] : '0';
                $eKey = array_key_exists('hash', $getQuery) ? $getQuery['hash'] : '0';
                $fraud = array_key_exists('fraud', $getQuery) ? $getQuery['fraud'] : '0';
                $subscriptionId = array_key_exists('subscriptionid', $getQuery) ? $getQuery['subscriptionid'] : '0';
                $cardId = array_key_exists('paymenttype', $getQuery) ? $getQuery['paymenttype'] : '0';
                $cardNoPostfix = array_key_exists('cardno', $getQuery) ? $getQuery['cardno'] : '';
                $transFee = array_key_exists('txnfee', $getQuery) ? $getQuery['txnfee'] : '0';
                $orderId = $getQuery['orderid'];

                $write = Mage::getSingleton('core/resource')->getConnection('core_write');
                $query = "UPDATE epay_order_status SET
                    tid = '" . $txnId . "',
                    status = 1,
                    amount = '" . $amount . "',
                    cur = '" . $cur . "',
                    date = '" . $date . "',
                    eKey = '" . $eKey . "',
                    fraud = '" . $fraud . "',
                    subscriptionid = '" . $subscriptionId . "',
                    cardid = '" . $cardId . "',
                    cardnopostfix = '" . $cardNoPostfix . "',
                    transfee = '" . $transFee . "'
                    WHERE orderid = '" . $orderId ."'";

                $write->query($query);
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
     * @param string $orderStatusAfterPayment
     */
    private function updatePaymentData($order, $orderStatusAfterPayment)
    {
        try{
            $methodInstance = $this->getMethod();
            $request = $this->getRequest();
            $txnId = $request->getQuery('txnid');
            /** @var Mage_Sales_Model_Order_Payment */
            $payment = $order->getPayment();
            $payment->setTransactionId($txnId);
            $payment->setIsTransactionClosed(false);
            $payment->setAdditionalInformation(Mage_Epay_Model_Standard::PSP_REFERENCE, $txnId);
            $payment->addTransaction(Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH);

            $getQuery = $request->getQuery();
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
