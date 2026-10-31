<?php

namespace App\Support\MarketData;

use App\Enums\PriceSource;
use App\Exceptions\MarketDataException;

/**
 * Used while no market-data provider or API key is set up: every refresh
 * explains how to set it up, and nothing is fetched.
 */
class UnconfiguredStockQuoteProvider implements StockQuoteProvider
{
    public function source(): PriceSource
    {
        return PriceSource::Manual;
    }

    public function quotes(array $tickers): array
    {
        throw MarketDataException::notConfigured();
    }
}
