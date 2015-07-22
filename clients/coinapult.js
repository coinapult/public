var crypto = require('crypto');
var request = require('request');
var jscrypto = require('jsrsasign');

var COINAPULTPUB_PEM = "-----BEGIN PUBLIC KEY-----" +
  "MFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAEWp9wd4EuLhIZNaoUgZxQztSjrbqgTT0w" +
  "LBq8RwigNE6nOOXFEoGCjGfekugjrHWHUi8ms7bcfrowpaJKqMfZXg==" +
  "-----END PUBLIC KEY-----";

/* Auxiliary functions for sending signed requests to Coinapult. */
function signData(data, seckey) {
  var sign = crypto.createHmac("sha512", seckey).update(data);
  return sign.digest('hex');
}

function prepareECC(params, pubkey, privkey) {
  /* Define the headers and parameters required for sending
   * a ECC signed request to Coinapult.
   */
  var headers = {};

  if (params.newAccount) {
    /* Do not set a nonce when creating new account. */
    headers['cpt-ecc-new'] = new Buffer(pubkey.pem).toString('base64');
    delete params.newAccount;
  } else {
    headers['cpt-ecc-pub'] = pubkey.hash;
    params.nonce = genNonce();
  }
  params.timestamp = new Date().getTime() / 1000;

  var data = new Buffer(JSON.stringify(params)).toString('base64');

  var sign = new jscrypto.Signature({'alg':'SHA256withECDSA'});
  sign.init(privkey);
  sign.updateString(data);
  var signVal = sign.sign();
  var signValRS = jscrypto.ECDSA.asn1SigToConcatSig(signVal);
  headers['cpt-ecc-sign'] = signValRS;

  return [headers, data];
}

function receiveECC(content, cb) {
  var checkSign = new jscrypto.Signature({'alg': 'SHA256withECDSA'});
  checkSign.init(COINAPULTPUB_PEM);
  checkSign.updateString(content.data);
  var sign = jscrypto.ECDSA.concatSigToASN1Sig(content.sign);
  var obj = {};
  if (checkSign.verify(sign)) {
    /* Signature is fine, proceed. */
    obj = JSON.parse(new Buffer(content.data, 'base64'));
    obj.validSign = true;
  } else {
    obj.validSign = false;
  }
  cb(obj);
}

function genNonce(length) {
  if (length === undefined) {
    length = 22;
  }
  var nonce = crypto.randomBytes(Math.ceil(length/2)).toString('hex');
  return nonce;
}


var VALID_SEARCH_KEYS = {
  transaction_id: true, type: true, currency: true,
  to: true, from: true, extOID: true, txhash: true
};


