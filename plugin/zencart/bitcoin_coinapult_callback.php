<?php
/*
 * Coinapult Payment Module
 *
 * Author: Guilherme Polo (gp@coinapult.com)
 */

require_once 'includes/modules/payment/coinapult/coinapult.php';
require 'includes/application_top.php';

function _log($msg) {
  global $logDir;
  $file = $logDir . '/bitcoin_coinapult.log';
  $fp = @fopen($file, 'a');
  @fwrite($fp, $msg . "\n");
  @fclose($fp);
}
$logDir = defined('DIR_FS_LOGS') ? DIR_FS_LOGS : DIR_FS_SQL_CACHE;

if (!defined('TABLE_COINAPULT_LINK')) {
  /* Associate orders with tid from coinapult so we can search for that
   * in callbacks. */
  define('TABLE_COINAPULT_LINK', DB_PREFIX . 'coinapult_link');
}

$coinapult = new CoinapultClient(
  MODULE_PAYMENT_COINAPULT_API_KEY,
  MODULE_PAYMENT_COINAPULT_API_SECRET
);

if (!(isset($_SERVER['HTTP_CPT_KEY']) && isset($_SERVER['HTTP_CPT_HMAC']))) {
  _log('received invalid callback');
  die();
}

/* Valid callback so far. */
$auth = $coinapult->authenticate_callback(
  $_SERVER['HTTP_CPT_KEY'],
  $_SERVER['HTTP_CPT_HMAC'],
  $_POST
);
if (!($auth['auth'] && isset($_POST['transaction_id']))) {
  _log('failed to authenticate the callback: ' . print_r($auth, true));
  die();
}

$tid = $_POST['transaction_id'];

$result = $db->Execute("SELECT `order_id` FROM " . TABLE_COINAPULT_LINK . "
  WHERE `transaction_id` = '$tid'");

if ($result->RecordCount() == 1) {
  $orderid = $result->fields['order_id'];
  _log('found order ' . $orderid . ' for tid ' . $tid);

  $transaction = $coinapult->search(array("transaction_id" => $tid));
  if ($transaction['transaction_id'] == $tid) {
    if ($transaction['state'] == 'complete') {
      /* Invoice got paid. */
      _log('order paid, updating..');

      $db->Execute("UPDATE " . TABLE_ORDERS . " SET orders_status = " . (int)MODULE_PAYMENT_COINAPULT_ORDERPAID_ID . "
        WHERE orders_id = " . (int)$orderid);

      $comment = "Received " . $transaction['in']['amount'] . "btc\n";
      $sql = "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . " (comments, orders_id,
            orders_status_id, customer_notified, date_added) VALUES (:orderComments,
            :orderID, :orderStatus, 0, now())";
      $sql = $db->bindVars($sql, ':orderComments', $comment, 'string');
      $sql = $db->bindVars($sql, ':orderID', $orderid, 'integer');
      $sql = $db->bindVars($sql, ':orderStatus', MODULE_PAYMENT_COINAPULT_ORDERPAID_ID, 'integer');
      $db->Execute($sql);

      _log('done!');

    } elseif($transaction['state'] == 'canceled') {
      _log('payment canceled, updating..');

      $comment = "Insufficient payment. Received " . $transaction['in']['amount'] . "btc. Expected " . $transaction['in']['expected'] . "btc.";
      $sql = "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . " (comments, orders_id,
            orders_status_id, customer_notified, date_added) VALUES (:orderComments,
            :orderID, :orderStatus, 0, now())";
      $sql = $db->bindVars($sql, ':orderComments', $comment, 'string');
      $sql = $db->bindVars($sql, ':orderID', $orderid, 'integer');
      $sql = $db->bindVars($sql, ':orderStatus', MODULE_PAYMENT_COINAPULT_ORDERNEW_ID, 'integer');

      _log('done!');
    }

  } /* tid matches. */

} /* found order. */

?>
