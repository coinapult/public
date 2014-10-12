Java client
===========

Welcome to the Java client for the Coinapult HTTP API.

The easiest JAR to use is likely to be the one at
`distrib_with_dependencies/coinapult_http_clientapi.jar` as it includes
and points to the external JARs required (available at
`distrib_with_dependencies/coinapult_http_clientapi`). Running `java -jar
coinapult_http_clientapi.jar` should display a bunch of data related to
tickers.

If you don't want the external dependencies, then use
`distrib/coinapult_http_api.jar` (no `java -jar ...` for this one).

If you don't want JARs, then you can check the source code under
`Coinapult/` and `CoinapultExample/`. These folders were exported
from Eclipse but their `.classpath` do not point to the dependencies.
