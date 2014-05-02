<?php

class ControllerPaymentCoinapult extends Controller
{

  private $error = array();

  public function index() {
    $this->language->load('payment/coinapult');
    $this->document->setTitle($this->language->get('heading_title'));
    $this->load->model('setting/setting');

    if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
      $this->model_setting_setting->editSetting('coinapult', $this->request->post);
      $this->session->data['success'] = $this->language->get('text_success');
      $this->redirect($this->url->link('extension/payment', 'token=' . $this->session->data['token'], 'SSL'));
    }

    $this->data['heading_title'] = $this->language->get('heading_title');

    $this->data['text_enabled'] = $this->language->get('text_enabled');
    $this->data['text_disabled'] = $this->language->get('text_disabled');
    $this->data['text_yes'] = $this->language->get('text_yes');
    $this->data['text_no'] = $this->language->get('text_no');
    $this->data['button_save'] = $this->language->get('button_save');
    $this->data['button_cancel'] = $this->language->get('button_cancel');

    $this->data['entry_order_status_pending'] = $this->language->get('entry_order_status_pending');
    $this->data['entry_order_status_received'] = $this->language->get('entry_order_status_received');
    $this->data['entry_status'] = $this->language->get('entry_status');
    $this->data['entry_api_key'] = $this->language->get('entry_api_key');
    $this->data['entry_api_secret'] = $this->language->get('entry_api_secret');
    $this->data['entry_sort_order'] = $this->language->get('entry_sort_order');

    if (isset($this->error['warning'])) {
      $this->data['error_warning'] = $this->error['warning'];
    } else {
      $this->data['error_warning'] = '';
    }
    if (isset($this->error['api_key'])) {
      $this->data['error_api_key'] = $this->error['api_key'];
    } else {
      $this->data['error_api_key'] = '';
    }
    if (isset($this->error['api_secret'])) {
      $this->data['error_api_secret'] = $this->error['api_secret'];
    } else {
      $this->data['error_api_secret'] = '';
    }

    $this->data['breadcrumbs'] = array();
    $this->data['breadcrumbs'][] = array(
      'href'      => $this->url->link('common/home', 'token=' . $this->session->data['token'], 'SSL'),
      'text'      => $this->language->get('text_home'),
      'separator' => false
    );
    $this->data['breadcrumbs'][] = array(
      'href'      => $this->url->link('extension/payment', 'token=' . $this->session->data['token'], 'SSL'),
      'text'      => $this->language->get('text_payment'),
      'separator' => ' :: '
    );
    $this->data['breadcrumbs'][] = array(
      'href'      => $this->url->link('payment/coinapult', 'token=' . $this->session->data['token'], 'SSL'),
      'text'      => $this->language->get('heading_title'),
      'separator' => ' :: '
    );

    $this->data['action'] = $this->url->link('payment/coinapult', 'token=' . $this->session->data['token'], 'SSL');
    $this->data['cancel'] = $this->url->link('extension/payment', 'token=' . $this->session->data['token'], 'SSL');

    if (isset($this->request->post['coinapult_api_key'])) {
      $this->data['coinapult_api_key'] = $this->request->post['coinapult_api_key'];
    } else {
      $this->data['coinapult_api_key'] = $this->config->get('coinapult_api_key');
    }

    if (isset($this->request->post['coinapult_api_secret'])) {
      $this->data['coinapult_api_secret'] = $this->request->post['coinapult_api_secret'];
    } else {
      $this->data['coinapult_api_secret'] = $this->config->get('coinapult_api_secret');
    }

    if (isset($this->request->post['coinapult_order_status_id_pending'])) {
      $this->data['coinapult_order_status_id_pending'] = $this->request->post['coinapult_order_status_id_pending'];
    } else {
      $this->data['coinapult_order_status_id_pending'] = $this->config->get('coinapult_order_status_id_pending');
    }
    if (isset($this->request->post['coinapult_order_status_id_received'])) {
      $this->data['coinapult_order_status_id_received'] = $this->request->post['coinapult_order_status_id_received'];
    } else {
      $this->data['coinapult_order_status_id_received'] = $this->config->get('coinapult_order_status_id_received');
    }

    $this->load->model('localisation/order_status');

    $this->data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

    if (isset($this->request->post['coinapult_status'])) {
      $this->data['coinapult_status'] = $this->request->post['coinapult_status'];
    } else {
      $this->data['coinapult_status'] = $this->config->get('coinapult_status');
    }

    if (isset($this->request->post['coinapult_sort_order'])) {
      $this->data['coinapult_sort_order'] = $this->request->post['coinapult_sort_order'];
    } else {
      $this->data['coinapult_sort_order'] = $this->config->get('coinapult_sort_order');
    }

    $this->template = 'payment/coinapult.tpl';
    $this->children = array(
      'common/header',
      'common/footer'
    );

    $this->response->setOutput($this->render());
  }

  private function validate() {
    if (!$this->user->hasPermission('modify', 'payment/coinapult')) {
      $this->error['warning'] = $this->language->get('error_permission');
    }

    if (!$this->request->post['coinapult_api_key']) {
      $this->error['api_key'] = $this->language->get('error_api_key');
    }

    if (!$this->request->post['coinapult_api_secret']) {
      $this->error['api_secret'] = $this->language->get('error_api_secret');
    }

    return !$this->error;
  }


  public function install() {
    /* Table for associating an order id to a transaction_id received from Coinapult.
     * This is used when receiving callbacks to confirm payments. */
    $log = new Log('coinapult.log');
    $log->write('Running install...');
    $query = $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "order_bitcoin_coinapult` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `order_id` INT(11) NOT NULL,
      `transaction_id` VARCHAR(32) NOT NULL,
      PRIMARY KEY(`id`)
      );");
    $log->write("Install completed.");
  }

}

?>
