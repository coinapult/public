var coinapult = require ('./coinapult.js');
var API = {
  key: '20a79976c8c1de9111073d40c6a429',
  secret: '1965f49326270e3201848860060fd9e714724a60778a5d1ff7197be8429c'
};

var client = coinapult.create ({
	apiKey: API.key,
	apiSecret: API.secret
});

var result = client.accountInfo();
console.log (JSON.stringify (result));
