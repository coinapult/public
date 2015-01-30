package com.coinapult.api.httpclient;

import java.io.IOException;
import java.io.UnsupportedEncodingException;
import java.math.BigDecimal;
import java.security.InvalidKeyException;
import java.security.KeyPair;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.security.NoSuchProviderException;
import java.security.PrivateKey;
import java.security.PublicKey;
import java.security.SecureRandom;
import java.security.Security;
import java.security.SignatureException;
import java.util.HashMap;
import java.util.HashSet;
import java.util.Map;
import java.util.Set;

import javax.crypto.Mac;
import javax.crypto.SecretKey;
import javax.crypto.spec.SecretKeySpec;

import org.bouncycastle.jce.provider.BouncyCastleProvider;
import org.bouncycastle.util.encoders.Hex;

import com.coinapult.api.httpclient.CoinapultError.CoinapultException;
import com.coinapult.api.httpclient.CoinapultError.CoinapultExceptionECC;
import com.google.api.client.http.GenericUrl;
import com.google.api.client.http.HttpContent;
import com.google.api.client.http.HttpHeaders;
import com.google.api.client.http.HttpRequest;
import com.google.api.client.http.HttpRequestFactory;
import com.google.api.client.http.HttpRequestInitializer;
import com.google.api.client.http.HttpResponse;
import com.google.api.client.http.HttpTransport;
import com.google.api.client.http.UrlEncodedContent;
import com.google.api.client.http.javanet.NetHttpTransport;
import com.google.api.client.json.JsonFactory;
import com.google.api.client.json.JsonObjectParser;
import com.google.api.client.json.JsonParser;
import com.google.api.client.json.jackson2.JacksonFactory;
import com.google.api.client.util.Base64;

/**
 * Client for the Coinapult HTTP API.
 *
 * @author Guilherme Polo
 *
 */
public class CoinapultClient {
	private static final String BASE_URL = "https://api.coinapult.com";

	private static final HttpTransport HTTP_TRANSPORT = new NetHttpTransport();
	private static final JsonFactory JSON_FACTORY = new JacksonFactory();

	private static final Set<String> SEARCH_CRITERIA = new HashSet<String>();
	static {
		SEARCH_CRITERIA.add("transaction_id");
		SEARCH_CRITERIA.add("type");
		SEARCH_CRITERIA.add("to");
		SEARCH_CRITERIA.add("from");
		SEARCH_CRITERIA.add("extOID");
		SEARCH_CRITERIA.add("situation");
		SEARCH_CRITERIA.add("txhash");
		SEARCH_CRITERIA.add("currency");
	};

	private HttpRequestFactory requestFactory;

	private String authMethod;
	/* apiKey and apiSecret are used when authMethod == "hmac" */
	private String apiKey;
	private SecretKey apiSecret;
	/* eccPub and eccPriv are used when authMethod == "ecc" */
	private PublicKey eccPub;
	private PrivateKey eccPriv;
	private String eccPubPEM;

	private PublicKey COINAPULT_PUBKEY;

	private SecureRandom rng;

	public CoinapultClient(String key, String secret) {
		authMethod = "hmac";
		apiKey = key;
		if (secret != null) {
			apiSecret = new SecretKeySpec(secret.getBytes(), "RAW");
		} else {
			apiSecret = null;
		}

		initialize();
	}

	public CoinapultClient(String secret) throws IOException {
		authMethod = "ecc";
		KeyPair kp = ECC.importFromPEM(secret);
		eccPub = kp.getPublic();
		eccPubPEM = ECC.exportToPEM(eccPub);
		eccPriv = kp.getPrivate();

		initialize();
		COINAPULT_PUBKEY = ECC.importPublicFromPEM(ECC.COINAPULT_PUBLIC_KEY);
	}

	public CoinapultClient() {
		/* Get a client that can access only public methods. */
		this(null, null);
	}

	private void initialize() {
		Security.addProvider(new BouncyCastleProvider());
		rng = new SecureRandom();
		requestFactory = HTTP_TRANSPORT
				.createRequestFactory(new HttpRequestInitializer() {
					@Override
					public void initialize(HttpRequest request) {
						request.setParser(new JsonObjectParser(JSON_FACTORY));
					}
				});
	}

	private <T> T sendGetRequest(Class<T> t, GenericUrl url) throws IOException {
		HttpRequest request = requestFactory.buildGetRequest(url);

		HttpResponse response = request.execute();
		T result = response.parseAs(t);
		return result;
	}

