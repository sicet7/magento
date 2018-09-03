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
class Mage_Epay_Block_Standard_Info extends Mage_Payment_Block_Info
{
    protected function _prepareSpecificInformation($transport = null)
    {
        if ($this->_paymentSpecificInformation !== null) {
            return $this->_paymentSpecificInformation;
        }

        $transport = new Varien_Object();
        $transport = parent::_prepareSpecificInformation($transport);

        $info = $this->getInfo();
        $order = $info->getOrder();
        if (!isset($order)) {
            return $transport;
        }

        $payment = $order->getPayment();
        $transactionId = $payment->getAdditionalInformation(Mage_Epay_Model_Standard::PSP_REFERENCE);
        $paymentType = $payment->getCcType();
        $truncatedCardNumberEnc = $payment->getCcNumberEnc();
        $truncatedCardNumber = "";
        if(!empty($truncatedCardNumberEnc)) {
            $truncatedCardNumber = Mage::helper('core')->decrypt($truncatedCardNumberEnc);
        }
        //For orders before module version 2.9.1
        if (empty($truncatedCardNumber) || strpos($truncatedCardNumber, 'XXXX') !== false) {
            $orderIncrementId = $order->getIncrementId();
            $read = Mage::getSingleton('core/resource')->getConnection('core_read');
            $row = $read->fetchRow("select * from epay_order_status where orderid = '" . $orderIncrementId . "'");
            if ($row['status'] == '1') {
                $truncatedCardNumber = $row['cardnopostfix'];
            }
        }


        //For orders before module version 2.7.0
        if (empty($transactionId) || empty($paymentType)) {
            $orderIncrementId = $order->getIncrementId();
            $read = Mage::getSingleton('core/resource')->getConnection('core_read');
            $row = $read->fetchRow("select * from epay_order_status where orderid = '" . $orderIncrementId . "'");
            if ($row['status'] == '1') {
                $method = Mage::getModel('epay/standard');
                $transactionId = $row['tid'];
                $paymentType = $method->calcCardtype($row['cardid']);
                $truncatedCardNumber = $row['cardnopostfix'];
            }
        }

        if (!empty($transactionId)) {
            $key = Mage::helper('epay')->__("Transaction ID");
            $transport->addData(array($key => $transactionId));
        }

        if (!empty($paymentType)) {
            $key = Mage::helper('epay')->__("Card type");
            $transport->addData(array($key => $paymentType));
        }

        if (!empty($truncatedCardNumber)) {
            $key = Mage::helper('epay')->__("Card number");
            $transport->addData(array($key => $truncatedCardNumber));
        }

        return $transport;
    }
}
