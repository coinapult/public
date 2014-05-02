<?php
/*
 * Copyright 2013 Coinapult
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
 */

class Coinapult_Bitcoin_Block_Payment_Form_Bitcoin extends Mage_Payment_Block_Form {
    protected function _construct() {
        parent::_construct();
        $this->setTemplate('coinapult/payment/form/bitcoin.phtml');
    }
}