	private <T> T sendSignedRequest(Class<T> t, String endpoint,
			Map<String, String> options) throws InvalidKeyException,
			NoSuchProviderException, NoSuchAlgorithmException, IOException,
			SignatureException {
		if (authMethod == "hmac") {
			return sendPostHMacRequest(t, endpoint, options);
		} else {
			return sendECCRequest(t, endpoint, options, false);
		}
	}

	private <T> T sendPostHMacRequest(Class<T> t, String endpoint,
			Map<String, String> options) throws IOException,
			NoSuchProviderException, NoSuchAlgorithmException,
			InvalidKeyException {
		GenericUrl url = new GenericUrl(BASE_URL + endpoint);

		options.put("endpoint", endpoint);
		options.put("timestamp", CoinapultClient.timestampNow());
		options.put("nonce", generateNonce());
		String signdata = Base64.encodeBase64String(JSON_FACTORY
				.toByteArray(options));

		String sign = CoinapultClient.generateHmac(signdata, apiSecret);
		HttpHeaders headers = new HttpHeaders();
		headers.set("cpt-key", apiKey);
		headers.set("cpt-hmac", sign);

		return makePostRequest(t, url, headers, signdata);
	}

	private <T> T sendECCRequest(Class<T> t, String endpoint,
			Map<String, String> options, boolean newAccount)
			throws NoSuchAlgorithmException, IOException, InvalidKeyException,
			SignatureException {
		GenericUrl url = new GenericUrl(BASE_URL + endpoint);
		HttpHeaders headers = new HttpHeaders();

		if (!newAccount) {
			options.put("nonce", generateNonce());
			options.put("endpoint", endpoint);
			headers.set("cpt-ecc-pub", sha256(eccPubPEM));
		} else {
			headers.set("cpt-ecc-new",
					Base64.encodeBase64String(eccPubPEM.getBytes()));
		}
		options.put("timestamp", CoinapultClient.timestampNow());

		String signdata = Base64.encodeBase64String(JSON_FACTORY
				.toByteArray(options));
		headers.set("cpt-ecc-sign", ECC.generateSign(signdata, eccPriv));

		return makePostRequest(t, url, headers, signdata);
	}

	private <T> T makePostRequest(Class<T> t, GenericUrl url,
			HttpHeaders headers, String signdata) throws IOException {
		Map<String, String> param = new HashMap<String, String>();
		param.put("data", signdata);
		HttpContent content = new UrlEncodedContent(param);
		HttpRequest request = requestFactory.buildPostRequest(url, content);
		request.setHeaders(headers);

		HttpResponse response = request.execute();
		T result = response.parseAs(t);
		return result;
	}

	private JsonParser receiveECC(ECC.Json resp) throws InvalidKeyException,
			NoSuchAlgorithmException, SignatureException, IOException,
			CoinapultExceptionECC {
		if (resp.sign != null && resp.data != null) {
			if (!ECC.verifySign(resp.sign, resp.data, COINAPULT_PUBKEY)) {
				throw new CoinapultExceptionECC("Invalid ECC signature");
			}
			String data = new String(Base64.decodeBase64(resp.data));
			return JSON_FACTORY.createJsonParser(data);
		} else {
			System.out.println(resp.toPrettyString());
			throw new CoinapultExceptionECC("Invalid ECC message");
		}
	}

	/** Requests that do not require authentication. */

	/**
	 * Ticker.
	 *
	 * @param market
	 * @param filter
	 * @return
	 * @throws IOException
	 */
	public Ticker.Json ticker(String market, String filter) throws IOException {
		String endpoint = "/api/ticker";
		Ticker.Url url = new Ticker.Url(BASE_URL + endpoint);
		if (market != null) {
			url.setMarket(market);
		}
		if (filter != null) {
			url.setFilter(filter);
		}
		Ticker.Json t = sendGetRequest(Ticker.Json.class, url);
		return t;
	}

	public Ticker.Json ticker() throws IOException {
		return ticker(null, null);
	}

	/**
	 * TickerHistory
	 *
	 * @param begin
	 * @param end
	 * @param market
	 *            for now only "USD_BTC" is supported for historical tickers
	 * @return
	 * @throws IOException
	 */
	public TickerHistory.Json tickerHistory(long begin, long end, String market)
			throws IOException {
		String endpoint = "/api/ticker";
		TickerHistory.Url url = new TickerHistory.Url(BASE_URL + endpoint);
		if (begin > 0) {
			url.setBegin(begin);
		}
		if (end > 0) {
			url.setEnd(end);
		}
		if (market != null) {
			url.setMarket(market);
		}
		TickerHistory.Json t = sendGetRequest(TickerHistory.Json.class, url);
		return t;
	}

