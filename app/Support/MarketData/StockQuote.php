<?php

namespace App\Support\MarketData;

use App\Enums\PriceSource;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;

/**
 * The latest price of one stock as reported by a market-data provider, and
 * the moment the provider says the price is from.
 */
final readonly class StockQuote
{
    public function __construct(
        public string $ticker,
        public UnitPrice $price,
        public CarbonImmutable $quotedAt,
        public PriceSource $source,
    ) {}
}
