<?php
/*
 * Coinapult Payment Module
 *
 * Author: Guilherme Polo (gp@coinapult.com)
 */

define('MODULE_PAYMENT_COINAPULT_TEXT_TITLE', 'Bitcoin');
define('MODULE_PAYMENT_COINAPULT_TEXT_DESCRIPTION', 'Accept bitcoin payments');

define('MODULE_PAYMENT_COINAPULT_TEXT_USER_DESCRIPTION', 'After confirming the order, you have 7 minutes to make the bitcoin payment to the address given in the next step.');

define('MODULE_PAYMENT_COINAPULT_TEXT_CHECKOUT_SUCCESS_HTML',
  "<p>To pay for your order, send <b>%in_expected%</b> BTC to the following Bitcoin address: <b>%btc_address%</b><br/><br/>Or <a href='https://coinapult.com/invoice/%tid%' target='_blank'>click here</a> to view this invoice on Coinapult.<br/></p>");
define('MODULE_PAYMENT_COINAPULT_TEXT_CHECKOUT_SUCCESS',
  "To pay for your order, send \n%in_expected%\n BTC to the following Bitcoin address: %btc_address%\n\nOr <a href='https://coinapult.com/invoice/%tid%' target='_blank'>click here</a> to view this invoice on Coinapult.");

define('MODULE_PAYMENT_COINAPULT_TEXT_ERROR', 'Failed to process the payment using bitcoins');
define('MODULE_PAYMENT_COINAPULT_TEXT_ERROR_INVOICEFAIL', 'Bitcoin payment order could not be created, contact the store');

?>
