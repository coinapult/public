/**
 * Author: Guilherme Polo
 */
using System;
using System.IO;
using System.Text;
using Org.BouncyCastle.Asn1;
using Org.BouncyCastle.Asn1.Sec;
using Org.BouncyCastle.Asn1.X9;
using Org.BouncyCastle.Crypto;
using Org.BouncyCastle.Crypto.Generators;
using Org.BouncyCastle.Crypto.Parameters;
using Org.BouncyCastle.Math;
using Org.BouncyCastle.OpenSsl;
using Org.BouncyCastle.Security;

namespace CoinapultClient
{
	public class CoinapultECC
	{
		private const string ECC_CURVE = "secp256k1";
		private const string ECDSA = "SHA256withECDSA";
		private const string OID_secp256k1 = "1.3.132.0.10";
		private const string OID_ecPublicKey = "1.2.840.10045.2.1";

		public const string COINAPULT_PUBLIC_KEY = @"-----BEGIN PUBLIC KEY-----
MFYwEAYHKoZIzj0CAQYFK4EEAAoDQgAEWp9wd4EuLhIZNaoUgZxQztSjrbqgTT0w
LBq8RwigNE6nOOXFEoGCjGfekugjrHWHUi8ms7bcfrowpaJKqMfZXg==
-----END PUBLIC KEY-----";

		private X9ECParameters ecparam = SecNamedCurves.GetByName (ECC_CURVE);
		private SecureRandom securerng = new SecureRandom();
		private ECDomainParameters spec;

		public CoinapultECC() {
			spec = new ECDomainParameters (ecparam.Curve, ecparam.G, ecparam.N, ecparam.H,
				ecparam.GetSeed ());
		}

		public AsymmetricCipherKeyPair generateKeypair() {
			var eckeygen = new ECKeyGenerationParameters (spec, securerng);
			var keygen = new ECKeyPairGenerator ();
			keygen.Init (eckeygen);

			var kp = keygen.GenerateKeyPair ();
			return kp;
		}

		public string newSecretKey() {
			var kp = generateKeypair ();
			return exportPrivToPEM (kp);
		}

		public static string generateSign(byte[] data, ICipherParameters privkey) {
			var dsa = SignerUtilities.GetSigner (ECDSA);
			dsa.Init (true, privkey);
			dsa.BlockUpdate (data, 0, data.Length);
			var sign = dsa.GenerateSignature ();
			BigInteger r, s;

			using (var decoder = new Asn1InputStream (sign)) {
				var seq = (Asn1Sequence)decoder.ReadObject ();
				r = ((DerInteger)seq [0]).Value;
				s = ((DerInteger)seq [1]).Value;
			}
			var signature = packInto32 (r) + packInto32 (s);
			return signature;
		}

		private static string packInto32(BigInteger b) {
			var arr = b.ToByteArray ();
			var start = arr.Length == 33 ? 1 : 0;
			var len = Math.Min (arr.Length, 32);
			var result = new byte[32];

			/* Make sure the integer b takes 32 bytes. */
			Array.Copy (arr, start, result, 32 - len, len);
			/* Convert the array to a hexadecimal string. */
			return BitConverter.ToString (result).Replace ("-", string.Empty).ToLower();
		}

		public static bool verifySign(string signature, byte[] origdata, ICipherParameters pubkey) {
			var dsa = SignerUtilities.GetSigner(ECDSA);
			dsa.Init (false, pubkey);
			dsa.BlockUpdate(origdata, 0, origdata.Length);

			BigInteger r = new BigInteger (signature.Substring (0, 64), 16);
			BigInteger s = new BigInteger (signature.Substring (64, 64), 16);
			Asn1EncodableVector vec = new Asn1EncodableVector ();
			vec.Add (new DerInteger (r));
			vec.Add (new DerInteger (s));
			Asn1Sequence seq = new DerSequence(vec);

			byte[] sign = seq.GetEncoded ();
			bool result = dsa.VerifySignature (sign);
			return result;
		}

		public static string exportPrivToPEM(AsymmetricCipherKeyPair pair) {
			byte[] asn1key;
			var privkey = ((ECPrivateKeyParameters)pair.Private).D;
			var pubkey = ((ECPublicKeyParameters)pair.Public).Q.GetEncoded ();

			/* Export in the same format as the one by OpenSSL */
			using (var mem = new MemoryStream ()) {
				using (var encoder = new Asn1OutputStream (mem)) {
					var seq = new DerSequenceGenerator (encoder);
					seq.AddObject (new DerInteger (1));
					seq.AddObject (new DerOctetString (privkey.ToByteArray ()));
					seq.AddObject (new DerTaggedObject (0, new DerObjectIdentifier (OID_secp256k1)));
					seq.AddObject (new DerTaggedObject (1, new DerBitString (pubkey)));
					seq.Close ();
					asn1key = mem.ToArray ();
				}
			}

			return bytesToPEM (asn1key, "EC PRIVATE");
		}

		private static string bytesToPEM(byte[] data, string title) {
			/* Save it in base64 with lines no longer than 64 bytes */
			string pem;
			var tempres = Convert.ToBase64String (data, 0, data.Length);

			using (var textWriter = new StringWriter ()) {
				textWriter.WriteLine ("-----BEGIN " + title + " KEY-----");
				for (int i = 0; i < tempres.Length; i += 64) {
					textWriter.WriteLine (tempres.Substring (i, Math.Min (64, tempres.Length - i)));
				}
				textWriter.WriteLine ("-----END " + title + " KEY-----");
				pem = textWriter.ToString ();
			}

			return pem;
		}

		public static string exportPubToPEM(AsymmetricKeyParameter key) {
			byte[] asn1key;
			var pubkey = ((ECPublicKeyParameters) key).Q.GetEncoded ();

			/* Export in the same format as the one by OpenSSL */
			using (var mem = new MemoryStream ()) {
				using (var encoder = new Asn1OutputStream (mem)) {
					var seq = new DerSequenceGenerator (mem);

					var vec = new Asn1EncodableVector ();
					vec.Add (new DerObjectIdentifier (OID_ecPublicKey));
					vec.Add (new DerObjectIdentifier (OID_secp256k1));

					seq.AddObject (new DerSequence(vec));
					seq.AddObject (new DerBitString (pubkey));
					seq.Close ();

					asn1key = mem.ToArray ();
				}
			}

			return bytesToPEM (asn1key, "PUBLIC");
		}

		public static AsymmetricCipherKeyPair importFromPEM(string priv) {
			AsymmetricCipherKeyPair kp;

			using (var textReader = new StringReader (priv)) {
				var pemReader = new PemReader (textReader);
				kp = (AsymmetricCipherKeyPair)pemReader.ReadObject ();
			}

			return kp;
		}

		public static AsymmetricKeyParameter importPublicFromPEM(string pub) {
			AsymmetricKeyParameter pubkey;

			using (var textReader = new StringReader (pub)) {
				var pemReader = new PemReader (textReader);
				pubkey = (AsymmetricKeyParameter)pemReader.ReadObject ();
			}

			return pubkey;
		}
	}
}

