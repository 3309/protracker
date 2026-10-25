<?php

use App\Actions\Stocks\SaveStockTrade;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\StockTrade;
use App\Support\MarketCalendar;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Record a stock trade through the real stock engine, so its lot and the
 * FIFO sale allocations are created exactly as the app would create them.
 */
function recordStockTrade(Account $account, TradeSide $side, string $ticker, string $tradeDate, string $shares, string $price, string $fee = '0'): StockTrade
{
    return app(SaveStockTrade::class)->create([
        'account_id' => $account->id,
        'side' => $side,
        'ticker' => $ticker,
        'trade_date' => $tradeDate,
        'settlement_date' => MarketCalendar::stockSettlementDate(CarbonImmutable::parse($tradeDate)),
        'shares' => Quantity::fromDecimal($shares),
        'price_per_share' => UnitPrice::fromDecimal($price),
        'fees' => Money::fromDecimal($fee),
    ]);
}
