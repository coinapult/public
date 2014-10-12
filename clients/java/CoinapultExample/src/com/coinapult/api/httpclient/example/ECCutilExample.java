package com.coinapult.api.httpclient.example;

import java.security.KeyPair;
import java.security.PrivateKey;
import java.security.PublicKey;
import java.security.Security;

import org.bouncycastle.jce.provider.BouncyCastleProvider;

import com.coinapult.api.httpclient.ECC;

public class ECCutilExample {
	public static void main(String[] args) {
		Security.addProvider(new BouncyCastleProvider());

		try {
			KeyPair keypair = ECC
					.importFromPEM("-----BEGIN EC PRIVATE KEY-----\n"
							+ "MHQCAQEEILwFOaXcyi0OezaRV+zuV/oQd/ygmBXA8PqboFqKzwq/oAcGBSuBBAAK\n"
							+ "oUQDQgAEgSzId2pbYTgQtBMfx9w4SkD4fDt5Es2VVSzt2MXuYIgTgrJ8k4eAjWKl\n"
							+ "k9BB4csn8R25OOtEwa05bVtOq2qr6g==\n"
							+ "-----END EC PRIVATE KEY-----");
			// KeyPair keypair = ECC.generateKeypair();
			PrivateKey priv = keypair.getPrivate();
			PublicKey pub = keypair.getPublic();

			String privPEM = ECC.exportToPEM(priv);
			System.out.println(privPEM);
			System.out.println(ECC.exportToPEM(pub));

			String somesign = ECC.generateSign("sign this", priv);
			System.out.println(somesign);
			System.out.println(ECC.verifySign(somesign, "sign this", pub));

			// KeyPair kp2 = ECC.importFromPEM(privPEM);
			// System.out.println(ECC.exportToPEM(kp2.getPrivate()));
			// System.out.println(ECC.exportToPEM(kp2.getPublic()));
		} catch (Throwable err) {
			err.printStackTrace();
		}
		System.exit(0);
	}
}
