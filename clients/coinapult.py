"""
Author: Ira Miller, Guilherme Polo
Copyright Coinapult 2013, 2014
"""

import os
import hmac
import json
import time
import base64
from urlparse import urljoin
from hashlib import sha256, sha512

import requests

ecdsa = None
try:
    import ecdsa
except ImportError:
    print "authentication through ECC not available"

__version__ = "2.03"


ECC_COINAPULT_PUB = """\
-----BEGIN PUBLIC KEY-----
MFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAEWp9wd4EuLhIZNaoUgZxQztSjrbqgTT0w
LBq8RwigNE6nOOXFEoGCjGfekugjrHWHUi8ms7bcfrowpaJKqMfZXg==
-----END PUBLIC KEY-----
"""
ECC_COINAPULT_PUBKEY = None
if ecdsa:
    ECC_COINAPULT_PUBKEY = ecdsa.VerifyingKey.from_pem(ECC_COINAPULT_PUB)
ECC_CURVE = 'secp256k1'

TERMS = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'TERMS.txt')


class CoinapultClient():
    def __init__(self, credentials=None, baseURL='https://api.coinapult.com',
                 ecc=None, authmethod=None):
        """
        Instantiate a Coinapult client for using the API at baseURL.
        If the parameter credentials is specified, it must contain the
        keys 'key' and 'secret' which were given by Coinapult. This is
        the traditional method for authenticating.
        If the parameter ecc is specified, it must contain the keys
        'privkey' and 'pubkey' which describe keys generated for the
        curve secp256k1 and  stored in the PEM format.

        :param dict credentials: client credentials for sending signed
            requests
        :param dict ecc: client credentials for sending signed requests
            using ECC
        :param str authmethod: authentication method to use when
            sending signed requests, either 'ecc' or 'creds'
        :param str baseURL: base URL for the API server
        """
        self.key = ''
        self.secret = ''
        self.ecc = None
        self.ecc_pub_pem = None
        self.authmethod = authmethod

        if credentials:
            self.key = credentials['key']
            self.secret = credentials['secret']
        self.baseURL = baseURL
        if ecc and ecdsa:
            self._setupECCPair((ecc['privkey'], ecc['pubkey']))

    def _setupECCPair(self, keypair=None):
        if not keypair:
            privkey = ecdsa.SigningKey.generate(curve=ecdsa.SECP256k1)
            pubkey = privkey.get_verifying_key()
            self.ecc = {'privkey': privkey, 'pubkey': pubkey}
        else:
            privkey, pubkey = keypair
            self.ecc = {
                'privkey': ecdsa.SigningKey.from_pem(privkey),
                'pubkey': ecdsa.VerifyingKey.from_pem(pubkey)
            }
        if self.ecc['pubkey'].curve.name.lower() != ECC_CURVE:
            raise TypeError("Curve must be %s" % ECC_CURVE)
        self.ecc_pub_pem = self.ecc['pubkey'].to_pem().strip()
        self.ecc_pub_hash = sha256(self.ecc_pub_pem).hexdigest()

    def _sendRequest(self, url, values, sign=False, post=True):
        """
        Send message to URL and return response contents.
        This method supports authentication through the traditional method.

        Raises CoinapultError
        """
        headers = {}

        if sign:
            values['timestamp'] = int(time.time())
            values['nonce'] = createNonce(20)
            values['endpoint'] = url[4:] if url.startswith('/api') else url
            headers['cpt-key'] = self.key
            signdata = base64.b64encode(json.dumps(values))
            headers['cpt-hmac'] = generateHmac(signdata, self.secret)
            data = {'data': signdata}
        else:
            data = values

        finalURL = urljoin(self.baseURL, url)
        if post:
            res = requests.post(finalURL, data=data, headers=headers)
        else:
            res = requests.get(finalURL, params=data)
        return self._format_response(res.text)

    def _format_response(self, result):
        resp = json.loads(result)
        if 'error' in resp:
            raise CoinapultError(resp['error'])
        else:
            return resp

    def _sendECC(self, url, values, newAccount=False, sign=True):
        """
        Send authenticated messages using ECC. It is possible to
        create an account using this authentication method.

        Note that sign is always assumed to be True, this is defined
        in order to keep signature compatibility with _sendRequest.
        """
        if self.ecc is None:
            raise CoinapultError("ECC disabled")

        headers = {}
        if not newAccount:
            values['nonce'] = createNonce(20)
            values['endpoint'] = url[4:] if url.startswith('/api') else url
            headers['cpt-ecc-pub'] = self.ecc_pub_hash
        else:
            headers['cpt-ecc-new'] = base64.b64encode(self.ecc_pub_pem)
        values['timestamp'] = int(time.time())

        data = base64.b64encode(json.dumps(values))
        headers['cpt-ecc-sign'] = generateECCsign(data, self.ecc['privkey'])

        res = requests.post(urljoin(self.baseURL, url), data={'data': data},
                            headers=headers)
        return self._format_response(res.text)

    def _receiveECC(self, resp):
        """Decode a signed ECC response."""
        if 'sign' not in resp or 'data' not in resp:
            raise CoinapultErrorECC('Invalid ECC message')
        # Check signature.
        if not verifyECCsign(resp['sign'], resp['data'], ECC_COINAPULT_PUBKEY):
            raise CoinapultErrorECC('Invalid ECC signature')

        form = json.loads(base64.b64decode(resp['data']))
        return form

    def sendToCoinapult(self, endpoint, values, sign=False, **kwargs):
        """
        Send a message to an API endpoint and return response contents.
        """
        method = self._sendRequest
        if sign and self.authmethod == 'ecc':
            method = self._sendECC

        return method(endpoint, values, sign=sign, **kwargs)

    def createAccount(self, createLocalKeys=True, changeAuthMethod=True,
                      **kwargs):
        """
        Create a new account at Coinapult.

        :param bool createLocalKeys: if True, creates a new ECC keypair
            and use the public key for creating this new account. The
            resulting keypair is stored in self.ecc
        :param bool changeAuthMethod: if True, change the authentication
            method to 'ecc' for future signed requests.
        :rtype dict:
        """
        url = '/api/account/create'
        if createLocalKeys:
            self._setupECCPair()
        pub_pem = self.ecc_pub_pem
        result = self._receiveECC(self._sendECC(url, kwargs, newAccount=True))
        if 'success' in result:
            if result['success'] != sha256(pub_pem).hexdigest():
                raise CoinapultErrorECC('Unexpected public key')
            terms = open(TERMS).read()
            if result['terms'] != sha256(terms).hexdigest():
                raise CoinapultErrorECC('Terms of service is out-of-date')

            if changeAuthMethod:
                self.authmethod = 'ecc'

            print ("Please read the terms of service in TERMS.txt before "
                   "proceeding with the account creation. %s" % result['info'])

        return result

    def activateAccount(self, agree, pubhash=None):
        """
        Decide whether you agree or not to the terms sent when the
        account was created by using ECC.

        :param bool agree:
        :rtype dict:
        """
        url = '/api/account/activate'
        pubhash = pubhash or self.ecc_pub_hash
        values = {'agree': agree, 'hash': pubhash}
        result = self._receiveECC(self._sendECC(url, values, newAccount=True))
        return result

    def receive(self, amount=0, outAmount=0, currency='BTC', outCurrency=None,
                extOID=None, callback='', **kwargs):
        """Create a invoice to receive bitcoins from third party."""

        if amount and amount > 0:
            outAmount = 0
        elif outAmount and outAmount > 0:
            amount = 0
        if amount == 0 and outAmount == 0:
            raise CoinapultError('invalid amount')
        if not outCurrency:
            outCurrency = currency

        url = '/api/t/receive/'
        values = dict(**kwargs)
        values['amount'] = amount
        values['currency'] = currency
        values['outCurrency'] = outCurrency
        values['outAmount'] = outAmount
        if extOID:
            values['extOID'] = extOID
        values['callback'] = callback
        resp = self.sendToCoinapult(url, values, sign=True)
        if 'transaction_id' in resp:
            return resp
        else:
            raise CoinapultError("unknown response from Coinapult")

    def send(self, amount, address, outAmount=0,
             currency='BTC', typ='bitcoin',
             instrument='', callback='', **kwargs):
        """Send money."""

        if amount and amount > 0:
            outAmount = 0
        elif outAmount and outAmount > 0:
            amount = 0
        if not amount and not outAmount:
            raise CoinapultError('invalid amount')

        if address is None:
            raise CoinapultError('address required')

        url = '/api/t/send/'
        values = dict(**kwargs)
        values['amount'] = amount
        values['currency'] = currency
        values['address'] = str(address)
        values['type'] = typ
        values['callback'] = callback
        values['instrument'] = instrument
        if outAmount:
            values['outAmount'] = outAmount
        resp = self.sendToCoinapult(url, values, sign=True)
        if 'transaction_id' in resp:
            return resp
        else:
            raise CoinapultError("unknown response from Coinapult")

    def convert(self, amount, inCurrency='USD', outCurrency='BTC', **kwargs):
        """Convert balance from one currency to another."""

        if amount is None or amount <= 0:
            raise CoinapultError('invalid amount')
        elif inCurrency == outCurrency:
            raise CoinapultError('cannot convert currency to itself')

        url = '/api/t/convert/'
        values = {'amount': amount,
                  'currency': inCurrency,
                  'outCurrency': outCurrency}
        resp = self.sendToCoinapult(url, values, sign=True)
        if 'transaction_id' in resp:
            return resp
        else:
            raise CoinapultError("unknown response from Coinapult")

    def search(self, transaction_id=None, typ=None, currency=None, to=None,
               fro=None, extOID=None, txhash=None, many=False, page=None,
               situation=None, **kwargs):
        """Search for a transaction by common fields.

        To search for many transactions, set many=True and optionally
        specify a page number."""
        url = '/api/t/search/'

        values = {}
        if transaction_id is not None:
            values['transaction_id'] = transaction_id
        if typ is not None:
            values['type'] = typ
        if currency is not None:
            values['currency'] = currency
        if to is not None:
            values['to'] = to
        if fro is not None:
            values['from'] = fro
        if extOID is not None:
            values['extOID'] = extOID
        if txhash is not None:
            values['txhash'] = txhash
        if situation is not None:
            values['situation'] = situation

        if len(values) == 0:
            raise CoinapultError('no search parameters provided')

        if many:
            values['many'] = '1'
        if page is not None:
            values['page'] = page

        return self.sendToCoinapult(url, values, sign=True)

    def lock(self, amount, outAmount=0, currency='USD', callback=None, **kwargs):
        """Lock a certain amount of bitcoins to another currency."""
        url = '/api/t/lock/'

        gotInAmount, gotOutAmount = False, False
        try:
            if amount:
                gotInAmount = True
                if float(str(amount)) <= 0:
                    raise CoinapultError("amount must be positive")
            if outAmount:
                gotOutAmount = True
                if float(str(outAmount)) <= 0:
                    raise CoinapultError("outAmount must be positive")
        except ValueError:
            raise CoinapultError("amount must be a number")

        if not gotInAmount and not gotOutAmount:
            raise CoinapultError("no amount specified")
        if gotInAmount and gotOutAmount:
            raise CoinapultError("specify either the input amount or "
                                 "the output amount")

        values = {}
        if gotInAmount:
            values['amount'] = amount
        if gotOutAmount:
            values['outAmount'] = outAmount
        if callback:
            values['callback'] = callback
        values['currency'] = currency

        resp = self.sendToCoinapult(url, values, sign=True)
        if 'transaction_id' in resp:
            return resp
        else:
            raise CoinapultError("unknown response from Coinapult")

    def unlock(self, amount, address=None, outAmount=0, currency='USD',
               callback=None, acceptNow=False, **kwargs):
        """Unlock a certain amount in a given currency to get bitcoins back."""
        url = '/api/t/unlock/'

        gotInAmount, gotOutAmount = False, False
        try:
            if amount:
                gotInAmount = True
                if float(str(amount)) <= 0:
                    raise CoinapultError("amount must be positive")
            if outAmount:
                gotOutAmount = True
                if float(str(outAmount)) <= 0:
                    raise CoinapultError("outAmount must be positive")
        except ValueError:
            raise CoinapultError("amount must be a number")

        if not gotInAmount and not gotOutAmount:
            raise CoinapultError("no amount specified")
        if gotInAmount and gotOutAmount:
            raise CoinapultError("specify either the input amount or "
                                 "the output amount")

        values = {'currency': currency, 'acceptNow': acceptNow}
        if gotInAmount:
            values['amount'] = amount
        if gotOutAmount:
            values['outAmount'] = outAmount
        if callback:
            values['callback'] = callback
        if address:
            values['address'] = address

        resp = self.sendToCoinapult(url, values, sign=True)
        if 'transaction_id' in resp:
            return resp
        else:
            raise CoinapultError("unknown response from Coinapult")

    def unlockConfirm(self, transaction_id):
        """Accept an Unlock operation after verifying its quote."""
        url = '/api/t/unlock/confirm'

        values = {'transaction_id': transaction_id}
        resp = self.sendToCoinapult(url, values, sign=True)
        if 'transaction_id' in resp:
            return resp
        else:
            raise CoinapultError("unknown response from Coinapult")

    def getTicker(self, begin=None, end=None, market=None, filter=None, **kwargs):
        """Get exchange rates."""
        url = '/api/ticker/'

        values = {}
        if begin is not None:
            values['begin'] = begin
        if end is not None:
            values['end'] = end
        if market is not None:
            values['market'] = market
        if filter is not None:
            values['filter'] = filter

        return self.sendToCoinapult(url, values, post=False)

    def getBitcoinAddress(self):
        """generate a new bitcoin address"""
        url = '/api/getBitcoinAddress/'
        return self.sendToCoinapult(url, {}, sign=True)

    def configAddress(self, address, **kwargs):
        url = '/api/address/config'
        values = {'address': address}
        values.update(kwargs)
        return self.sendToCoinapult(url, values, sign=True)

    def accountInfo(self, balanceType='all', locksAsBTC=False, **kwargs):
        """account info"""
        url = '/api/accountInfo/'
        locksAsBTC = '1' if locksAsBTC else '0'
        values = {'balanceType': balanceType, 'locksAsBTC': locksAsBTC}
        return self.sendToCoinapult(url, values, sign=True)

    def accountAddress(self, address):
        """Check if an address belongs to your account."""
        url = '/api/accountInfo/address'
        return self.sendToCoinapult(url, {'address': address}, sign=True)

    def authenticateCallback(self, recvKey, recvSign, recvData, **kwargs):
        """Utility for validating a received message.
        Upon success, returns nothing."""
        if recvKey is None:
            # ECC auth.
            if not verifyECCsign(recvSign, recvData, ECC_COINAPULT_PUBKEY):
                raise CoinapultErrorECC('ECC signature does not match')
            return

        if recvKey != self.key:
            raise CoinapultError("Unexpected API key received")

        testHMAC = generateHmac(recvData, self.secret)
        if testHMAC != recvSign:
            raise CoinapultError("Signature does not match")


