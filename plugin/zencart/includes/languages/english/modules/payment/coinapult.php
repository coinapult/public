<?php
/*
 * Coinapult Payment Module
 *
 * Author: Guilherme Polo (gp@coinapult.com)
 */

define('MODULE_PAYMENT_COINAPULT_TEXT_TITLE', 'Bitcoin');
define('MODULE_PAYMENT_COINAPULT_TEXT_DESCRIPTION', 'Accept bitcoin payments');

define('MODULE_PAYMENT_COINAPULT_TEXT_USER_DESCRIPTION', 'After confirming the order, you have 15 minutes to make the bitcoin payment to the address given in the next step.');

define('MODULE_PAYMENT_COINAPULT_TEXT_CHECKOUT_SUCCESS_HTML',
  "<p>To pay for your order, send <b>%in_expected%</b>btc to the Bitcoin address <b>%btc_address%</b><br/>Payment ID: %tid%<br/></p>");
define('MODULE_PAYMENT_COINAPULT_TEXT_CHECKOUT_SUCCESS',
  "To pay for your order...\nSend %in_expected%btc to the Bitcoin address %btc_address%\n\nPayment ID: %tid%\n");

define('MODULE_PAYMENT_COINAPULT_TEXT_ERROR', 'Failed to process the payment using bitcoins');
define('MODULE_PAYMENT_COINAPULT_TEXT_ERROR_INVOICEFAIL', 'Bitcoin payment order could not be created, contact the store');

?>
