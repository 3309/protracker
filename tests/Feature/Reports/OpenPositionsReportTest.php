<?php

use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\Price;
use App\Support\OpenPositionsReport;
use App\Support\PriceBook;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 16:00:00'));
    $this->today = CarbonImmutable::parse('2026-03-31');
    $this->account = Account::factory()->create();
    $this->report = fn (?string $ticker = null) => OpenPositionsReport::build(null, $ticker, PriceBook::load($this->today), $this->today);
});

function stockPrice(string $ticker, string $price, string $asOf = '2026-03-31 20:00:00'): Price
{
    return Price::factory()->create(['ticker' => $ticker, 'price' => UnitPrice::fromDecimal($price), 'as_of' => $asOf]);
}

function optionPrice(string $ticker, OptionType $type, string $strike, string $expiresOn, string $price, string $asOf = '2026-03-31 20:00:00'): Price
{
    return Price::factory()->option($type, $strike, $expiresOn)->create(['ticker' => $ticker, 'price' => UnitPrice::fromDecimal($price), 'as_of' => $asOf]);
}

it('values stock with the latest price: (price − cost) × shares still held', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '40', '52');
    stockPrice('XYZ', '55');

    $stock = ($this->report)()->stocks->sole();

    expect($stock['shares']->format())->toBe('60')
        ->and($stock['cost']->format())->toBe('$3,000.00')
        ->and($stock['marketValue']?->format())->toBe('$3,300.00')
        ->and($stock['unrealized']?->format())->toBe('$300.00');
});

it('gives the cost per share and unrealized percentage of the shares still held', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '1', '10');
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-13', '2', '10.50');
    stockPrice('XYZ', '9.50');

    $stock = ($this->report)()->stocks->sole();

    expect($stock['costPerShare']->format())->toBe('$10.3333')
        ->and($stock['unrealized']?->format())->toBe('-$2.50')
        ->and($stock['unrealizedPercent'])->toBe('-8.06');
});

it('values a short option as open premium less opening fees less the cost to close', function () {
    recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-04-17', '2026-03-02', 1, '1.20', '0.67');
    optionPrice('XYZ', OptionType::Put, '50', '2026-04-17', '0.30');

    expect(($this->report)()->options->sole()['unrealized']?->format())->toBe('$89.33');
});

it('values only the contracts still open after a partial close', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-04-17', '2026-03-02', 3, '1.20', '2.01');
    recordBuyToClose($put, '2026-03-10', 1, '0.50', '0.67');
    optionPrice('XYZ', OptionType::Put, '50', '2026-04-17', '0.30');

    $option = ($this->report)()->options->sole();

    expect($option['openContracts'])->toBe(2)
        ->and($option['openPremium']->format())->toBe('$240.00')
        ->and($option['openFees']->format())->toBe('$1.34')
        ->and($option['costToClose']?->format())->toBe('$60.00')
        ->and($option['unrealized']?->format())->toBe('$178.66');
});

it('shows price needed and leaves positions without a price out of the totals', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    recordStockTrade($this->account, TradeSide::Buy, 'ABC', '2026-01-12', '10', '20');
    stockPrice('XYZ', '51');

    $report = ($this->report)();

    expect($report->stocks->firstWhere('ticker', 'ABC')['unrealized'])->toBeNull()
        ->and($report->stockUnrealizedTotal()->format())->toBe('$100.00')
        ->and($report->stocksNeedingPrices())->toBe(1);
});

it('uses the latest price that has not been voided', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    stockPrice('XYZ', '51', '2026-03-27 20:00:00');
    stockPrice('XYZ', '53', '2026-03-30 20:00:00');
    stockPrice('XYZ', '99', '2026-03-31 20:00:00')->void('Wrong ticker');

    expect(($this->report)()->stocks->sole()['price']?->price->format())->toBe('$53.00');
});

it('marks a price stale once it is older than the threshold', function (string $asOf, bool $stale) {
    $book = PriceBook::load($this->today);

    expect($book->isStale(stockPrice('XYZ', '50', $asOf)))->toBe($stale);
})->with([
    '3 days old' => ['2026-03-28 20:00:00', false],
    '4 days old' => ['2026-03-27 20:00:00', true],
]);

it('shows whether a short option is in or out of the money from the underlying price', function (OptionType $type, string $underlying, string $expected) {
    recordSellToOpen($this->account, $type, 'XYZ', '50', '2026-04-17', '2026-03-02', 1, '1.00', '0.67');
    stockPrice('XYZ', $underlying);

    expect(($this->report)()->options->sole()['moneyness'])->toBe($expected);
})->with([
    'put, price below strike' => [OptionType::Put, '48', 'In the money'],
    'put, price above strike' => [OptionType::Put, '52', 'Out of the money'],
    'call, price above strike' => [OptionType::Call, '55', 'In the money'],
    'call, price at strike' => [OptionType::Call, '50', 'At the money'],
]);

it('sums open exposure by account and ticker, including the cash needed if puts are assigned', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    recordSellToOpen($this->account, OptionType::Call, 'XYZ', '55', '2026-04-17', '2026-03-02', 1, '1.00', '0.67');
    recordSellToOpen($this->account, OptionType::Put, 'XYZ', '45', '2026-04-17', '2026-03-02', 2, '1.00', '1.34');
    stockPrice('XYZ', '52');

    $row = ($this->report)()->exposure()->sole();

    expect($row['shares']->format())->toBe('100')
        ->and($row['marketValue']?->format())->toBe('$5,200.00')
        ->and($row['calls'])->toBe(1)
        ->and($row['puts'])->toBe(2)
        ->and($row['putObligation']->format())->toBe('$9,000.00');
});

it('filters by ticker', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    recordStockTrade($this->account, TradeSide::Buy, 'ABC', '2026-01-12', '10', '20');

    expect(($this->report)('ABC')->stocks->pluck('ticker')->all())->toBe(['ABC']);
});
