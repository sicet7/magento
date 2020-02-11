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
class Mage_Epay_Model_Observer
{
    public function adminhtmlWidgetContainerHtmlBefore($event)
    {
        $block = $event->getBlock();
        if ($block instanceof Mage_Adminhtml_Block_Sales_Order_View) {
            $epayStandard = Mage::getModel('epay/standard');

            /** @var Mage_Sales_Model_Order */
            $order = $this->getOrder();
            if (!$this->validatePaymentMethod($order) || $order->isCanceled()) {
                return;
            }

            $read = Mage::getSingleton('core/resource')->getConnection('core_read');
            $row = $read->fetchRow("select * from epay_order_status where orderid = '" . $order->getIncrementId() . "'");

            if (!$row || $row['status'] == '0') {
                $block->addButton('button_sendpaymentrequest', array('label' => Mage::helper('epay')->__("Create payment request"), 'onclick' => 'setLocation(\'' . Mage::helper("adminhtml")->getUrl('adminhtml/paymentrequest/create/', array('id' => $order->getRealOrderId())) . '\')', 'class' => 'scalable go'), 0, 0, 'header');
            }

            if ($order != null && $order->getRelationParentRealId() != null && (int)$epayStandard->getConfigData('cancelonedit', $order->getStoreId()) === 0) {
                $payment = $order->getPayment();
                if (isset($payment) && ($payment->getAdditionalInformation('currentpaymentincrementid') === null || $payment->getAdditionalInformation('currentpaymentincrementid') != $order->getRealOrderId())) {
                    $block->addButton('button_movepayment', array('label' => Mage::helper('epay')->__("Move Payment from parent"), 'onclick' => 'setLocation(\'' . Mage::helper("adminhtml")->getUrl('adminhtml/payment/move/', array('orderid' => $order->getRealOrderId(), 'parentorderid' => $order->getRelationParentRealId())) . '\')', 'class' => 'scalable go'), 0, 0, 'header');
                }
            }
        }
    }

    public function addMassOrderAction($event)
    {
        $block = $event->getBlock();
        if ($block instanceof Mage_Adminhtml_Block_Widget_Grid_Massaction && $block->getRequest()->getControllerName() == 'sales_order') {
            $block->addItem(
                'epay_capture', array(
                'label'=> Mage::helper('epay')->__("ePay - Mass Invoice and Capture"),
                'url'  => $block->getUrl('adminhtml/massaction/epaymasscapture'),
                'confirm' => Mage::helper('epay')->__("Are you sure you want to invoice and capture selected items?")
                )
            );

            $block->addItem(
                'epay_delete', array(
                'label'=> Mage::helper('epay')->__("ePay - Mass Delete"),
                'url'  => $block->getUrl('adminhtml/massaction/epaymassdelete'),
                'confirm' => Mage::helper('epay')->__("Are you sure you want to delete selected items? This can not be undone! If there have been authorized a payment on the order it will not get voided by this action.")
                )
            );
        }
    }

    public function addMassInvoiceAction($event)
    {
        $block = $event->getBlock();
        if ($block instanceof Mage_Adminhtml_Block_Widget_Grid_Massaction && $block->getRequest()->getControllerName() == 'sales_invoice') {
            $block->addItem(
                'epay_invoice', array(
                'label'=> Mage::helper('epay')->__("ePay - Mass Creditmemo and Refund"),
                'url'  => $block->getUrl('adminhtml/massaction/epaymassrefund'),
                'confirm' => Mage::helper('epay')->__("Are you sure you want to refund selected items?")
                )
            );
        }
    }


    /**
     * Auto Cancel orders that are from 1 day until 1 hour ago and with custom pending status
     */
    public function autocancelPendingOrders(Mage_Cron_Model_Schedule $schedule = null)
    {
        $epayStandard = Mage::getModel('epay/standard');

        $storeId = $epayStandard->getStore()->getId();

        if (intval($epayStandard->getConfigData('use_auto_cancel', $storeId)) === 1) {
            $date = Mage::getSingleton('core/date');
            $fromDateTimeConfig = $epayStandard->getConfigData('auto_cancel_from_minute', $storeId);
            $toDateTimeConfig = $epayStandard->getConfigData('auto_cancel_to_minute', $storeId);

            $fromDateTimeValue = empty($fromDateTimeConfig) ? "1440" : $fromDateTimeConfig;
            $toDateTimeValue = empty($toDateTimeConfig) ? "60" : $toDateTimeConfig;

            $orderCollection = Mage::getResourceModel('sales/order_collection');
            $orderCollection
                ->addFieldToFilter('status', array('eq' => $epayStandard->getConfigData('order_status', null)))
                ->addFieldToFilter(
                    'created_at', array(
                    'to' => strtotime("-{$toDateTimeValue} minutes", strtotime($date->gmtDate())),
                    'from' => strtotime("-{$fromDateTimeValue} minutes", strtotime($date->gmtDate())),
                    'datetime' => true)
                )
                ->setOrder('created_at', 'ASC')
                ->getSelect();

            foreach ($orderCollection->getItems() as $order) {
                /** @var Mage_Sales_Model_Order */
                $orderModel = Mage::getModel('sales/order');
                $orderModel->load($order["entity_id"]);

                try {
                    if (!$this->validatePaymentMethod($orderModel)) {
                        continue;
                    }

                    if (!$orderModel->canCancel()) {
                        continue;
                    }

                    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
                    $row = $read->fetchRow("select * from epay_order_status where orderid = '" . $orderModel->getIncrementId() . "'");

                    if ($row["status"] == '0') {
                        $orderModel->cancel();
                        $message = Mage::helper('epay')->__("Order was auto canceled because no payment has been made.");
                        $orderModel->addStatusHistoryComment($message)->setIsCustomerNotified(false);;
                        $orderModel->save();
                    }
                } catch (Exception $e) {
                    if ($schedule) {
                        $message = "Could not be canceled: {$e->getMessage()}\n";
                        $schedule->setMessages($schedule->getMessages() . $message);
                    }

                    Mage::logException($e);
                }
            }
        }
    }

    public function orderPlacedAfter($observer)
    {
        /** @var Mage_Sales_Model_Order */
        $order = $observer->getOrder();
        if ($this->validatePaymentMethod($order)) {
            $comment = Mage::helper('epay')->__("The Order is placed using ePay Online payment system and is now awaiting payment.");
            $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT,$order->getStatus(),$comment,false);
            $order->save();
        }
    }

    /**
     * Get order
     *
     * @return mixed
     */
    private function getOrder()
    {
        return Mage::registry('current_order');
    }

    private function validatePaymentMethod($order)
    {
        $currentMethod = $order->getPayment()->getMethod();
        return $currentMethod === Mage_Epay_Model_Standard::METHOD_CODE;
    }
}
