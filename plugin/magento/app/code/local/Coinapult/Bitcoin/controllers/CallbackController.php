<?php
/*
 * Copyright 2013, 2014 Coinapult
 *
 *  This file is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.

 *  This file is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.

 *  You should have received a copy of the GNU General Public License.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Authors: Ira Miller (ira@coinapult.com)
 *          Guilherme Polo (gp@coinapult.com)
 */

require_once('lib/coinapult/coinapult.php');

class Coinapult_Bitcoin_CallbackController extends Mage_Core_Controller_Front_Action {
	public function indexAction() {
        $key = Mage::getStoreConfig('payment/bitcoin/coinapult_key');
    		$secret = Mage::getStoreConfig('payment/bitcoin/coinapult_secret');
		    $coinapult = new Coinapult($key, $secret);
    		$auth = $coinapult->authenticate_callback(
		    	  $_SERVER['HTTP_CPT_KEY'], $_SERVER['HTTP_CPT_HMAC'], $_POST);
    		if (!$auth['auth']) {
		      	Mage::log('Callback: failed to authenticate! ' . print_r($_SERVER, TRUE),
				        null, 'coinapult.log');
			      exit;
		    }

		    /* Lookup transaction. */
		    $response = $coinapult->search(array("transaction_id" => $_POST['transaction_id']));
	    	Mage::log('Search result: ' . print_r($response, TRUE), null, 'coinapult.log');

        if(get_class($response) == "payError") {
            exit;
        } elseif($response['state'] == 'complete') {
            $write = Mage::getSingleton('core/resource')->getConnection('core_write');

            $query = sprintf("SELECT entity_id FROM sales_flat_order_payment WHERE additional_data LIKE '%%\"CoinapultTID\":\"%s\"%%'", $response['transaction_id']);

            $readresult = $write->query($query);

            $row = $readresult->fetch();
            if($row) {
                $oid = $row['entity_id'];
            }
            if(!empty($oid)) {
                $order = Mage::getModel('sales/order')->load($oid);
                $comment = "Coinapult callback. Received: ".$response['in']['amount']."btc";
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $comment, true);
                $order->sendOrderUpdateEmail(true, $comment);
                $order->save();
            }
        } elseif($response['state'] == 'canceled') {
            $write = Mage::getSingleton('core/resource')->getConnection('core_write');

            $query = sprintf("SELECT entity_id FROM sales_flat_order_payment WHERE additional_data LIKE '%%\"CoinapultTID\":\"%s\"%%'", $response['transaction_id']);

            $readresult = $write->query($query);

            $row = $readresult->fetch();
            if($row) {
                $oid = $row['entity_id'];
            }

            if(!empty($oid)) {
                $order = Mage::getModel('sales/order')->load($oid);
                $payment = $order->getPayment();
                $adata = json_decode($payment->getAdditionalData());
            }
            $short = $response['in']['expected'] - $response['in']['amount'];

            $comment = "Coinapult callback. Insufficient payment. Received: ".$response['in']['amount']."btc. Expected: ".$response['in']['expected']."btc. Please send an additional ".$short."btc to Bitcoin address: ".$response['address'].".";
            $order->setState(Mage_Sales_Model_Order::STATE_NEW, true, $comment, true);
            $order->sendOrderUpdateEmail(true, $comment);
            $order->save();
        } else {
            exit;
        }
    }
}
