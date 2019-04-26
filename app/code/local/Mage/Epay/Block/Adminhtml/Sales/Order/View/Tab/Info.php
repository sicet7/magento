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
class Mage_Epay_Block_Adminhtml_Sales_Order_View_Tab_Info extends Mage_Adminhtml_Block_Template implements Mage_Adminhtml_Block_Widget_Tab_Interface
{

    /**
     * @var Mage_Epay_Helper_Data
     */
    private $epayHelper;

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('epay/order/view/tab/info.phtml');
        $this->epayHelper = Mage::helper('epay');
    }

    private function getOrder()
    {
        return Mage::registry('current_order');
    }

    private function isRemoteInterfaceEnabled()
    {
        $order = $this->getOrder();
        return intval($order->getPayment()->getMethodInstance()->getConfigData('remoteinterface', $order->getStoreId())) === 1;
    }

    public function getPaymentInformationHtml()
    {
        if (!$this->isRemoteInterfaceEnabled()) {
            return $this->epayHelper->__("Please enable remote payment processing from the module configuration");
        }

        /** @var Mage_Sales_Model_Order */
        $order = $this->getOrder();
        $currencyCode = $order->getBaseCurrencyCode();
        $minorunits = $this->epayHelper->getCurrencyMinorunits($currencyCode);

        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $row = $read->fetchRow("select * from epay_order_status where orderid = '" . $order->getIncrementId() . "'");
        if ($row['status'] == '1') {
            $method = Mage::getModel('epay/standard');

            $res = "<table border='0' width='100%'>";
            $res .= "<tr><td colspan='2'><b>" . $this->epayHelper->__("Payment information") . "</b></td></tr>";
            if ($row['tid'] != '0') {
                $res .= "<tr><td width='150'>" . $this->epayHelper->__("Transaction ID") . ":</td>";
                $res .= "<td>" . $row['tid'] . "</td></tr>";
            }
            //
            if ($row['amount'] != '0') {
                $res .= "<tr><td>" . $this->epayHelper->__("Amount") . ":</td>";
                $amount = $this->epayHelper->convertPriceFromMinorunits((int)$row['amount'], $minorunits);
                $res .= "<td>" . Mage::helper('core')->currency($amount, true, false) . "</td></tr>";
            }

            if ($row['cur'] != '0') {
                $res .= "<tr><td>" . $this->epayHelper->__("Currency code") . ":</td>";
                $res .= "<td>" . $row['cur'] . "</td></tr>";
            }

            if ($row['date'] != '0') {
                $res .= "<tr><td>" . $this->epayHelper->__("Transaction date") . ":</td>";
                $res .= "<td>" . $row['date'] . "</td></tr>";
            }

            if ($row['eKey'] != '0') {
                $res .= "<tr><td>" . $this->epayHelper->__("MD5 hash") . ":</td>";
                $res .= "<td>" . $row['eKey'] . "</td></tr>";
            }

            if ($row['fraud'] != '0') {
                $res .= "<tr><td>" . $this->epayHelper->__("Fraud control") . ":</td>";
                $res .= "<td>" . $this->epayHelper->__("Possible fraud have been detected"). "</td></tr>";
            }

            if ($row['subscriptionid'] != '0') {
                $res .= "<tr><td>" . $this->epayHelper->__("Subscription ID") . ":</td>";
                $res .= "<td>" . $row['subscriptionid'] . "</td></tr>";
            }

            if ($row['cardid'] != '0') {
                $res .= "<tr><td>" . $this->epayHelper->__("Card type") . ":</td>";
                $res .= "<td>". $method->calcCardtype($row['cardid']) . $this->getPaymentLogoUrl($row['cardid']) . "</td></tr>";
            }

            if (strlen($row['cardnopostfix']) != 0) {
                $res .= "<tr><td>" . $this->epayHelper->__("Card number") . ":</td>";
                $res .= "<td>" . $row['cardnopostfix'] . "</td></tr>";
            }

            if ($row['transfee'] != '0') {
                $res .= "<tr><td>" . $this->epayHelper->__("Surcharge fee") . ":</td>";
                $surcharge = $this->epayHelper->convertPriceFromMinorunits((int)$row['transfee'], $minorunits);
                $res .= "<td>" . Mage::helper('core')->currency($surcharge, true, false) . "</td></tr>";
            }

            if ($method->getConfigData('remoteinterface', $order ? $order->getStoreId() : null) == 1) {
                $res .= $this->getTransactionStatus($row['tid'], $row['fraud'], $method, $order, $minorunits);
            }

            $res .= "</table><br>";

            $res .= "<a href='https://admin.ditonlinebetalingssystem.dk/admin' target='_blank'>" . $this->epayHelper->__("Go to payment system administration and process the transaction") . "</a>";
            $res .= "<br><br>";
        } else {
            $res = $this->getChildHtml('order_payment');
            $res .= "<br>" . $this->epayHelper->__("There is not registered any payment for this order yet!") . "<br>";
        }

        return $res;
    }

    //
    // Translate the current ePay transaction status
    //
    public function translatePaymentStatus($status)
    {
        if (strcmp($status, "PAYMENT_NEW") == 0) {
            return $this->epayHelper->__("New");
        } elseif (strcmp($status, "PAYMENT_CAPTURED") == 0 || strcmp($status, "PAYMENT_EUROLINE_WAIT_CAPTURE") == 0 || strcmp($status, "PAYMENT_EUROLINE_WAIT_CREDIT") == 0) {
            return $this->epayHelper->__("Captured");
        } elseif (strcmp($status, "PAYMENT_DELETED") == 0) {
            return $this->epayHelper->__("Deleted");
        } else {
            return $this->epayHelper->__("Unknown");
        }
    }

    //
    // Retrieves the transaction status from ePay
    //
    public function getTransactionStatus($tid, $fraud, $paymentobj, $order, $minorunits)
    {
        $res = "<tr><td colspan='2'><br><b>" . $this->epayHelper->__("Transaction status") . "</b></td></tr>";
        try {
            $storeId = $order->getStoreId();

            $param = array(
                'merchantnumber' => $paymentobj->getConfigData('merchantnumber', $storeId),
                'transactionid' => $tid,
                'epayresponse' => 0,
                'pwd' =>  $paymentobj->getRemotePassword($storeId)
            );

            $client = new SoapClient('https://ssl.ditonlinebetalingssystem.dk/remote/payment.asmx?WSDL');
            $result = $client->gettransaction($param);

            if ($result->gettransactionResult === true) {
                if ($fraud > 0) {
                    $res .= "<tr><td>" . $this->epayHelper->__("Fraud status") . ":</td>";
                    $res .= "<td>" .$result->transactionInformation->fraudStatus. "</td></tr>";

                    $res .= "<tr><td>" . $this->epayHelper->__("Payer country code") . ":</td>";
                    $res .= "<td>" .$result->transactionInformation->payerCountryCode. "</td></tr>";

                    $res .= "<tr><td>" . $this->epayHelper->__("Issued country code") . ":</td>";
                    $res .= "<td>" .$result->transactionInformation->issuedCountryCode. "</td></tr>";
                    if (isset($result->transactionInformation->FraudMessage)) {
                        $res .= "<tr><td>" . $this->epayHelper->__("Fraud message") . ":</td>";
                        $res .= "<td>" .$result->transactionInformation->FraudMessage. "</td></tr>";
                    }
                }

                $res .= "<tr><td>" . $this->epayHelper->__("Transaction status") . ":</td>";
                $res .= "<td>" . $this->translatePaymentStatus($result->transactionInformation->status) . "</td></tr>";

                if (strcmp($result->transactionInformation->status, "PAYMENT_DELETED") == 0) {
                    $res .= "<tr><td>" . $this->epayHelper->__("Deleted date") . ":</td>";
                    $res .= "<td>" . str_replace("T", " ", $result->transactionInformation->deleteddate) . "</td></tr>";
                }

                $res .= "<tr><td>" . $this->epayHelper->__("Order number") . ":</td>";
                $res .= "<td>" . $result->transactionInformation->orderid . "</td></tr>";

                $res .= "<tr><td>" . $this->epayHelper->__("Acquirer") . ":</td>";
                $res .= "<td>" . $result->transactionInformation->acquirer . "</td></tr>";

                $res .= "<tr><td>" . $this->epayHelper->__("Currency code") . ":</td>";
                $res .= "<td>" . $result->transactionInformation->currency . "</td></tr>";

                $res .= "<tr><td>" . $this->epayHelper->__("Splitpayment") . ":</td>";
                $res .= "<td>" . ($result->transactionInformation->splitpayment ? $this->epayHelper->__("Yes") : $this->epayHelper->__("No")) . "</td></tr>";

                $res .= "<tr><td>" . $this->epayHelper->__("3D Secure") . ":</td>";
                $res .= "<td>" . ($result->transactionInformation->msc ? Mage::helper('epay')->__("Yes") : Mage::helper('epay')->__("No")) . "</td></tr>";

                $res .= "<tr><td>" . $this->epayHelper->__("Description") . ":</td>";
                $res .= "<td>" . $result->transactionInformation->description . "</td></tr>";

                $res .= "<tr><td>" . $this->epayHelper->__("Cardholder") . ":</td>";
                $res .= "<td>" . $result->transactionInformation->cardholder . "</td></tr>";

                $res .= "<tr><td>" . $this->epayHelper->__("Auth amount") . ":</td>";
                $authAmount = $this->epayHelper->convertPriceFromMinorunits((int)$result->transactionInformation->authamount, $minorunits);
                $authDate = $authAmount > 0 ?  Mage::helper('core')->formatDate(str_replace("T", " ", $result->transactionInformation->authdate), Mage_Core_Model_Locale::FORMAT_TYPE_SHORT, true) : "";
                $res .= "<td>" . Mage::helper('core')->currency($authAmount, true, false) . "&nbsp;&nbsp;&nbsp;" . $authDate . "</td></tr>";

                $res .= "<tr><td>" . $this->epayHelper->__("Captured amount") . ":</td>";
                $captureAmount = $this->epayHelper->convertPriceFromMinorunits((int)$result->transactionInformation->capturedamount, $minorunits);
                $captureDate = $captureAmount > 0 ?  Mage::helper('core')->formatDate(str_replace("T", " ", $result->transactionInformation->captureddate), Mage_Core_Model_Locale::FORMAT_TYPE_SHORT, true) : "";
                $res .= "<td>" . Mage::helper('core')->currency($captureAmount, true, false) . "&nbsp;&nbsp;&nbsp;" . $captureDate . "</td></tr>";

                $res .= "<tr><td>" . $this->epayHelper->__("Credited amount") . ":</td>";
                $creditedAmount = $this->epayHelper->convertPriceFromMinorunits((int)$result->transactionInformation->creditedamount, $minorunits);
                $creditedDate = $creditedAmount > 0 ?  Mage::helper('core')->formatDate(str_replace("T", " ", $result->transactionInformation->crediteddate), Mage_Core_Model_Locale::FORMAT_TYPE_SHORT, true) : "";
                $res .= "<td>" . Mage::helper('core')->currency($creditedAmount, true, false) . "&nbsp;&nbsp;&nbsp;" . $creditedDate . "</td></tr>";

                if (isset($result->transactionInformation->history) && isset($result->transactionInformation->history->TransactionHistoryInfo) && count($result->transactionInformation->history->TransactionHistoryInfo) > 0) {
                    //
                    // Important to convert this item to array. If only one item is to be found in the array of history items
                    // the object will be handled as non-array but object only.
                    $historyArray = $result->transactionInformation->history->TransactionHistoryInfo;
                    if (count($result->transactionInformation->history->TransactionHistoryInfo) == 1) {
                        $historyArray = array($result->transactionInformation->history->TransactionHistoryInfo);
                        // convert to array
                    }

                    $res .= "<tr><td colspan='2'><br><br><b>" . $this->epayHelper->__("History") . "</b></td></tr>";
                    for ($i = 0; $i < count($historyArray); $i++) {
                        $res .= "<tr><td>" . str_replace("T", " ", $historyArray[$i]->created) . "</td>";
                        $res .= "<td>";
                        if (strlen($historyArray[$i]->username) > 0) {
                            $res .= ($historyArray[$i]->username . ": ");
                        }

                        $res .= $historyArray[$i]->eventMsg . "</td></tr>";
                    }
                }
            } else {
                if ($result->epayresponse != -1) {
                    if ($result->epayresponse == -1019) {
                        $res .= "<tr><td colspan='2'>" . $this->epayHelper->__("Invalid password used for webservice access!"). "</td>";
                    } else {
                        $res .= "<tr><td colspan='2'>" . $paymentobj->getEpayErrorText($result->epayresponse, $storeId) . "</td>";
                    }
                } else {
                    $res .= "<tr><td colspan='2'>" . $this->epayHelper->__("Unknown error!") . "</td>";
                }
            }
        } catch (Exception $e) {
            $res .= "<tr><td colspan='2'>" . $this->epayHelper->__("An error occured in the communication to the payment system") ." - ". $e->getMessage(). "</td>";
        }

        return $res;
    }

    /**
     * Create html for paymentLogoUrl
     *
     * @param mixed $paymentId
     * @return string
     */
    private function getPaymentLogoUrl($paymentId)
    {
        return '<img class="epay_paymentcard" src="https://d25dqh6gpkyuw6.cloudfront.net/paymentlogos/external/'.$paymentId . '.png"';
    }

    /**
     * ######################## TAB settings #################################
     */
    public function getTabLabel()
    {
        return 'Bambora Online ePay';
    }
    public function getTabTitle()
    {
        return "Bambora Online ePay";
    }
    public function canShowTab()
    {
        /** @var Mage_Sales_Model_Order_Payment $payment */
        $payment = $this->getOrder()->getPayment();
        if ($payment->getMethod() === 'epay_standard' && $this->isRemoteInterfaceEnabled()) {
            return true;
        }

        return false;
    }
    public function isHidden()
    {
        return false;
    }
}
