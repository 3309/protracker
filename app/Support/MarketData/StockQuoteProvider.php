<?php

namespace App\Support\MarketData;

use App\Enums\PriceSource;
use App\Exceptions\MarketDataException;

/**
 * Fetches the latest stock prices from one market-data provider.
 *
 * Implementations only fetch: they never save anything. A ticker the
 * provider has no usable price for (unknown, zero, blank or without a
 * quote time) is left out of the result rather than guessed.
 */
interface StockQuoteProvider
{
    public function source(): PriceSource;

    /**
     * @param  list<string>  $tickers  valid, upper-case tickers such as AAPL or BRK.B
     * @return array<string, StockQuote> quotes by the ticker as given
     *
     * @throws MarketDataException when no quotes could be fetched at all
     */
    public function quotes(array $tickers): array;
}
