#!/usr/bin/env ruby
require 'rest_client'
require 'json'
require 'rubygems'
require 'hmac-sha2'
require 'securerandom'
require 'base64'
require 'open-uri'
require 'addressable/uri'

class CoinapultClient

  def initialize(key, secret, baseURL='https://api.coinapult.com')
    @key = key
    @secret = secret
    @baseURL = baseURL
  end

  #send a request to Coinapult
  def _sendRequest(url, values, sign=false, post=true)
    headers = Hash.new

    if sign
        values['timestamp'] = Time.now.to_i
        values['nonce'] = SecureRandom.hex()
        values['endpoint'] = url[4..-1]
        headers['cpt-key'] = @key
        signdata = Base64.encode64(JSON.generate(values))
        headers['cpt-hmac'] = HMAC::SHA512.hexdigest(@secret, signdata)
        uri = Addressable::URI.new
        uri.query_values = {:data => signdata}
        data = uri.query
    else
      uri = Addressable::URI.new
      uri.query_values = values
      data = uri.query
    end
    if post
        response = RestClient.post("#{@baseURL}#{url}", data, headers)
    else
        response = RestClient.get("#{@baseURL}#{url}?#{data}", headers)
    end
    puts response
    JSON.parse(response)
  end

  def receive(amount=0, outAmount=0, currency='BTC', outCurrency=nil, extOID=nil, callback='')
    if amount > 0
        outAmount = 0
    elsif outAmount > 0
        amount = 0
    end

    if amount == 0 and outAmount == 0
        raise ArgumentError('invalid amount')
    end

    if outCurrency.nil?
        outCurrency = currency
    end

    url = '/api/t/receive/'
    values = {}
    values['amount'] = amount
    values['currency'] = currency
    values['outCurrency'] = outCurrency
    values['outAmount'] = outAmount
    unless extOID.nil?
        values['extOID'] = extOID
    end
    values['callback'] = callback
    _sendRequest(url, values, sign=true)
  end

  def send(amount, address, outAmount=0, currency='BTC', typ='bitcoin', instrument='', callback='')
    if amount > 0
      outAmount = 0
    elsif outAmount > 0
      amount = 0
    end

    if amount == 0 and outAmount == 0
      raise ArgumentError('invalid amount')
    end

    url = '/api/t/send/'
    values = {}
    values['amount'] = amount
    values['currency'] = currency
    values['address'] = address
    values['type'] = typ
    values['callback'] = callback
    values['instrument'] = instrument
    values['outAmount'] = outAmount
    _sendRequest(url, values, sign=true)
  end

  def convert(amount, inCurrency='USD', outCurrency='BTC')
    if amount <= 0
      raise ArgumentError('invalid amount')
    elsif inCurrency == outCurrency
      raise ArgumentError('cannot convert currency to itself')
    end

    url = '/api/t/convert/'
    values = {}
    values['amount'] = amount
    values['inCurrency'] = inCurrency
    values['outCurrency'] = outCurrency
    _sendRequest(url, values, sign=true)
  end

  def search(transaction_id=nil, typ=nil, currency=nil, to=nil, fro=nil, extOID=nil, txhash=nil, many=false, page=nil)
  
    url = '/api/t/search/'

    values = {}
    unless transaction_id.nil?
      values['transaction_id'] = transaction_id
    end
    unless typ.nil?
      values['type'] = typ
    end
    unless currency.nil?
      values['currency'] = currency
    end
    unless to.nil?
      values['to'] = to
    end
    unless fro.nil?
      values['from'] = fro
    end
    unless extOID.nil?
      values['extOID'] = extOID
    end
    unless txhash.nil?
      values['txhash'] = txhash
    end

    if values.length == 0
      raise ArgumentError('no search parameters provided')
    end

    if many
      values['many'] = '1'
    end
    unless page.nil?
      values['page'] = page
    end

    _sendRequest(url, values, sign=true)
  end

  def lock(amount, outAmount=0, currency='USD', callback=nil)
    url = '/api/t/lock/'

    if amount > 0 and outAmount > 0
        raise ArgumentError("specify either the input amount or the output amount")
    end

    values = {}
    if amount > 0
      values['amount'] = amount
    elsif outAmount > 0
      values['outAmount'] = outAmount
    else
      raise ArgumentError("no amount specified")
    end
    if callback
        values['callback'] = callback
    end
    values['currency'] = currency
    _sendRequest(url, values, sign=true)
  end

  def unlock(amount, address, outAmount=0, currency='USD', callback=nil)
    url = '/api/t/unlock/'

    if amount > 0 and outAmount > 0
        raise ArgumentError("specify either the input amount or the output amount")
    end

    values = {}
    if amount == 'all' or amount > 0
      values['amount'] = amount
    elsif outAmount > 0
      values['outAmount'] = outAmount
    else
      raise ArgumentError("no amount specified")
    end
    if callback
        values['callback'] = callback
    end
    values['currency'] = currency
    values['address'] = address
    _sendRequest(url, values, sign=true)
  end

  # Get the ticker
  def ticker(begint=nil, endt=nil, market=nil, filter=nil)
    data = {}
    unless begint.nil?
      data['begin'] = begint
    end
    unless endt.nil?
      data['end'] = endt
    end
    unless market.nil?
      data['market'] = market
    end
    unless filter.nil?
      data['filter'] = filter
    end
    _sendRequest("/api/ticker", data, sign=false, post=false)
  end

  # Get a new bitcoin address
  def getBitcoinAddress
    _sendRequest("/api/getBitcoinAddress", {}, sign=true)
  end

  # Get account info
  def accountInfo()
    _sendRequest("/api/accountInfo", {}, sign=true)
  end

end

