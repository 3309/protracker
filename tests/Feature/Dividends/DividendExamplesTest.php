<?php

use App\Actions\Stocks\SaveStockTrade;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\Dividend;
use App\Models\StockLot;
use App\Support\DividendRate;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;

beforeEach(function () {
    $this->account = Account::factory()->create();
});

function dividendOn(Account $account, string $ticker, string $exDate, string $perShare = '0.50'): Dividend
{
    return Dividend::factory()->for($account)->create([
        'ticker' => $ticker,
        'ex_date' => $exDate,
        'pay_date' => '2026-03-31',
        'amount_per_share' => DividendRate::fromDecimal($perShare),
    ]);
}

it('Example E: a sale before the ex-date leaves 100 eligible shares and an expected $50.00', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-03-01', '200', '50');
    recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-03-05', '100', '51');

    $dividend = dividendOn($this->account, 'XYZ', '2026-03-10');

    expect($dividend->currentEligibleShares()->format())->toBe('100')
        ->and($dividend->currentExpectedAmount()->format())->toBe('$50.00');

    $dividend->confirm(Money::fromDecimal('42.50'));

    expect($dividend->expected_amount?->format())->toBe('$50.00')
        ->and($dividend->actual_amount?->format())->toBe('$42.50')
        ->and($dividend->hasDifference())->toBeTrue()
        ->and($dividend->difference()?->format())->toBe('-$7.50');
});

it('Example F: shares called away the day before the ex-date leave no eligible shares', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-02-20', '2026-01-12', 1, '1.20', '0.67');
    recordAssignment($put, '2026-02-20', 1);
    $call = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '52', '2026-03-20', '2026-02-23', 1, '0.80', '0.67');
    recordAssignment($call, '2026-03-09', 1);

    $dividend = dividendOn($this->account, 'XYZ', '2026-03-10');

    expect($dividend->currentEligibleShares()->isZero())->toBeTrue()
        ->and($dividend->currentExpectedAmount()->format())->toBe('$0.00');
});

it('Example G: a buy the day before the ex-date is eligible; a buy on the ex-date is not', function (string $buyDate, string $expectedShares) {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', $buyDate, '100', '50');

    expect(dividendOn($this->account, 'XYZ', '2026-03-10')->currentEligibleShares()->format())->toBe($expectedShares);
})->with([
    'bought Mar 9' => ['2026-03-09', '100'],
    'bought Mar 10' => ['2026-03-10', '0'],
]);

it('counts shares sold on the ex-date, which still receive the dividend', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-03-02', '100', '50');
    recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-03-10', '100', '51');

    expect(dividendOn($this->account, 'XYZ', '2026-03-10')->currentEligibleShares()->format())->toBe('100');
});

it('counts opening-balance lots and ignores other accounts and voided lots', function () {
    StockLot::factory()->for($this->account)->create(['ticker' => 'XYZ', 'acquired_on' => '2025-06-02', 'shares' => Quantity::fromInteger(30), 'cost_per_share' => UnitPrice::fromDecimal('40')]);
    StockLot::factory()->for($this->account)->voided()->create(['ticker' => 'XYZ', 'acquired_on' => '2025-06-02', 'shares' => Quantity::fromInteger(500)]);
    StockLot::factory()->create(['ticker' => 'XYZ', 'acquired_on' => '2025-06-02', 'shares' => Quantity::fromInteger(700)]);

    expect(dividendOn($this->account, 'XYZ', '2026-03-10')->currentEligibleShares()->format())->toBe('30');
});

it('keeps six decimal places per share and rounds the total to the cent', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-03-02', '33', '50');

    expect(dividendOn($this->account, 'XYZ', '2026-03-10', '0.123456')->currentExpectedAmount()->format())->toBe('$4.07');
});

it('does not change the cost of the shares', function () {
    $buy = recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-03-02', '100', '50');
    dividendOn($this->account, 'XYZ', '2026-03-10')->confirm(Money::fromDecimal('50'));

    expect($buy->lot->fresh()->cost_per_share->format())->toBe('$50.00');
});

it('notices when the shares on the ex-date change after confirmation', function () {
    $buy = recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-03-02', '100', '50');
    $dividend = dividendOn($this->account, 'XYZ', '2026-03-10');
    $dividend->confirm(Money::fromDecimal('50'));

    app(SaveStockTrade::class)->update($buy, ['shares' => Quantity::fromInteger(80)], 'Fewer shares');

    expect($dividend->eligibleSharesChangedSinceConfirmation())->toBeTrue();
});
