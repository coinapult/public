package com.coinapult.api.httpclient;

import java.io.CharArrayReader;
import java.io.CharArrayWriter;
import java.io.IOException;
import java.io.Reader;
import java.io.Writer;
import java.math.BigInteger;
import java.security.InvalidAlgorithmParameterException;
import java.security.InvalidKeyException;
import java.security.Key;
import java.security.KeyPair;
import java.security.KeyPairGenerator;
import java.security.NoSuchAlgorithmException;
import java.security.NoSuchProviderException;
import java.security.PrivateKey;
import java.security.PublicKey;
import java.security.Signature;
import java.security.SignatureException;
import java.security.spec.ECGenParameterSpec;

import org.bouncycastle.asn1.ASN1EncodableVector;
import org.bouncycastle.asn1.ASN1InputStream;
import org.bouncycastle.asn1.ASN1Integer;
import org.bouncycastle.asn1.ASN1Sequence;
import org.bouncycastle.asn1.DERSequence;
import org.bouncycastle.asn1.x509.SubjectPublicKeyInfo;
import org.bouncycastle.jce.provider.BouncyCastleProvider;
import org.bouncycastle.openssl.PEMKeyPair;
import org.bouncycastle.openssl.PEMParser;
import org.bouncycastle.openssl.jcajce.JcaPEMKeyConverter;
import org.bouncycastle.openssl.jcajce.JcaPEMWriter;

import com.google.api.client.json.GenericJson;

public class ECC {
	static final String ECC_CURVE = "secp256k1";
	static final String ECDSA = "SHA256withECDSA";

	static final String COINAPULT_PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\n"
			+ "MFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAEWp9wd4EuLhIZNaoUgZxQztSjrbqgTT0w\n"
			+ "LBq8RwigNE6nOOXFEoGCjGfekugjrHWHUi8ms7bcfrowpaJKqMfZXg==\n"
			+ "-----END PUBLIC KEY-----";

	public static String generateSign(String data, PrivateKey priv)
			throws NoSuchAlgorithmException, InvalidKeyException,
			SignatureException, IOException {
		Signature dsa = Signature.getInstance(ECDSA);
		dsa.initSign(priv);
		dsa.update(data.getBytes());
		byte[] sign = dsa.sign();

		ASN1InputStream decoder = new ASN1InputStream(sign);
		ASN1Sequence seq = (ASN1Sequence) decoder.readObject();
		BigInteger r = ((ASN1Integer) seq.getObjectAt(0)).getValue();
		BigInteger s = ((ASN1Integer) seq.getObjectAt(1)).getValue();
		decoder.close();

		String signature = r.toString(16) + "|" + s.toString(16);
		return signature;
	}

	public static boolean verifySign(String signature, String origdata,
			PublicKey pub) throws NoSuchAlgorithmException,
			InvalidKeyException, SignatureException, IOException {
		Signature dsa = Signature.getInstance(ECDSA);
		dsa.initVerify(pub);
		dsa.update(origdata.getBytes());

		/* Construct ASN1 sequence from the signature received. */
		BigInteger r = new BigInteger(signature.substring(0, 64), 16);
		BigInteger s = new BigInteger(signature.substring(64,
				signature.length()), 16);
		ASN1EncodableVector vec = new ASN1EncodableVector();
		vec.add(new ASN1Integer(r));
		vec.add(new ASN1Integer(s));
		ASN1Sequence seq = new DERSequence(vec);

		byte[] sign = seq.getEncoded();
		boolean result = dsa.verify(sign);
		return result;
	}

	public static KeyPair generateKeypair() throws NoSuchAlgorithmException,
			NoSuchProviderException, InvalidAlgorithmParameterException {
		KeyPairGenerator keygen = KeyPairGenerator.getInstance("EC",
				BouncyCastleProvider.PROVIDER_NAME);
		ECGenParameterSpec eccspec = new ECGenParameterSpec(ECC_CURVE);
		keygen.initialize(eccspec);

		KeyPair keypair = keygen.generateKeyPair();
		return keypair;
	}

	public static String exportToPEM(Key key) throws IOException {
		Writer writer = new CharArrayWriter();
		JcaPEMWriter pemwriter = new JcaPEMWriter(writer);
		pemwriter.writeObject(key);
		pemwriter.flush();
		String result = writer.toString();
		pemwriter.close();
		return result.trim();
	}

	public static KeyPair importFromPEM(String priv) throws IOException {
		Reader reader = new CharArrayReader(priv.toCharArray());
		PEMParser parser = new PEMParser(reader);
		JcaPEMKeyConverter converter = new JcaPEMKeyConverter();
		Object obj = parser.readObject();
		parser.close();

		KeyPair keypair = converter.getKeyPair((PEMKeyPair) obj);
		return keypair;
	}

	public static PublicKey importPublicFromPEM(String pub) throws IOException {
		Reader reader = new CharArrayReader(pub.toCharArray());
		PEMParser parser = new PEMParser(reader);
		JcaPEMKeyConverter converter = new JcaPEMKeyConverter();
		Object obj = parser.readObject();
		parser.close();

		PublicKey pubkey = converter.getPublicKey((SubjectPublicKeyInfo) obj);
		return pubkey;
	}

	public static class Json extends GenericJson {
		@com.google.api.client.util.Key
		public String sign;

		@com.google.api.client.util.Key
		public String data;
	}
}