	public TickerHistory.Json tickerHistory(long begin, long end)
			throws IOException {
		return tickerHistory(begin, end, null);
	}

	/**
	 * Create an account.
	 *
	 * @param options
	 * @return
	 * @throws InvalidKeyException
	 * @throws NoSuchAlgorithmException
	 * @throws SignatureException
	 * @throws IOException
	 * @throws CoinapultExceptionECC
	 */
	public AccountNew.JsonNew createAccount(Map<String, String> options)
			throws InvalidKeyException, NoSuchAlgorithmException,
			SignatureException, IOException, CoinapultExceptionECC {
		String endpoint = "/api/account/create";
		if (options == null) {
			options = new HashMap<String, String>();
		}

		JsonParser result = receiveECC(sendECCRequest(ECC.Json.class, endpoint,
				options, true));
		AccountNew.JsonNew parsed = result.parse(AccountNew.JsonNew.class);
		if (parsed.success != null) {
			if (!parsed.success.equals(sha256(eccPubPEM))) {
				throw new CoinapultExceptionECC("Unexpected public key");
			}
			System.out
					.println("Please read the terms of service in TERMS.txt before "
							+ "proceeding with the account creation. "
							+ parsed.info);
		}
		return parsed;
	}

	public AccountNew.JsonNew createAccount() throws InvalidKeyException,
			NoSuchAlgorithmException, SignatureException,
			CoinapultExceptionECC, IOException {
		return createAccount(null);
	}

	/**
	 * Activate an account.
	 *
	 * @throws NoSuchAlgorithmException
	 * @throws IOException
	 * @throws CoinapultExceptionECC
	 * @throws SignatureException
	 * @throws InvalidKeyException
	 */
	public AccountNew.Json activateAccount(boolean agree)
			throws NoSuchAlgorithmException, InvalidKeyException,
			SignatureException, CoinapultExceptionECC, IOException {
		String endpoint = "/api/account/activate";
		Map<String, String> options = new HashMap<String, String>();
		options.put("agree", agree ? "1" : "0");
		options.put("hash", sha256(eccPubPEM));

		JsonParser result = receiveECC(sendECCRequest(ECC.Json.class, endpoint,
				options, true));
		AccountNew.Json parsed = result.parse(AccountNew.Json.class);
		return parsed;
	}

	/** Requests that require authentication. */

	/**
	 * Receive.
	 *
	 * @param amount
	 * @param currency
	 * @param outAmount
	 * @param outCurrency
	 * @param callback
	 * @param extOID
	 * @return
	 * @throws IOException
	 * @throws NoSuchProviderException
	 * @throws NoSuchAlgorithmException
	 * @throws InvalidKeyException
	 * @throws SignatureException
	 */
	public Transaction.Json receive(Number amount, String currency,
			Number outAmount, String outCurrency, String callback,
			String extOID)
					throws IOException, NoSuchProviderException,
			NoSuchAlgorithmException, InvalidKeyException, SignatureException {
		String endpoint = "/api/t/receive";

		BigDecimal inputAmount = new BigDecimal(amount.toString());
		BigDecimal outputAmount = new BigDecimal(outAmount.toString());

		Map<String, String> options = new HashMap<String, String>();
		if (inputAmount.compareTo(BigDecimal.ZERO) > 0) {
			options.put("amount", inputAmount.toString());
		}
		options.put("currency", currency);
		if (outputAmount.compareTo(BigDecimal.ZERO) > 0) {
			options.put("outAmount", outputAmount.toString());
		}
		if (outCurrency != null) {
			options.put("outCurrency", outCurrency);
		}
		if (callback != null) {
			options.put("callback", callback);
		}
		if (extOID != null) {
			options.put("extOID", extOID);
		}

		Transaction.Json result = sendSignedRequest(Transaction.Json.class,
				endpoint, options);
		return result;
	}

	/**
	 * Receive a specific amount in BTC. If callback is not required, pass null.
	 */
	public Transaction.Json receive(Number amount, String currency,
			String callback) throws IOException, NoSuchProviderException,
			NoSuchAlgorithmException, InvalidKeyException, SignatureException {
		return receive(amount, currency, 0, null, callback, null);
	}