module.exports = {

  genKeypair: function() {
    var keypair = jscrypto.KEYUTIL.generateKeypair('EC', 'secp256k1');
    return {
      apiKey: keypair.pubKeyObj,
      apiSecret: keypair.prvKeyObj
    };
  },

  writePubkey: function(key) {
    return jscrypto.KEYUTIL.getPEM(key);
  },

  writePrivkey: function(key) {
    return jscrypto.KEYUTIL.getPEM(key, 'PKCS8PRV');
  },

  loadKeypair: function(privPEM, pubPEM) {
    var priv = jscrypto.KEYUTIL.getKey(privPEM);
    var pub = jscrypto.KEYUTIL.getKey(pubPEM);
    return {
      apiKey: pub,
      apiSecret: priv
    };
  },

  create: function(options) {
    /* Recognized keys in options:
     *  apiKey, apiSecret, ecc, baseURL
     *
     *  Set ecc to true if apiKey and apiSecret represent a ECC key pair.
     */
    var pubkey = {}; /* ECC public key and supporting params. */

    if (!options.baseURL) {
      options.baseURL = 'https://api.coinapult.com/api/';
    }

    if (options.ecc) {
      var privkey = options.apiSecret;
      pubkey.key = options.apiKey;
      pubkey.pem = jscrypto.KEYUTIL.getPEM(options.apiKey).trim();
      var md = new jscrypto.MessageDigest({alg: 'sha256', prov: 'cryptojs'});
      md.updateString(pubkey.pem);
      pubkey.hash = md.digest();
    }

    return {

      signData: signData,

      /* Make a call to the Coinapult API. */
      call: function(method, params, sign, post, cb) {
        var data, headers = {};
        if (sign) {
          if (options.ecc) {
            var result;
            result = prepareECC(params, pubkey, privkey);
            headers = result[0];
            data = result[1];
          } else {
            params.nonce = genNonce();
            params.timestamp = (new Date().getTime() / 1000).toString();
            params.endpoint = '/' + method;
            data = new Buffer(JSON.stringify(params)).toString('base64');
            headers = {
              'cpt-key':  options.apiKey,
              'cpt-hmac': signData(data, options.apiSecret)
            };
          }
        } else {
          data = '';
        }

        var reqopts = {
          url: options.baseURL + method,
          form: {data: data},
          headers: headers,
          method: post ? 'POST' : 'GET',
        };
        if (!post) {
          reqopts.qs = params;
        }
        request(reqopts, cb);
      },


      /* Coinapult API */

      ticker: function(cb, market, begin, end) {
        var params = {};
        if (typeof begin != 'undefined') {
          params.begin = begin;
        }
        if (typeof end != 'undefined') {
          params.end = end;
        }
        if (typeof market != 'undefined') {
          params.market = market;
        }

        this.call('ticker', params, false, false, cb);
      },

      accountInfo: function(cb, balanceType) {
        var params = {};
        if (typeof balanceType != 'undefined') {
          params.balanceType = balanceType;
        }
        this.call('accountInfo', params, true, true, cb);
      },

      getBitcoinAddress: function(cb) {
        this.call('getBitcoinAddress', {}, true, true, cb);
      },

      send: function(cb, amount, address, currency, extOID, callback) {
        var params = {
          'amount': amount,
          'address': address,
          'currency': currency
        };
        if (typeof extOID != 'undefined') {
          params.extOID = extOID;
        }
        if (typeof callback != 'undefined') {
          params.callback = callback;
        }

        this.call('t/send', params, true, true, cb);
      },

      receive: function(cb, amount, outAmount, address, inCurrency, outCurrency, extOID, urlCallback) {
        var params = {};
        if (typeof amount != 'undefined') {
          params.amount = amount;
        } else if (typeof outAmount != 'undefined') {
          params.outAmount = outAmount;
        }
        if (typeof address != 'undefined') {
          params.address = address;
        }
        if (typeof inCurrency != 'undefined') {
          params.currency = inCurrency;
        } else {
          params.currency = 'BTC';
        }
        if (typeof outCurrency != 'undefined') {
          params.outCurrency = outCurrency;
        }
        if (typeof extOID != 'undefined') {
          params.extOID = extOID;
        }
        if (typeof urlCallback != 'undefined') {
          params.callback = urlCallback;
        }

        return this.call('t/receive', params, true, true, cb);
      },

      search: function(cb, criteria, many, page) {
        var count = 0;
        for (var key in criteria) {
          count++;
          if (!(key in VALID_SEARCH_KEYS)) {
            throw "Unsupported search criteria: " + key;
          }
        }
        if (!count) {
          throw "Empty search criteria";
        }
        if (typeof many != 'undefined') {
          criteria.many = many;
        }
        if (typeof page != 'undefined') {
          criteria.page = page;
        }

        this.call('t/search/', criteria, true, true, cb);
      },

      convert: function(cb, amount, inCurrency, outCurrency, urlCallback) {
        var params = {'amount': amount};
        if (typeof inCurrency != 'undefined') {
          params.inCurrency = inCurrency;
        } else {
          params.inCurrency = 'BTC';
        }
        if (typeof outCurrency != 'undefined') {
          params.outCurrency = outCurrency;
        }
        if (typeof urlCallback != 'undefined') {
          params.callback = urlCallback;
        }

        this.call('t/convert', params, true, true, cb);
      },

      lock: function(cb, amount, outAmount, currency, urlCallback) {
        var params = {};
        if (typeof amount != 'undefined') {
          params.amount = amount;
        }
        if (typeof outAmount != 'undefined') {
          params.outAmount = outAmount;
        }
        if (typeof currency != 'undefined') {
          params.currency = currency;
        } else {
          params.currency = 'USD';
        }
        if (typeof urlCallback != 'undefined') {
          params.callback = urlCallback;
        }

        this.call('t/lock', params, true, true, cb);
      },

      unlock: function(cb, amount, address, outAmount, currency, urlCallback) {
        var params = {'address': address};
        if (typeof amount != 'undefined') {
          params.amount = amount;
        }
        if (typeof outAmount != 'undefined') {
          params.outAmount = outAmount;
        }
        if (typeof currency != 'undefined') {
          params.currency = currency;
        } else {
          params.currency = 'USD';
        }
        if (typeof urlCallback != 'undefined') {
          params.callback = urlCallback;
        }

        this.call('t/unlock', params, true, true, cb);
      },

      activateAccount: function(cb, agree, pubhash) {
        var params = {'newAccount': true, 'agree': agree};
        if (typeof pubhash != 'undefined') {
          params.hash = pubhash;
        } else {
          params.hash = pubkey.hash;
        }

        this.call('account/activate', params, true, true, function (err, req, ret) {
          if (err) {
            console.log(err);
          } else {
            var data = JSON.parse(ret);
            if (!data.error) {
              receiveECC(data, function(rdata) {
                if (cb) {
                  cb(null, req, rdata);
                }
              });
            } else {
              if (cb) {
                cb(data.error, req, null);
              }
            }
          }
        });
      },

      accountCreate: function(cb) {
        var params = {'newAccount': true};
        this.call('account/create', params, true, true, function (err, req, ret) {
          if (!err) {
            var data = JSON.parse(ret);
            if (!data.error) {
              var error;
              receiveECC(data, function(rdata) {
                if (rdata.validSign && rdata.success && rdata.success == pubkey.hash) {
                  error = null;
                } else {
                  error = {reason: 'failed to verify signature ' +
                    'and/or local public key'};
                }
                if (cb) {
                  cb(error, req, rdata);
                }
              });
            } else {
              if (cb) {
                cb(data.error, req, null);
              }
            }
          } else {
            console.log(err);
          }
        });
      }

    };
  }
};
