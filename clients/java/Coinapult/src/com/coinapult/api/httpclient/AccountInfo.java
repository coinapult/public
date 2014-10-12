package com.coinapult.api.httpclient;

import java.util.List;

import com.google.api.client.json.GenericJson;
import com.google.api.client.util.Key;

public class AccountInfo {
	public static class Json extends GenericJson {
		@Key
		public String role;

		@Key
		public List<Balance> balances;
	}

	public static class Balance {
		@Key
		public String currency;

		@Key
		public double amount;
	}
}
