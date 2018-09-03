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

$installer = $this;

$installer->startSetup();

$installer->run(
    "
    CREATE TABLE if not exists epay_order_status (
      `orderid` VARCHAR(45) NOT NULL,
      `tid` VARCHAR(45) NOT NULL,
      `status` INTEGER UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = unpaid, 1 = paid',
      `amount` VARCHAR(45) NOT NULL,
      `cur` VARCHAR(45) NOT NULL,
      `date` VARCHAR(45) NOT NULL,
      `eKey` VARCHAR(45) NOT NULL,
      `fraud` VARCHAR(45) NOT NULL,
      `subscriptionid` VARCHAR(45) NOT NULL,
      `cardid` VARCHAR(45) NOT NULL,
      `transfee` VARCHAR(45) NOT NULL,
      `cardnopostfix` VARCHAR(45) NOT NULL
    );
"
);

$installer->run(
    "
    CREATE TABLE if not exists `paymentrequest` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `orderid` varchar(20) DEFAULT NULL,
      `currency_code` char(3) DEFAULT NULL,
      `amount` int(11) DEFAULT NULL,
      `receiver` varchar(255) DEFAULT NULL,
      `ispaid` tinyint(4) NOT NULL DEFAULT '0',
      `status` int(11) NOT NULL DEFAULT '0',
      `paymentrequestid` bigint(20) DEFAULT NULL,
      `created` timestamp NULL DEFAULT NULL,
      PRIMARY KEY (`id`)
    );
"
);

$installer->run(
    "
        DELETE FROM ".$installer->getTable('core/config_data')." WHERE path = 'payment/epay_standard/group';
    "
);

$installer->endSetup();
