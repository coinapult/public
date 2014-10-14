/**
 * Author: Guilherme Polo
 */
using System;
using System.Net;
using System.Collections.Generic;
using System.Collections.Specialized;
using System.Security.Cryptography;
using System.Text;
using Newtonsoft.Json;
using Org.BouncyCastle.Crypto;

namespace CoinapultClient
{
	public class CoinapultAPI
	{
		private const string BASE_URL = "https://api.coinapult.com";

		private string authMethod;
		/* apiKey and apiSecret are used with HMAC */
		private string apiKey;
		private byte[] apiSecret;
		/* eccPub and eccPriv are used with ECC */
		private AsymmetricKeyParameter eccPub;
		private AsymmetricKeyParameter eccPriv;
		private string eccPubPEM;

		private RandomNumberGenerator rng;

		private AsymmetricKeyParameter COINAPULT_PUBKEY;

		public CoinapultAPI(string key, string secret) {
			/* Authentication method: HMAC */
			authMethod = "hmac";
			apiKey = key;
			apiSecret = Encoding.ASCII.GetBytes (secret);
			initialize ();
		}

		public CoinapultAPI() {
			/* Access public methods only. */
		}

		public CoinapultAPI(string secret) {
			/* Authentication method: ECC */
			authMethod = "ecc";
			AsymmetricCipherKeyPair kp = CoinapultECC.importFromPEM(secret);
			eccPub = kp.Public;
			eccPubPEM = CoinapultECC.exportPubToPEM(eccPub).Trim();
			eccPriv = kp.Private;

			initialize ();
			COINAPULT_PUBKEY = CoinapultECC.importPublicFromPEM(CoinapultECC.COINAPULT_PUBLIC_KEY);
		}

		private void initialize() {
			rng = new RNGCryptoServiceProvider ();
		}

		private T sendGetRequest<T>(string endpoint, NameValueCollection options) {
			var cli = new WebClient ();
			cli.QueryString = options;
			var response = cli.DownloadString (BASE_URL + endpoint);
			T jsonres = JsonConvert.DeserializeObject<T> (response);
			return jsonres;
		}

		private T sendSignedRequest<T>(string endpoint, NameValueCollection options) {
			if (authMethod == "hmac") {
				return sendPostHMACRequest<T>(endpoint, options);
			} else {
				return sendECCRequest<T>(endpoint, options);
			}
		}

		private T sendPostHMACRequest<T>(string endpoint, NameValueCollection options) {
			if (authMethod == null) {
				throw new CoinapultException ("Credentials not defined");
			}
			var cli = new WebClient ();

			options.Add ("endpoint", endpoint);
			options.Add ("timestamp", timestampNow ().ToString ());
			options.Add ("nonce", generateNonce ());

			var dictOptions = new Dictionary<string, string> ();
			foreach (string key in options.Keys) {
				dictOptions.Add (key, options [key]);
			}
			var data = JsonConvert.SerializeObject (dictOptions);
			var signdata = Convert.ToBase64String (Encoding.ASCII.GetBytes (data));

			var sign = generateHmac (Encoding.ASCII.GetBytes(signdata), apiSecret);
			cli.Headers.Add ("cpt-key", apiKey);
			cli.Headers.Add ("cpt-hmac", sign);

			return makePostRequest<T> (endpoint, cli, signdata);
		}

		private T sendECCRequest<T>(string endpoint, NameValueCollection options, bool newAccount=false) {
			var cli = new WebClient ();

			if (!newAccount) {
				options.Add ("endpoint", endpoint);
				options.Add ("nonce", generateNonce ());
				cli.Headers.Add ("cpt-ecc-pub", CoinapultAPI.sha256 (eccPubPEM));
			} else {
				cli.Headers.Add ("cpt-ecc-new",
					Convert.ToBase64String (Encoding.ASCII.GetBytes (eccPubPEM)));
			}
			options.Add ("timestamp", timestampNow ().ToString ());

			var dictOptions = new Dictionary<string, string> ();
			foreach (string key in options.Keys) {
				dictOptions.Add (key, options [key]);
			}
			var data = JsonConvert.SerializeObject (dictOptions);
			var signdata = Convert.ToBase64String (Encoding.ASCII.GetBytes (data));
			cli.Headers.Add ("cpt-ecc-sign", CoinapultECC.generateSign (Encoding.ASCII.GetBytes (signdata), eccPriv));

			return makePostRequest<T> (endpoint, cli, signdata);
		}

		private T makePostRequest<T>(string endpoint, WebClient cli, string signdata) {
			var post = new NameValueCollection ();
			post.Add ("data", signdata);

			var response = Encoding.ASCII.GetString(cli.UploadValues (BASE_URL + endpoint, post));
			T jsonres = JsonConvert.DeserializeObject<T> (response);
			return jsonres;
		}

