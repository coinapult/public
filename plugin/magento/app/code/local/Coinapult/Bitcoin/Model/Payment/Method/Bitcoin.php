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

class Coinapult_Bitcoin_Model_Payment_Method_Bitcoin extends Mage_Payment_Model_Method_Abstract {
    protected $_code = 'bitcoin';
    protected $_formBlockType = 'bitcoin/payment_form_bitcoin';
    protected $_infoBlockType = 'bitcoin/payment_info_bitcoin';

    protected $_isGateway = true;
    protected $_canAuthorize = false;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = true;

    protected $_canCapture = false;
    protected $_canCapturePartial = false;
    protected $_canRefund = false;
    protected $_canVoid = false;
    protected $_canUseInternal = false;
    protected $_canSaveCc = false;

    public function __construct() {
        $called = true;
        parent::__construct();
    }

    public function assignData($data) {
        $info = $this->getInfoInstance();

        if (is_array($data)) {
            $info->addData($data);
        }
        elseif ($data instanceof Varien_Object) {
            $info->addData($data->getData());
        }

        $total = $info->getQuote()->getBaseGrandTotal();
        $curcode = $info->getQuote()->getBaseCurrencyCode();

        $key = Mage::getStoreConfig('payment/bitcoin/coinapult_key');
    		$secret = Mage::getStoreConfig('payment/bitcoin/coinapult_secret');

		    $coinapult = new Coinapult($key, $secret);

	    	/* Create an invoice. */
        $callurl = Mage::getUrl('bitcoin/callback', array('_type' => 'direct_link'));
        $response = $coinapult->receive(null, 'BTC', $total, 'USD', null, $callurl);
    		Mage::log('Invoice response: ' . print_r($response, TRUE), null, 'coinapult.log');
	    	$tid = $response['transaction_id'];

    		if (is_null($tid)) {
            Mage::throwException("Couldn't set order details. Coinapult error and/or system misconfigured.");
		    }

    		$info->setBitcoinTotal($response['in']['expected']);
    		$info->setBitcoinAddress($response['address']);
    		$info->setCoinapultTID($tid);

		    $adata = array();
    		$adata["BitcoinTotal"] = $response['in']['expected'];
        $adata["BitcoinAddress"] = $response['address'];
    		$adata["CoinapultTID"] = $tid;
    		$info->setAdditionalData(json_encode($adata));
        $info->save();
		    Mage::log('Info saved (' . $tid . '): '. print_r($adata, TRUE), null, 'coinapult.log');

        return $this;
    }
}
?>
