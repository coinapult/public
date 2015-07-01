<?php
/*
 * Coinapult Payment Module
 *
 * Author: Guilherme Polo (gp@coinapult.com)
 */

if (!defined('TABLE_COINAPULT_LINK')) {
  /* Associate orders with tid from coinapult so we can search for that
   * in callbacks. */
  define('TABLE_COINAPULT_LINK', DB_PREFIX . 'coinapult_link');
}

class coinapult
{

  var $code, $title, $description, $enabled;
  var $_logDir;

  function _log($msg) {
      $file = $this->_logDir . '/bitcoin_coinapult.log';
      $fp = @fopen($file, 'a');
      @fwrite($fp, $msg . "\n");
      @fclose($fp);
  }

  /* constructor */
  function coinapult() {
    global $order;

    $this->code = 'coinapult';
    $this->title = MODULE_PAYMENT_COINAPULT_TEXT_TITLE;
    $this->description = MODULE_PAYMENT_COINAPULT_TEXT_DESCRIPTION;
    $this->sort_order = MODULE_PAYMENT_COINAPULT_SORT_ORDER;
    $this->enabled = (MODULE_PAYMENT_COINAPULT_STATUS == 'True') ? true : false;

    if (IS_ADMIN_FLAG === true) {
      if (!MODULE_PAYMENT_COINAPULT_API_KEY || !strlen(MODULE_PAYMENT_COINAPULT_API_KEY) ||
          !MODULE_PAYMENT_COINAPULT_API_SECRET || !strlen(MODULE_PAYMENT_COINAPULT_API_SECRET)) {
        $this->title .= '<span class="alert"><strong> MISSING API CREDENTIALS</strong></span>';
      }
    }

    if ((int)MODULE_PAYMENT_COINAPULT_ORDERNEW_ID > 0) {
      $this->order_status = MODULE_PAYMENT_COINAPULT_ORDERNEW_ID;
    }

    if (is_object($order)) {
      $this->update_status();
    }

    $this->_logDir = defined('DIR_FS_LOGS') ? DIR_FS_LOGS : DIR_FS_SQL_CACHE;

  }

