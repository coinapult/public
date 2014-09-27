<?php

require_once(__DIR__ . '/../../coinapult.php');

/* Replace by your key pair. */
$API = array(
  'key' => '20a79976c8c1de9111073d40c6a429',
  'secret' => '1965f49326270e3201848860060fd9e714724a60778a5d1ff7197be8429c'
);

$client = new Coinapult($API['key'], $API['secret']);

$result = $client->account_info();
print_r($result);

?>
