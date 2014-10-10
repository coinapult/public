#!/usr/bin/env ruby
require 'json'
require 'base64'
require 'openssl'
require 'rest_client'
require 'securerandom'

class CoinapultClient
  def initialize(key, secret, baseURL: 'https://api.coinapult.com')
    @key = key
    @secret = secret
    @baseURL = baseURL
  end

  # Send a generic request to Coinapult.
  def _send_request(url, values, sign: false, post: true)
    headers = {}

    if sign
      values['timestamp'] = Time.now.to_i
      values['nonce'] = SecureRandom.hex
      values['endpoint'] = url[4..-1]
      headers['cpt-key'] = @key
      signdata = Base64.encode64(JSON.generate(values))
      headers['cpt-hmac'] = OpenSSL::HMAC.hexdigest('sha512', @secret, signdata)
      data = { data: signdata }
    else
      data = values
    end
    if post
      response = RestClient.post("#{@baseURL}#{url}", data, headers)
    else
      response = RestClient.get("#{@baseURL}#{url}", data, headers)
    end
    JSON.parse(response)
  end

  def receive(amount: 0, out_amount: 0, currency: 'BTC', out_currency: nil,
              external_id: nil, callback: '')
    url = '/api/t/receive/'

    if amount > 0 && out_amount > 0
      fail ArgumentError('specify either the input amount or the output amount')
    end

    values = {}
    if amount > 0
      values['amount'] = amount
    elsif out_amount > 0
      values['outAmount'] = out_amount
    else
      fail ArgumentError('no amount specified')
    end

    out_currency = currency unless out_currency.nil?

    values['currency'] = currency
    values['outCurrency'] = out_currency
    values['extOID'] = external_id unless external_id.nil?
    values['callback'] = callback

    _send_request(url, values, sign: true)
  end

  def send(amount, address, out_amount: 0, currency: 'BTC', typ: 'bitcoin',
           callback: '')
    url = '/api/t/send/'

    if amount > 0 && out_amount > 0
      fail ArgumentError('specify either the input amount or the output amount')
    end

    values = {}
    if amount > 0
      values['amount'] = amount
    elsif out_amount > 0
      values['outAmount'] = out_amount
    else
      fail ArgumentError('no amount specified')
    end
    values['currency'] = currency
    values['address'] = address
    values['type'] = typ
    values['callback'] = callback

    _send_request(url, values, sign: true)
  end

  def convert(amount, in_currency: 'USD', out_currency: 'BTC')
    url = '/api/t/convert/'

    if amount <= 0
      fail ArgumentError('invalid amount')
    elsif in_currency == out_currency
      fail ArgumentError('cannot convert currency to itself')
    end

    values = {}
    values['amount'] = amount
    values['inCurrency'] = in_currency
    values['outCurrency'] = out_currency
    _send_request(url, values, sign: true)
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
    fail ArgumentError('no search parameters provided') if values.length == 0
    values['many'] = '1' if many
    values['page'] = page unless page.nil?

    _send_request(url, values, sign: true)
  end

  def lock(amount, out_amount: 0, currency: 'USD', callback: nil)
    url = '/api/t/lock/'

    if amount > 0 && out_amount > 0
      fail ArgumentError('specify either the input amount or the output amount')
    end

    values = {}
    if amount > 0
      values['amount'] = amount
    elsif out_amount > 0
      values['outAmount'] = out_amount
    else
      fail ArgumentError('no amount specified')
    end
    values['callback'] = callback if callback
    values['currency'] = currency

    _send_request(url, values, sign: true)
  end

  def unlock(amount, address, out_amount: 0, currency: 'USD', callback: nil)
    url = '/api/t/unlock/'

    if amount > 0 && out_amount > 0
      fail ArgumentError('specify either the input amount or the output amount')
    end

    values = {}
    if amount > 0
      values['amount'] = amount
    elsif out_amount > 0
      values['outAmount'] = out_amount
    else
      fail ArgumentError('no amount specified')
    end
    values['callback'] = callback if callback
    values['currency'] = currency
    values['address'] = address

    _send_request(url, values, sign: true)
  end

  # Get the ticker
  def ticker(begint: nil, endt: nil, market: nil, filter: nil)
    data = {}
    data['begin'] = begint unless begint.nil?
    data['end'] = endt unless endt.nil?
    data['market'] = market unless market.nil?
    data['filter'] = filter unless filter.nil?

    _send_request('/api/ticker', data, sign: false, post: false)
  end

  # Get a new bitcoin address
  def new_bitcoin_address
    _send_request('/api/getBitcoinAddress', {}, sign: true)
  end

  # Display basic account information
  def account_info
    _send_request('/api/accountInfo', {}, sign: true)
  end
end
