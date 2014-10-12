package com.coinapult.api.httpclient.example;

import java.security.KeyPair;
import java.security.PrivateKey;
import java.security.Security;

import org.bouncycastle.jce.provider.BouncyCastleProvider;

import com.coinapult.api.httpclient.AccountNew;
import com.coinapult.api.httpclient.CoinapultClient;
import com.coinapult.api.httpclient.ECC;
import com.coinapult.api.httpclient.Transaction;

public class ECCExample {
	public static void main(String[] args) {
		Security.addProvider(new BouncyCastleProvider());

		/*
		 * Assuming there is no previous key pair, one has to be created now.
		 */
		String privPEM;
		try {
			KeyPair keypair;
			PrivateKey priv;
			keypair = ECC.generateKeypair();
			priv = keypair.getPrivate();
			privPEM = ECC.exportToPEM(priv);
		} catch (Throwable err) {
			err.printStackTrace();
			System.exit(1);
			return;
		}

		try {
			CoinapultClient cli = new CoinapultClient(privPEM);
			/* Create a new account. */
			AccountNew.JsonNew res = cli.createAccount();
			System.out.println(res.toPrettyString());
			/* Read and agree to the terms before activating the new account. */
			AccountNew.Json conf = cli.activateAccount(true);
			System.out.println(conf.toPrettyString());
			/* Start a lock transaction. */
			Transaction.Json lock = cli.lock(0.5, 0, "USD", null);
			System.out.println(lock.toPrettyString());
		} catch (Throwable err) {
			err.printStackTrace();
		}

		/* If you intend to ever this account again, save privPEM. */
		System.exit(0);
	}
}