	/** Receive a specific amount in a currency different than BTC */
	public Transaction.Json receive(String currency, Number outAmount,
			String outCurrency, String callback) throws IOException,
			NoSuchProviderException, NoSuchAlgorithmException,
			InvalidKeyException, SignatureException {
		return receive(0, currency, outAmount, outCurrency, callback, null);
	}

	/**
	 * Receive in a currency different than BTC by automatically converting the
	 * amount in BTC.
	 */
	public Transaction.Json receive(Number amount, String currency,
			String outCurrency, String callback) throws IOException,
			NoSuchProviderException, NoSuchAlgorithmException,
			InvalidKeyException, SignatureException {
		return receive(amount, currency, 0, outCurrency, callback, null);
	}

	/**
	 * Send.
	 *
	 * @param amount
	 * @param currency
	 * @param address
	 * @param outAmount
	 * @param callback
	 * @param extOID
	 * @param otp
	 * @return
	 * @throws IOException
	 * @throws InvalidKeyException
	 * @throws NoSuchProviderException
	 * @throws NoSuchAlgorithmException
	 * @throws SignatureException
	 */
	public Transaction.Json send(Number amount, String currency,
			String address, Number outAmount, String callback,
			String extOID,
			String otp) throws IOException, InvalidKeyException,
			NoSuchProviderException, NoSuchAlgorithmException,
			SignatureException {
		String endpoint = "/api/t/send";

		BigDecimal inputAmount = new BigDecimal(amount.toString());
		BigDecimal outputAmount = new BigDecimal(outAmount.toString());

		Map<String, String> options = new HashMap<String, String>();
		if (inputAmount.compareTo(BigDecimal.ZERO) > 0) {
			options.put("amount", inputAmount.toString());
		}
		options.put("currency", currency);
		options.put("address", address);
		if (outputAmount.compareTo(BigDecimal.ZERO) > 0) {
			options.put("outAmount", outputAmount.toString());
		}
		if (callback != null) {
			options.put("callback", callback);
		}
		if (extOID != null) {
			options.put("extOID", extOID);
		}
		if (otp != null) {
			options.put("otp", otp);
		}

		Transaction.Json result = sendSignedRequest(Transaction.Json.class,
				endpoint, options);
		return result;
	}

	/**
	 * Convert.
	 *
	 * @param amount
	 * @param currency
	 * @param outAmount
	 * @param outCurrency
	 * @param callback
	 * @return
	 * @throws IOException
	 * @throws InvalidKeyException
	 * @throws NoSuchProviderException
	 * @throws NoSuchAlgorithmException
	 * @throws SignatureException
	 */
	public Transaction.Json convert(Number amount, String currency,
			Number outAmount, String outCurrency, String callback)
					throws IOException, InvalidKeyException, NoSuchProviderException,
			NoSuchAlgorithmException, SignatureException {
		String endpoint = "/api/t/convert";

		BigDecimal inputAmount = new BigDecimal(amount.toString());
		BigDecimal outputAmount = new BigDecimal(outAmount.toString());

		Map<String, String> options = new HashMap<String, String>();
		if (inputAmount.compareTo(BigDecimal.ZERO) > 0) {
			options.put("amount", inputAmount.toString());
		}
		options.put("currency", currency);
		if (outputAmount.compareTo(BigDecimal.ZERO) > 0) {
			options.put("outAmount", outputAmount.toString());
		}
		options.put("outCurrency", outCurrency);
		if (callback != null) {
			options.put("callback", callback);
		}

		Transaction.Json result = sendSignedRequest(Transaction.Json.class,
				endpoint, options);
		return result;
	}

	/**
	 * Search.
	 *
	 * @param criteria
	 *            this will be modified, pass a copy if necessary.
	 * @return
	 * @throws IOException
	 * @throws InvalidKeyException
	 * @throws NoSuchProviderException
	 * @throws NoSuchAlgorithmException
	 * @throws CoinapultException
	 * @throws SignatureException
	 */
	public Transaction.Json search(Map<String, String> criteria)
			throws IOException, InvalidKeyException, NoSuchProviderException,
			NoSuchAlgorithmException, CoinapultException, SignatureException {
		String endpoint = "/api/t/search";

		Map<String, String> options = null;
		if (criteria.size() > 0
				&& SEARCH_CRITERIA.containsAll(criteria.keySet())) {
			options = criteria;
		} else {
			throw new CoinapultException("Invalid search criteria");
		}

		Transaction.Json result = sendSignedRequest(Transaction.Json.class,
				endpoint, options);
		return result;
	}

