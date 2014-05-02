<?php

class ModelPaymentCoinapult extends Model
{
  public function getMethod($address, $total) {
    $this->load->language('payment/coinapult');

    $method_data = array();
    if ($this->config->get('coinapult_status')) {
      $method_data['code'] = 'coinapult';
      $method_data['title'] = $this->language->get('text_title');
      $method_data['sort_order'] = $this->config->get('coinapult_sort_order');
    }

    return $method_data;
  }
}

?>
