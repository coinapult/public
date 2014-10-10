require_relative '../../coinapult.rb'

creds = {
  key: '20a79976c8c1de9111073d40c6a429',
  secret: '1965f49326270e3201848860060fd9e714724a60778a5d1ff7197be8429c'
}

client = CoinapultClient.new(credentials: creds)

result = client.account_info
puts result