	/**
	 * Search many.
	 *
	 * @param criteria
	 *            this will be modified, pass a copy if necessary.
	 * @param page
	 * @return
	 * @throws IOException
	 * @throws InvalidKeyException
	 * @throws NoSuchProviderException
	 * @throws NoSuchAlgorithmException
	 * @throws CoinapultException
	 * @throws SignatureException
	 */
	public SearchMany.Json searchMany(Map<String, String> criteria, int page)
			throws IOException, InvalidKeyException, NoSuchProviderException,
			NoSuchAlgorithmException, CoinapultException, SignatureException {
		String endpoint = "/api/t/search";

		Map<String, String> options = null;
		if (criteria.size() > 0
				&& SEARCH_CRITERIA.containsAll(criteria.keySet())) {
			options = criteria;
		} else {
			throw new CoinapultError.CoinapultException(
					"Invalid search criteria");
		}
		options.put("many", "1");
		if (page > 0) {
			options.put("page", String.valueOf(page));
		}

		SearchMany.Json result = sendSignedRequest(SearchMany.Json.class,
				endpoint, options);
		return result;
	}

	/**
	 * Lock.
	 *
	 * @param amount
	 * @param outAmount
	 * @param outCurrency
	 * @param callback
	 * @return
	 * @throws IOException
	 * @throws InvalidKeyException
	 * @throws NoSuchProviderException
	 * @throws NoSuchAlgorithmException
	 * @throws SignatureException
	 */
	public Transaction.Json lock(Number amount, Number outAmount,
			String outCurrency, String callback) throws IOException,
			InvalidKeyException, NoSuchProviderException,
			NoSuchAlgorithmException, SignatureException {
		String endpoint = "/api/t/lock";

		BigDecimal inputAmount = new BigDecimal(amount.toString());
		BigDecimal outputAmount = new BigDecimal(outAmount.toString());

		Map<String, String> options = new HashMap<String, String>();
		if (inputAmount.compareTo(BigDecimal.ZERO) > 0) {
			options.put("amount", inputAmount.toString());
		}
		if (outputAmount.compareTo(BigDecimal.ZERO) > 0) {
			options.put("outAmount", outputAmount.toString());
		}
		options.put("currency", outCurrency);
		if (callback != null) {
			options.put("callback", callback);
		}

		Transaction.Json result = sendSignedRequest(Transaction.Json.class,
				endpoint, options);
		return result;
	}

	/**
	 * Unlock.
	 *
	 * @param amount
	 * @param inCurrency
	 * @param outAmount
	 * @param address
	 *            If specified the amount unlocked will be sent to the given
	 *            address, otherwise the amount will be credited to your
	 *            Coinapult wallet.
	 * @param callback
	 * @param acceptNow
	 *            If true, the unlock will be performed right away. If false,
	 *            it's possible to first check the operation that would be
	 *            performed and then it can be confirmed by using
	 *            CoinapultClient.unlockConfirm.
	 * @return
	 * @throws IOException
	 * @throws InvalidKeyException
	 * @throws NoSuchProviderException
	 * @throws NoSuchAlgorithmException
	 * @throws SignatureException
	 */
	public Transaction.Json unlock(Number amount, String inCurrency,
			Number outAmount, String address, String callback,
			boolean acceptNow)
					throws IOException, InvalidKeyException, NoSuchProviderException,
			NoSuchAlgorithmException, SignatureException {
		String endpoint = "/api/t/unlock";

		BigDecimal inputAmount = new BigDecimal(amount.toString());
		BigDecimal outputAmount = new BigDecimal(outAmount.toString());

		Map<String, String> options = new HashMap<String, String>();
		if (inputAmount.compareTo(BigDecimal.ZERO) > 0) {
			options.put("amount", inputAmount.toString());
		}
		options.put("currency", inCurrency);
		if (outputAmount.compareTo(BigDecimal.ZERO) > 0) {
			options.put("outAmount", outputAmount.toString());
		}
		if (address != null) {
			options.put("address", address);
		}
		if (callback != null) {
			options.put("callback", callback);
		}
		options.put("acceptNow", acceptNow ? "1" : "0");

		Transaction.Json result = sendSignedRequest(Transaction.Json.class,
				endpoint, options);
		return result;
	}

	/**
	 * Confirm an unlock operation.
	 *
	 * @param tid
	 * @return
	 * @throws IOException
	 * @throws InvalidKeyException
	 * @throws NoSuchProviderException
	 * @throws NoSuchAlgorithmException
	 * @throws SignatureException
	 */
	public Transaction.Json unlockConfirm(String tid) throws IOException,
			InvalidKeyException, NoSuchProviderException,
			NoSuchAlgorithmException, SignatureException {
		String endpoint = "/api/t/unlock/confirm";

		Map<String, String> options = new HashMap<String, String>();
		options.put("transaction_id", tid);
		Transaction.Json result = sendSignedRequest(Transaction.Json.class,
				endpoint, options);
		return result;
	}