		private T receiveECC<T>(ECCJson resp) {
			if (resp.sign != null && resp.data != null) {
				if (!CoinapultECC.verifySign(resp.sign, Encoding.ASCII.GetBytes (resp.data), COINAPULT_PUBKEY)) {
					throw new CoinapultExceptionECC ("Invalid ECC signature");
				}
				var data = Encoding.ASCII.GetString (Convert.FromBase64String (resp.data));
				return JsonConvert.DeserializeObject<T> (data);
			} else {
				Console.WriteLine (JsonConvert.SerializeObject (resp, Formatting.Indented));
				throw new CoinapultExceptionECC ("Invalid ECC message");
			}
		}

		/* Requests that do not require authentication. */

		public TickerJson ticker(string market="USD_BTC", string filter=null) {
			var endpoint = "/api/ticker";
			var options = new NameValueCollection ();
			options.Add ("market", market);
			if (filter != null) {
				options.Add ("filter", filter);
			}

			var result = sendGetRequest<TickerJson> (endpoint, options);
			return result;
		}

		public TickerHistoryJson tickerHistory(long begin, long end, string market="USD_BTC") {
			var endpoint = "/api/ticker";
			var options = new NameValueCollection ();
			if (begin > 0) {
				options.Add ("begin", begin.ToString ());
			}
			if (end > 0) {
				options.Add ("end", end.ToString ());
			}
			options.Add ("market", market);

			var result = sendGetRequest<TickerHistoryJson> (endpoint, options);
			return result;
		}

		public AccountNewConfirmJson createAccount(NameValueCollection options=null) {
			var endpoint = "/api/account/create";
			if (options == null) {
				options = new NameValueCollection ();
			}

			var sendresult = sendECCRequest<ECCJson> (endpoint, options, true);
			var recvresult = receiveECC<AccountNewConfirmJson> (sendresult);
			if (recvresult.success != null) {
				Console.WriteLine (recvresult.success);
				Console.WriteLine (sha256 (eccPubPEM));
				if (!recvresult.success.Equals (sha256 (eccPubPEM))) {
					throw new CoinapultExceptionECC("Unexpected public key");
				}
				Console.WriteLine (@"Please read the terms of service in TERMS.txt before
proceeding with the account creation. {0}", recvresult.info);
			}
			return recvresult;
		}

		public AccountNewJson activateAccount(bool agree) {
			var endpoint = "/api/account/activate";
			var options = new NameValueCollection ();
			options.Add ("agree", agree ? "1" : "0");
			options.Add ("hash", sha256(eccPubPEM));

			var sendresult = sendECCRequest<ECCJson> (endpoint, options, true);
			var recvresult = receiveECC<AccountNewJson> (sendresult);
			return recvresult;
		}

		/* Requests that require authentication. */
		public TransactionJson receive(double amount, string currency="BTC",
									   double outAmount=0, string outCurrency="BTC", string extOID=null,
									   string callback=null) {
			var endpoint = "/api/t/receive";
			var options = new NameValueCollection ();
			if (amount > 0) {
				options.Add ("amount", amount.ToString ());
			}
			options.Add ("currency", currency);
			if (outAmount > 0) {
				options.Add ("outAmount", outAmount.ToString ());
			}
			options.Add ("outCurrency", outCurrency);
			if (extOID != null) {
				options.Add ("extOID", extOID);
			}
			if (callback != null) {
				options.Add ("callback", callback);
			}

			var result = sendSignedRequest<TransactionJson> (endpoint, options);
			return result;
		}

		/* XXX Other methods are missing. */

		/* Utilities */

		public bool authenticateCallback(string recvKey, string recvSign, byte[] recvData) {
			if (recvKey != apiKey) {
				return false;
			}
			var testHmac = generateHmac(recvData, apiSecret);
			return (testHmac == recvSign);
		}

		public string generateNonce() {
			var nonce = new byte[10];
			rng.GetBytes (nonce);
			return BitConverter.ToString (nonce).Replace ("-", string.Empty);
		}

		static string generateHmac(byte[] rawdata, byte[] key) {
			var md = new HMACSHA512 (key);
			var sign = md.ComputeHash (rawdata);
			return BitConverter.ToString (sign).Replace ("-", string.Empty);
		}

		static public Int32 timestampNow() {
			var unixTimestamp = (Int32)(DateTime.UtcNow.Subtract(new DateTime(1970, 1, 1))).TotalSeconds;
			return unixTimestamp;
		}

		static public string sha256(string val) {
			using (var md = SHA256Managed.Create ()) {
				var data = Encoding.UTF8.GetBytes (val);
				var digest = md.ComputeHash (data);
				return BitConverter.ToString (digest).Replace ("-", string.Empty).ToLower();
			}
		}
	}
}
