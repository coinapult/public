#!/usr/bin/env ruby
require 'uri'
require 'json'
require 'base64'
require 'openssl'
require 'rest_client'
require 'securerandom'

ECC_CURVE = 'secp256k1'
ECC_COINAPULT_PUB = "
-----BEGIN PUBLIC KEY-----
MFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAEWp9wd4EuLhIZNaoUgZxQztSjrbqgTT0w
LBq8RwigNE6nOOXFEoGCjGfekugjrHWHUi8ms7bcfrowpaJKqMfZXg==
-----END PUBLIC KEY-----
"
ECC_COINAPULT_PUBKEY = OpenSSL::PKey.read(ECC_COINAPULT_PUB)

class CoinapultClient
  def initialize(credentials: nil, baseURL: 'https://api.coinapult.com',
                 ecc: nil, authmethod: nil)
    @key = ''
    @secret = ''
    @baseURL = baseURL
    @authmethod = authmethod

    if credentials
      @key = credentials[:key]
      @secret = credentials[:secret]
    end
    _setup_ECC_pair([ecc[:privkey], ecc[:pubkey]]) if ecc
  end

  def export_ECC
    [@ecc[:privkey].to_pem,
     @ecc[:pubkey].to_pem]
  end

  def _setup_ECC_pair(keypair)
    if keypair.nil?
      privkey = OpenSSL::PKey::EC.new(ECC_CURVE)
      privkey.generate_key
      pubkey = OpenSSL::PKey::EC.new(privkey.group)
      pubkey.public_key = privkey.public_key
      @ecc = { privkey: privkey, pubkey: pubkey }
    else
      privkey, pubkey = keypair
      @ecc = {
        privkey: OpenSSL::PKey.read(privkey),
        pubkey: OpenSSL::PKey.read(pubkey)
      }
    end
    @ecc_pub_pem = @ecc[:pubkey].to_pem.strip
    @ecc_pub_hash = OpenSSL::Digest::SHA256.hexdigest(@ecc_pub_pem)
  end

  # Send a generic request to Coinapult.
  def _send_request(url, values, sign: false, post: true)
    headers = {}

    if sign
      values['timestamp'] = Time.now.to_i
      values['nonce'] = SecureRandom.hex(10)
      values['endpoint'] = url[4..-1]
      headers['cpt-key'] = @key
      signdata = Base64.urlsafe_encode64(JSON.generate(values))
      headers['cpt-hmac'] = OpenSSL::HMAC.hexdigest('sha512', @secret, signdata)
      data = { data: signdata }
    else
      data = values
    end
    if post
      response = _send(:post, "#{@baseURL}#{url}", headers, payload: data)
    else
      url = "#{@baseURL}#{url}"
      if data.length > 0
        url += "?#{URI.encode_www_form(data)}"
      end
      response = _send(:get, url, headers)
    end
    _format_response(response)
  end

  def _format_response(response)
    resp = JSON.parse(response)
    fail CoinapultError, resp['error'] if resp['error']
    resp
  end

  def _send(method, url, headers, payload: nil)
     RestClient::Request.execute(:method => method, :url => url,
                                 :headers => headers, :payload => payload,
				 :ssl_version => "TLSv1")
  end

  def _send_ECC(url, values, new_account: false, sign: true)
    headers = {}

    if !new_account
      values['nonce'] = SecureRandom.hex(10)
      values['endpoint'] = url[4..-1]
      headers['cpt-ecc-pub'] = @ecc_pub_hash
    else
      headers['cpt-ecc-new'] = Base64.urlsafe_encode64(@ecc_pub_pem)
    end
    values['timestamp'] = Time.now.to_i

    data = Base64.urlsafe_encode64(JSON.generate(values))
    headers['cpt-ecc-sign'] = generate_ECC_sign(data, @ecc[:privkey])
    response = _send(:post, "#{@baseURL}#{url}", headers, payload: { data: data })
    _format_response(response)
  end

  def _receive_ECC(resp)
    if resp['sign'].nil? || resp['data'].nil?
      fail CoinapultErrorECC, 'Invalid ECC message'
    end
    # Check signature.
    unless verify_ECC_sign(resp['sign'], resp['data'], ECC_COINAPULT_PUBKEY)
      fail CoinapultErrorECC, 'Invalid ECC signature'
    end

    JSON.parse(Base64.urlsafe_decode64(resp['data']))
  end

  def send_to_coinapult(endpoint, values, sign: false, **kwargs)
    if sign && @authmethod == 'ecc'
      method = self.method(:_send_ECC)
    else
      method = self.method(:_send_request)
    end

    method.call(endpoint, values, sign: sign, **kwargs)
  end

  def create_account(create_local_keys: true, change_authmethod: true,
                     **kwargs)
    url = '/api/account/create'

    _setup_ECC_pair(nil) if create_local_keys

    pub_pem = @ecc_pub_pem
    result = _receive_ECC(_send_ECC(url, kwargs, new_account: true))
    unless result['success'].nil?
      if result['success'] != OpenSSL::Digest::SHA256.hexdigest(pub_pem)
        fail CoinapultErrorECC, 'Unexpected public key'
      end
      @authmethod = 'ecc' if change_authmethod
      puts "Please read the terms of service in TERMS.txt before \
proceeding with the account creation. #{result['info']}"
    end

    result
  end

  def activate_account(agree, pubhash: nil)
    url = '/api/account/activate'

    pubhash = @ecc_pub_hash if pubhash.nil?
    values = { agree: agree, hash: pubhash }
    _receive_ECC(_send_ECC(url, values, new_account: true))
  end

  def receive(amount: 0, out_amount: 0, currency: 'BTC', out_currency: nil,
              external_id: nil, callback: '')
    url = '/api/t/receive/'

    if amount > 0 && out_amount > 0
      fail ArgumentError, 'specify either the input amount or the output amount'
    end

    values = {}
    if amount > 0
      values['amount'] = amount
    elsif out_amount > 0
      values['outAmount'] = out_amount
    else
      fail ArgumentError, 'no amount specified'
    end

    out_currency = currency if out_currency.nil?

    values['currency'] = currency
    values['outCurrency'] = out_currency
    values['extOID'] = external_id unless external_id.nil?
    values['callback'] = callback

    send_to_coinapult(url, values, sign: true)
  end

  def send(amount, address, out_amount: 0, currency: 'BTC', typ: 'bitcoin',
           callback: '')
    url = '/api/t/send/'

    if amount > 0 && out_amount > 0
      fail ArgumentError, 'specify either the input amount or the output amount'
    end

    values = {}
    if amount > 0
      values['amount'] = amount
    elsif out_amount > 0
      values['outAmount'] = out_amount
    else
      fail ArgumentError, 'no amount specified'
    end
    values['currency'] = currency
    values['address'] = address
    values['type'] = typ
    values['callback'] = callback

    send_to_coinapult(url, values, sign: true)
  end

  def convert(amount, in_currency: 'USD', out_currency: 'BTC')
    url = '/api/t/convert/'

    if amount <= 0
      fail ArgumentError, 'invalid amount'
    elsif in_currency == out_currency
      fail ArgumentError, 'cannot convert currency to itself'
    end

    values = {}
    values['amount'] = amount
    values['inCurrency'] = in_currency
    values['outCurrency'] = out_currency

    send_to_coinapult(url, values, sign: true)
  end

  def search(transaction_id: nil, typ: nil, currency: nil,
             to: nil, fro: nil, external_id: nil, txhash: nil,
             many: false, page: nil)
    url = '/api/t/search/'

    values = {}
    values['transaction_id'] = transaction_id unless transaction_id.nil?
    values['type'] = typ unless typ.nil?
    values['currency'] = currency unless currency.nil
    values['to'] = to unless to.nil?
    values['from'] = fro unless fro.nil?
    values['extOID'] = external_id unless external_id.nil?
    values['txhash'] = txhash unless txhash.nil?
    fail ArgumentError, 'no search parameters provided' if values.length == 0
    values['many'] = '1' if many
    values['page'] = page unless page.nil?

    send_to_coinapult(url, values, sign: true)
  end

  def lock(amount, out_amount: 0, currency: 'USD', callback: nil)
    url = '/api/t/lock/'

    if amount > 0 && out_amount > 0
      fail ArgumentError, 'specify either the input amount or the output amount'
    end

    values = {}
    if amount > 0
      values['amount'] = amount
    elsif out_amount > 0
      values['outAmount'] = out_amount
    else
      fail ArgumentError, 'no amount specified'
    end
    values['callback'] = callback if callback
    values['currency'] = currency

    send_to_coinapult(url, values, sign: true)
  end

  def unlock(amount, address, out_amount: 0, currency: 'USD', callback: nil)
    url = '/api/t/unlock/'

    if amount > 0 && out_amount > 0
      fail ArgumentError, 'specify either the input amount or the output amount'
    end

    values = {}
    if amount > 0
      values['amount'] = amount
    elsif out_amount > 0
      values['outAmount'] = out_amount
    else
      fail ArgumentError, 'no amount specified'
    end
    values['callback'] = callback if callback
    values['currency'] = currency
    values['address'] = address

    send_to_coinapult(url, values, sign: true)
  end

  def unlock_confirm(transaction_id)
    url = '/api/t/unlock/confirm'

    values = {}
    values['transaction_id'] = transaction_id

    send_to_coinapult(url, values, sign: true)
  end

  # Get the ticker
  def ticker(begint: nil, endt: nil, market: nil, filter: nil)
    data = {}
    data['begin'] = begint unless begint.nil?
    data['end'] = endt unless endt.nil?
    data['market'] = market unless market.nil?
    data['filter'] = filter unless filter.nil?

    send_to_coinapult('/api/ticker', data, sign: false, post: false)
  end

  # Get a new bitcoin address
  def new_bitcoin_address
    send_to_coinapult('/api/getBitcoinAddress', {}, sign: true)
  end

  # Display basic account information
  def account_info(balance_type: 'all', locks_as_BTC: false)
    url = '/api/accountInfo'

    values = {}
    values['balanceType'] = balance_type
    values['locksAsBTC'] = locks_as_BTC

    send_to_coinapult(url, values, sign: true)
  end

  # Check if an address belongs to your account
  def account_address(address)
    url = '/api/accountInfo/address'

    values = {}
    values['address'] = address

    send_to_coinapult(url, values, sign: true)
  end

  # Utility for authenticating callbacks.
  def authenticate_callback(recv_key, recv_sign, recv_data)
    if recv_key.nil?
      # ECC
      return verify_ECC_sign(recv_sign, recv_data, ECC_COINAPULT_PUBKEY)
    end

    # HMAC
    if recv_key != @key
      # Unexpected API key received.
      return false
    end
    test_HMAC = OpenSSL::HMAC.hexdigest('sha512', @secret, recv_data)
    if test_HMAC != recv_sign
      # Signature does not match.
      return false
    end
    true
  end

end

class CoinapultError < StandardError; end

class CoinapultErrorECC < CoinapultError; end

def generate_ECC_sign(data, privkey)
  curve = privkey.group.curve_name
  if curve != ECC_CURVE
    fail CoinapultErrorECC, "key on curve #{curve}, expected #{ECC_CURVE}"
  end
  hmsg = OpenSSL::Digest::SHA256.digest(data)
  sign = OpenSSL::ASN1.decode(privkey.dsa_sign_asn1(hmsg))
  # Encode the signature as the r and s values.
  "#{sign.value[0].value.to_s(16)}#{sign.value[1].value.to_s(16)}"
end

def verify_ECC_sign(signstr, origdata, pubkey)
  curve = pubkey.group.curve_name
  if curve != ECC_CURVE
    fail CoinapultErrorECC, "key on curve #{curve}, expected #{ECC_CURVE}"
  end
  r = OpenSSL::ASN1::Integer.new(signstr[0..63].to_i(16))
  s = OpenSSL::ASN1::Integer.new(signstr[64..-1].to_i(16))
  sign = OpenSSL::ASN1::Sequence.new([r, s])
  pubkey.dsa_verify_asn1(OpenSSL::Digest::SHA256.digest(origdata),
                         sign.to_der)
end
