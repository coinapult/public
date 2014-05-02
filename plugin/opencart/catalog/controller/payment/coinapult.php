<?php

class ControllerPaymentCoinapult extends Controller
{

  protected function index() {
    $this->language->load('payment/coinapult');
    $this->data['button_confirm_bitcoin'] = $this->language->get('button_confirm_bitcoin');
    $this->data['text_wait'] = $this->language->get('text_wait');
    $this->data['continue'] = $this->url->link('checkout/success');

    if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/coinapult.tpl')) {
      $this->template = $this->config->get('config_template') . '/template/payment/coinapult.tpl';
    } else {
      $this->template = 'default/template/payment/coinapult.tpl';
    }
    $this->render();
  }

  public function send() {
    /* Create invoice. */

    require DIR_APPLICATION . '../coinapult/coinapult.php';

    $this->load->model('checkout/order');

    $orderid = $this->session->data['order_id'];
    $order = $this->model_checkout_order->getOrder($orderid);
    $data = array(
      'callback'            => $this->url->link('payment/coinapult/callback'),
      /* Order data. */
      'transaction_amount'  => $this->currency->format($order['total'], $order['currency_code'], $order['currency_value'], FALSE),
      'currency_code'       => $order['currency_code']
    );

    $log = new Log('coinapult.log');

    $coinapult = new Coinapult(
      $this->config->get('coinapult_api_key'),
      $this->config->get('coinapult_api_secret')
    );
    $response = $coinapult->receive(null, 'BTC',
      $data['transaction_amount'], $data['currency_code'],
      null, $data['callback']);
    $log->write('Coinapult invoice response: ' . print_r($response, TRUE));

    $json = array();
    $tid = $response['transaction_id'];
    if (is_null($tid) || $response['state'] == 'canceled') {
      $json['error'] = 'Failed to create the invoice';
      $log->write("failed :/");
    } else {

      /* Associate the orderid to the transaction_id received from Coinapult. */
      $sql = "INSERT INTO `" . DB_PREFIX . "order_bitcoin_coinapult`
        (`order_id`, `transaction_id`) VALUES ('$orderid', '$tid');";
      $this->db->query($sql);

      $message = "To finalize your order...\n";
      $message .= "Send <b>" . $response['in']['expected'] . "</b>btc to the following Bitcoin address:\n";
      $message .= "<b>" . $response['address'] . "</b>\n\n";
      $message .= "Once the payment is received, your order will be complete.\n\n";
      $message .= "Coinapult Order ID is: " . $tid . "\n";

      $this->model_checkout_order->confirm(
        $orderid,
        $this->config->get('coinapult_order_status_id_pending'),
        $message,
        true
      );

      $newurl = $this->url->link('account/order/info&order_id=' . $order['order_id']);
      $json['success'] = $newurl;

      $this->cart->clear();
    }

    $this->response->setOutput(json_encode($json));
  }

  public function callback() {
    /* Validate the received callback to confirm (or not) the payment. */
    require DIR_APPLICATION . '../coinapult/coinapult.php';

    $log = new Log('coinapult.log');
    $log->write('callback!');
    $log->write(print_r($_POST, TRUE));

    $coinapult = new Coinapult(
      $this->config->get('coinapult_api_key'),
      $this->config->get('coinapult_api_secret')
    );
    if (!(isset($_SERVER['HTTP_CPT_KEY']) && isset($_SERVER['HTTP_CPT_HMAC']))) {
      /* Invalid callback. */
      $log->write('Callback: basic headers missing.');
      return;
    }
    $auth = $coinapult->authenticate_callback(
      $_SERVER['HTTP_CPT_KEY'],
      $_SERVER['HTTP_CPT_HMAC'],
      $_POST
    );
    if (!$auth['auth']) {
      $log->write('Callback: failed to authenticate! ' . print_r($_SERVER, TRUE));
      $log->write('Auth result: ' . print_r($auth));
      return;
    }
    if (!(isset($_POST['transaction_id']))) {
      $log->write('Callback missing transaction id, ignored.');
      return;
    }

    $this->load->model('checkout/order');

    $tid = $_POST['transaction_id'];
    $sql = "SELECT `order_id` FROM `" . DB_PREFIX . "order_bitcoin_coinapult`
      WHERE `transaction_id` = '$tid';";
    $result = $this->db->query($sql);
    if (!$result->num_rows) {
      $log->write("No order found for tid = $tid");
      return;
    }
    $orderid = $result->row['order_id'];
    $log->write("SQL result: " . print_r($result, TRUE));

    $transaction = $coinapult->search(array("transaction_id" => $tid));
    if ($transaction['transaction_id'] != $tid) {
      $log->write('Transaction ID does not match, how did that happen?');
      return;
    }

    $this->language->load('payment/coinapult');

    if ($transaction['state'] == 'complete') {
      /* Invoice got paid. */
      $message = "Received " . $transaction['in']['amount'] . "btc\n";
      $log->write("Order $orderid: " . $message);

      $this->model_checkout_order->update(
        $orderid,
        $this->config->get('coinapult_order_status_id_received'),
        $message,
        true
      );
    } elseif($transaction['state'] == 'canceled') {
      $message = "Insufficient payment. Received " . $transaction['in']['amount'] . "btc. Expected " . $transaction['in']['expected'] . "btc.";
      $log->write("Order $orderid: " . $message);

      $this->model_checkout_order->update(
        $orderid,
        $this->config->get('coinapult_order_status_id_pending'),
        $message,
        true
      );
    } else {
      $log->write('Unexpected transaction status: ' . $transaction['state']);
    }
  }

}

?>
