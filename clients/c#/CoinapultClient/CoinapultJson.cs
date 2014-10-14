/**
 * Author: Guilherme Polo
 */
using System;
using System.Collections.Generic;
using Newtonsoft.Json;

namespace CoinapultClient
{

	public class TickerJson
	{
		public string type;
		public double index;
		public string market;
		public int updatetime;

		[JsonProperty("100")]
		public BidAsk level_100;
		[JsonProperty("500")]
		public BidAsk level_500;
		[JsonProperty("2000")]
		public BidAsk level_2000;
		[JsonProperty("5000")]
		public BidAsk level_5000;
		[JsonProperty("1000")]
		public BidAsk level_10000;
		public BidAsk small;
		public BidAsk medium;
		public BidAsk large;
		public BidAsk vip;
		[JsonProperty("vip+")]
		public BidAsk vip_plus;
	}

	public class BidAsk
	{
		public double ask;
		public double bid;
	}

	public class TickerHistoryJson
	{
		public List<HistoryItem> result;
	}

	public class HistoryItem: BidAsk
	{
		public long updatetime;
	}

	public class TransactionJson
	{
		public string type;

		[JsonProperty("transaction_id")]
		public string tid;

		public string address; /* Might be null. */
		public long? completeTime;
		public long? expiration;
		public long timestamp;

		[JsonProperty("in")]
		public Half inh;
		[JsonProperty("out")]
		public Half outh;
		public BidAsk quote;   /* Will be null if there is no conversion required. */

		public string state;

		public string extOID;  /* Might be null. */
	}

	public class Half
	{
		public double amount;
		public string currency;
		public double expected;
	}

	public class ECCJson {
		public string error;
		public string sign;
		public string data;
	}

	public class AccountNewJson {
		public string success;
		public long when;
	}

	public class AccountNewConfirmJson: AccountNewJson {
		public string terms;
		public string info;
	}
}