class CoinapultError(Exception):
    def __init__(self, message):
        self.error = message

    def __str__(self):
        return self.error


class CoinapultErrorECC(CoinapultError):
    pass


def generateECCsign(data, privkey):
    """
    Sign data using ECDSA-SHA256.

    :param str data: original data. For the API this would be base64 encoded
    :param ecdsa.SigningKey privkey: private key on curve secp256k1
    :rtype str:
    :return: the signature pair (r, s) concatenated and formatted as a
        hexadecimal string
    """
    if privkey.curve.name != ecdsa.SECP256k1.name:
        raise CoinapultErrorECC('key on curve %s, expected secp256k1' %
                                privkey.curve.name)
    hmsg = sha256(data).digest()
    sign = privkey.sign_digest_deterministic(hmsg, sha256)
    return sign.encode('hex')


def verifyECCsign(signstr, origdata, pubkey):
    """
    Verify signature using ECDSA-SHA256.

    :param str signstr: a signature formatted as a hexadecimal string
    :param str origdata: the original data used when creating the signature
    :param ecdsa.VerifyingKey pubkey: public key on curve secp256k1
    :rtype bool:
    :raises ecdsa.keys.BadSignatureError:
    """
    if pubkey.curve.name != ecdsa.SECP256k1.name:
        raise CoinapultErrorECC('key on curve %s, expected secp256k1' %
                                pubkey.curve.name)
    sign = signstr.decode('hex')
    return pubkey.verify(sign, origdata, sha256)


def generateHmac(message, secret):
    """Generate the HMAC-SHA512 of a given message using supplied key."""
    return hmac.new(secret, message, sha512).hexdigest()


def createNonce(length=20):
    """Generate a pseudo-random nonce."""
    return os.urandom(length / 2).encode('hex')