  function update_status() {
    global $order, $db;

    if (!MODULE_PAYMENT_COINAPULT_API_KEY || !strlen(MODULE_PAYMENT_COINAPULT_API_KEY) ||
        !MODULE_PAYMENT_COINAPULT_API_SECRET || !strlen(MODULE_PAYMENT_COINAPULT_API_SECRET)) {
      /* Missing API credentials. */
      $this->enabled = false;
      return;
    }

    if (($this->enabled == true) && ((int)MODULE_PAYMENT_COINAPULT_ZONE > 0)) {
      $check_flag = false;
      $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_COINAPULT_ZONE . "' and zone_country_id = '" . $order->delivery['country']['id'] . "' order by zone_id");
      while (!$check->EOF) {
        if ($check->fields['zone_id'] < 1) {
          $check_flag = true;
          break;
        } elseif ($check->fields['zone_id'] == $order->delivery['zone_id']) {
          $check_flag = true;
          break;
        }
        $check->MoveNext();
      }

      if ($check_flag == false) {
        $this->enabled = false;
      }
    }
  }

  function javascript_validation() {
    return false;
  }

  /* Payment method selection. */
  function selection() {
    return array('id' => $this->code, 'module' => $this->title);
  }

  function pre_confirmation_check() {
    return false;
  }

  /* Order confirmation. */
  function confirmation() {
    return array('title' => MODULE_PAYMENT_COINAPULT_TEXT_USER_DESCRIPTION);
  }

  /* after the above confirmation. */
  function process_button() {
    return false;
  }

  /* confirm clicked. */
  function before_process() {
    return false;
  }

  function after_process() {
    require_once 'coinapult/coinapult.php';
    global $insert_id, $order, $db, $messageStack;

    $this->_log("after_process");

    $coinapult = new CoinapultClient(
      MODULE_PAYMENT_COINAPULT_API_KEY,
      MODULE_PAYMENT_COINAPULT_API_SECRET
    );
    $response = $coinapult->receive(null, 'BTC',
      $order->info['total'], $order->info['currency'],
      null,
      zen_href_link('bitcoin_coinapult_callback.php', '', 'NONSSL', true, true, true));

    $this->_log("response from coinapult: " . print_r($response, true));

    if (!isset($response['transaction_id']) || is_null($response['transaction_id']) || isset($response['error'])) {
      /* Invoice not created. */
      $this->_log("failed");
      $messageStack->add_session('checkout_payment', MODULE_PAYMENT_COINAPULT_TEXT_ERROR_INVOICEFAIL, 'error');
      zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'NONSSL', true, false));

    } else {
      $this->_log("transaction started at coinapult");
      /* Set status for the new order. */
      $db->Execute("UPDATE " . TABLE_ORDERS . " SET orders_status = " .
        (int)MODULE_PAYMENT_COINAPULT_ORDERNEW_ID . " WHERE orders_id = " . (int)$insert_id);

      /* Store $tid. */
      $tid = $response['transaction_id'];
      $db->Execute("INSERT INTO " . TABLE_COINAPULT_LINK . " (`order_id`,
        `transaction_id`) VALUES ('$insert_id', '$tid')");

      /* Include payment data. */
      $btc_address = $response['address'];
      $in_expected = $response['in']['expected'];
      $replacements = array('%btc_address%' => $btc_address, '%in_expected%' => $in_expected, '%tid%' => $tid);
      $_SESSION['payment_method_messages'] = str_replace(
        array_keys($replacements), $replacements,
        MODULE_PAYMENT_COINAPULT_TEXT_CHECKOUT_SUCCESS_HTML);

      $comment = str_replace(
        array_keys($replacements), $replacements,
        MODULE_PAYMENT_COINAPULT_TEXT_CHECKOUT_SUCCESS);
      $sql = "INSERT INTO " . TABLE_ORDERS_STATUS_HISTORY . " (comments, orders_id,
        orders_status_id, customer_notified, date_added) VALUES (:orderComments,
        :orderID, :orderStatus, 0, now())";
      $sql = $db->bindVars($sql, ':orderComments', $comment, 'string');
      $sql = $db->bindVars($sql, ':orderID', $insert_id, 'integer');
      $sql = $db->bindVars($sql, ':orderStatus', $order->info['order_status'], 'integer');
      $db->Execute($sql);

      $_SESSION['cart']->reset(true);
      $this->_log("redirecting..");
      zen_redirect("https://coinapult.com/invoice/" . $tid);
    }

    return false;

  }

  function get_error() {
    $this->_log("get_error called");
    $error = array('title' => MODULE_PAYMENT_COINAPULT_TEXT_ERROR,
                   'error' => stripslashes(urldecode($_GET['error'])));
    return $error;
//    return false;
  }


  function check() {
    global $db;

    if (!isset($this->_check)) {
      $check_query = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_COINAPULT_STATUS'");
      $this->_check = $check_query->RecordCount();
    }
    return $this->_check;
  }

  function install() {
    global $db, $messageStack, $sniffer;

    if (defined('MODULE_PAYMENT_COINAPULT_STATUS')) {
      $messageStack->add_session('Coinapult Payment Module already installed.', 'error');
      zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=coinapult', 'NONSSL'));
      return 'failed';
    }

    if (!$sniffer->table_exists(TABLE_COINAPULT_LINK)) {
      $db->Execute("CREATE TABLE " . TABLE_COINAPULT_LINK . " (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `order_id` INT(11) NOT NULL,
        `transaction_id` VARCHAR(32) NOT NULL,
        PRIMARY KEY(`id`)
      );");
    }

    /* COINAPULT_STATUS */
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title,
      configuration_key, configuration_value, configuration_description,
      configuration_group_id, sort_order, set_function, date_added) VALUES
      ('Enable Coinapult Payment Module', 'MODULE_PAYMENT_COINAPULT_STATUS', 'True',
      'Do you want to accept Bitcoin payments through Coinapult?', '6', '1',
      'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");

    /* COINAPULT_API_KEY */
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title,
      configuration_key, configuration_value, configuration_description,
      configuration_group_id, sort_order, date_added) VALUES
      ('API Key', 'MODULE_PAYMENT_COINAPULT_API_KEY', '', 'Enter your Coinapult API Key',
      '6', '0', now())");
    /* COINAPULT_API_SECRET */
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title,
      configuration_key, configuration_value, configuration_description,
      configuration_group_id, sort_order, date_added) VALUES
      ('API Secret', 'MODULE_PAYMENT_COINAPULT_API_SECRET', '', 'Enter your Coinapult API Secret',
      '6', '0', now())");

    /* COINAPULT_ZONE: restrict orders to a given country, if desired. */
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title,
      configuration_key, configuration_value, configuration_description,
      configuration_group_id, sort_order, use_function, set_function, date_added) VALUES
      ('Payment zone', 'MODULE_PAYMENT_COINAPULT_ZONE', '0',
      'If a zone is selected, only enable this payment method for that zone.',
      '6', '2', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");

    /* COINAPULT_SORT_ORDER */
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title,
      configuration_key, configuration_value, configuration_description,
      configuration_group_id, sort_order, date_added) VALUES
      ('Sort order of display.', 'MODULE_PAYMENT_COINAPULT_SORT_ORDER', '0',
      'Sort order of display. Lowest is displayed first.', '6', '0', now())");

    /* COINAPULT_ORDERNEW_ID */
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title,
      configuration_key, configuration_value, configuration_description,
      configuration_group_id, sort_order, set_function, use_function, date_added) VALUES
      ('Set new order status', 'MODULE_PAYMENT_COINAPULT_ORDERNEW_ID', '1',
      'Set the status of new orders made with this payment module to this value', '6', '0',
      'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
    /* COINAPULT_ORDERPAID_ID */
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title,
      configuration_key, configuration_value, configuration_description,
      configuration_group_id, sort_order, set_function, use_function, date_added) VALUES
      ('Set paid order tatus', 'MODULE_PAYMENT_COINAPULT_ORDERPAID_ID', '0',
      'Set the status of paid orders made with this payment module to this value', '6', '0',
      'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
  }

  function remove() {
    global $db, $sniffer;

    $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key IN ('" . implode("', '", $this->keys()) . "')");
    if ($sniffer->table_exists(TABLE_COINAPULT_LINK)) {
      $result = $db->Execute("SELECT COUNT(*) FROM " . TABLE_COINAPULT_LINK);
      if ($result->RecordCount() == 0) {
        $db->Execute("DROP TABLE " . TABLE_COINAPULT_LINK);
      }
    }
  }

  function keys() {
    return array(
      'MODULE_PAYMENT_COINAPULT_STATUS',
      'MODULE_PAYMENT_COINAPULT_API_KEY',
      'MODULE_PAYMENT_COINAPULT_API_SECRET',
      'MODULE_PAYMENT_COINAPULT_SORT_ORDER',
      'MODULE_PAYMENT_COINAPULT_ORDERNEW_ID',
      'MODULE_PAYMENT_COINAPULT_ORDERPAID_ID',
      'MODULE_PAYMENT_COINAPULT_ZONE');
  }

}

?>
