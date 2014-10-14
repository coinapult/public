/**
 * Author: Guilherme Polo
 */
using System;

namespace CoinapultClient
{
	public class CoinapultException: Exception
	{
		public CoinapultException(string message): base(message)
		{
		}
	}

	public class CoinapultExceptionECC: CoinapultException
	{
		public CoinapultExceptionECC(string message): base(message)
		{
		}
	}
}