	/**
	 * Get a new bitcoin address.
	 *
	 * @return
	 * @throws InvalidKeyException
	 * @throws NoSuchProviderException
	 * @throws NoSuchAlgorithmException
	 * @throws IOException
	 * @throws SignatureException
	 */
	public Address.Json getBitcoinAddress() throws InvalidKeyException,
			NoSuchProviderException, NoSuchAlgorithmException, IOException,
			SignatureException {
		String endpoint = "/api/getBitcoinAddress";

		Map<String, String> options = new HashMap<String, String>();
		Address.Json result = sendSignedRequest(Address.Json.class, endpoint,
				options);
		return result;
	}

	/**
	 * Account information.
	 *
	 * @param balanceType
	 *            one of "all", "normal", "locks"
	 * @param locksAsBTC
	 * @return
	 * @throws InvalidKeyException
	 * @throws NoSuchProviderException
	 * @throws NoSuchAlgorithmException
	 * @throws IOException
	 * @throws SignatureException
	 */
	public AccountInfo.Json accountInfo(String balanceType, boolean locksAsBTC)
			throws InvalidKeyException, NoSuchProviderException,
			NoSuchAlgorithmException, IOException, SignatureException {
		String endpoint = "/api/accountInfo";

		Map<String, String> options = new HashMap<String, String>();
		options.put("balanceType", balanceType);
		options.put("locksAsBTC", locksAsBTC ? "1" : "0");
		AccountInfo.Json result = sendSignedRequest(AccountInfo.Json.class,
				endpoint, options);
		return result;
	}

	public AccountInfo.Json accountInfo() throws InvalidKeyException,
			NoSuchProviderException, NoSuchAlgorithmException, IOException,
			SignatureException {
		return accountInfo("all", false);
	}

	/**
	 * Verify if an address belongs to the current account.
	 *
	 * @param address
	 * @return
	 * @throws InvalidKeyException
	 * @throws NoSuchProviderException
	 * @throws NoSuchAlgorithmException
	 * @throws IOException
	 * @throws SignatureException
	 */
	public AddressInfo.Json accountAddress(String address)
			throws InvalidKeyException, NoSuchProviderException,
			NoSuchAlgorithmException, IOException, SignatureException {
		String endpoint = "/api/accountInfo/address";

		Map<String, String> options = new HashMap<String, String>();
		options.put("address", address);
		AddressInfo.Json result = sendSignedRequest(AddressInfo.Json.class,
				endpoint, options);
		return result;
	}

	/**
	 * Utility functions.
	 */
	public boolean authenticateCallbackECC(String recvSign, String recvData)
			throws InvalidKeyException, NoSuchAlgorithmException,
			SignatureException, IOException {
		return ECC.verifySign(recvSign, recvData, COINAPULT_PUBKEY);
	}

	public boolean authenticateCallback(String recvKey, String recvSign,
			String recvData) throws InvalidKeyException,
			NoSuchAlgorithmException, NoSuchProviderException {
		if (recvKey != apiKey) {
			return false;
		}
		String testHmac = generateHmac(recvData, apiSecret);
		if (testHmac.equals(recvSign)) {
			return true;
		}
		return false;
	}

	static public String generateHmac(String data, SecretKey key)
			throws InvalidKeyException, NoSuchAlgorithmException,
			NoSuchProviderException {
		Mac sign = Mac.getInstance("Hmac-SHA512",
				BouncyCastleProvider.PROVIDER_NAME);
		sign.init(key);
		sign.reset();
		sign.update(data.getBytes());
		return Hex.toHexString(sign.doFinal());
	}

	public String generateNonce() {
		byte[] nonce = new byte[10];
		rng.nextBytes(nonce);
		return Hex.toHexString(nonce);
	}

	static public String timestampNow() {
		return String.valueOf(System.currentTimeMillis() / 1000);
	}

	static public String sha256(String val)
			throws UnsupportedEncodingException, NoSuchAlgorithmException {
		MessageDigest md = MessageDigest.getInstance("SHA-256");
		md.update(val.getBytes("UTF-8"));
		byte[] digest = md.digest();
		return Hex.toHexString(digest);
	}
}
