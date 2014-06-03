<?php


class Coinapult
{
  const DEBUG = FALSE;

  /* Valid keys while searching for transactions. */
  private static $SEARCH_CRITERIA = array('transaction_id', 'type',
    'currency', 'to', 'from', 'extOID', 'txhash');

  private $_base_url = 'https://api.coinapult.com/api/';

  private $_api_key = null;
  private $_api_secret = null;

  public function __construct($api_key, $api_secret, $base_url=NULL)
  {
    $this->_api_key = $api_key;
    $this->_api_secret = $api_secret;
    if (!is_null($base_url)) {
      $this->_base_url = $base_url;
    }
  }

  /* Make a call to the Coinapult API. */
  private function request($method, $params, $sign=TRUE, $post=TRUE) {
    $headers = array();
    if ($sign) {
      $params['nonce'] = gen_nonce();
      $params['timestamp'] = (string)time();
      $params['endpoint'] = '/' . $method;
      $headers[] = 'cpt-key: ' . $this->_api_key;
      $signdata = base64_encode(json_encode($params));
      $headers[] = 'cpt-hmac: ' . hash_hmac("sha512", $signdata, $this->_api_secret);
      $data = array("data" => $signdata);
      $params_str = http_build_query($data, '', '&');
    } else {
      $params_str = http_build_query($params, '', '&');
    }

    $handle = curl_init();
    if (Coinapult::DEBUG) {
      curl_setopt($handle, CURLOPT_VERBOSE, TRUE);
    }
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($handle, CURLOPT_URL, $this->_base_url . $method);
    curl_setopt($handle, CURLOPT_POSTFIELDS, $params_str);
    curl_setopt($handle, CURLOPT_POST, $post);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);
    $result = curl_exec($handle);
    if (curl_errno($handle)) {
      throw new Exception(curl_error($handle));
    }
    curl_close($handle);

    $data = json_decode($result, true);
    return $data;
  }

  /* Coinapult API. */

  public function ticker($begin=NULL, $end=NULL) {
    $params = array();
    if (!is_null($begin)) {
      $params['begin'] = $begin;
    }
    if (!is_null($end)) {
      $params['end'] = $end;
    }

    return $this->request('ticker', $params, $sign=FALSE, $post=FALSE);
  }

  public function account_info() {
    return $this->request('accountInfo', array());
  }

  public function get_bitcoin_address() {
    return $this->request('getBitcoinAddress', array());
  }

  public function send($amount, $address, $currency='BTC', $extOID=NULL, $callback=NULL) {
    $params = array(
      'amount'   => $amount,
      'address'  => $address,
      'currency' => $currency
    );
    if (!is_null($callback)) {
      $params['callback'] = $callback;
    }
    if (!is_null($extOID)) {
      $params['extOID'] = $extOID;
    }
    return $this->request('t/send', $params);
  }

  public function receive($amount, $inCurrency='BTC', $outAmount=NULL,
    $outCurrency=NULL, $extOID=NULL, $callback=NULL, $address=NULL) {

      $params = array();
      if (!is_null($amount)) {
        $params['amount'] = $amount;
      }

      if (is_null($inCurrency)) {
        $params['currency'] = 'BTC';
      } else {
        $params['currency'] = $inCurrency;
      }

      if (!is_null($outAmount)) {
        $params['outAmount'] = "$outAmount";
      }
      if (!is_null($outCurrency)) {
        $params['outCurrency'] = $outCurrency;
      }
      if (!is_null($extOID)) {
        $params['extOID'] = "$extOID";
      }
      if (!is_null($callback)) {
        $params['callback'] = "$callback";
      }
      if (!is_null($address)) {
        $params['address'] = "$address";
      }

      return $this->request('t/receive', $params);
    }

  public function search($criteria, $many=false, $page=NULL) {

    $params = array();
    foreach ($criteria as $key => $val) {
      if (in_array($key, Coinapult::$SEARCH_CRITERIA)) {
        $params[$key] = $val;
      } else {
        throw new Exception("Invalid search criteria '$key'");
      }
    }

    if (!count($params)) {
      throw new Exception("Empty search criteria");
    }

    if ($many) {
      $params['many'] = '1';
    }
    if (!is_null($page)) {
      $params['page'] = $page;
    }

    return $this->request('t/search', $params);
  }

  public function convert($amount, $inCurrency='BTC', $outCurrency=NULL, $callback=NULL) {

    $params = array(
      'amount'	 => $amount,
      'inCurrency' => $inCurrency
    );
    if (!is_null($outCurrency)) {
      $params['outCurrency'] = $outCurrency;
    }
    if (!is_null($callback)) {
      $params['callback'] = $callback;
    }

    return $this->request('t/convert', $params);
  }


  /* Helpers. */
  public function authenticate_callback($recv_key, $recv_hmac, $recv_data) {
    $res = array();
    $res['auth'] = FALSE;
    $res['hmac'] = '';
    if (!(strcmp($recv_key, $this->_api_key))) {
      /* API key matches. */
      $res['hmac'] = hash_hmac("sha512", $recv_data, $this->_api_secret);
      if (!(strcasecmp($res['hmac'], $recv_hmac))) {
        /* Received HMAC matches. */
        $res['auth'] = TRUE;
      }
    }
    return $res;
  }

} /* Coinapult class. */


/* Auxiliary function for sending signed requests to Coinapult. */
function gen_nonce($length=22) {
  $b58 = "123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz";
  $nonce = '';
  for ($i = 0; $i < $length; $i++) {
    $char = $b58[mt_rand(0, 57)];
    $nonce = $nonce . $char;
  }
  return $nonce;
}


?>
