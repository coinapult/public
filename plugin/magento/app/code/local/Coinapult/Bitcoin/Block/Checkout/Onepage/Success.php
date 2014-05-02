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
 * Author: Ira Miller (ira@coinapult.com)
 *         Guilherme Polo (gp@coinapult.com)
 */
class Coinapult_Bitcoin_Block_Checkout_Onepage_Success extends Mage_Checkout_Block_Onepage_Success {
    protected function _prepareLastOrder() {
        $oid = Mage::getSingleton('checkout/session')->getLastOrderId();
        if($oid) {
            $order = Mage::getModel('sales/order')->load($oid);
            if($order->getId()) {
                $adata = json_decode($order->getPayment()->getAdditionalData());

                $this->addData(array(
                    'is_order_visible' => true,
                    'view_order_id' => $this->getUrl('sales/order/view/', array('order_id' => $oid)),
                    'print_url' => $this->getUrl('sales/order/print', array('order_id'=> $oid)),
                    'can_print_order' => true,
                    'can_view_order'  => Mage::getSingleton('customer/session')->isLoggedIn(),
                    'order_id'  => $oid,
                    'bitcoin_address' => $adata->BitcoinAddress,
                    'bitcoin_total' => $adata->BitcoinTotal,
                    'coinapult_tid' => $adata->CoinapultTID
                ));
            }
        }
    }
}
