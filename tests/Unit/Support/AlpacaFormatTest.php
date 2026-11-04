<?php

use App\Support\Alpaca\AlpacaFormat;

it('rounds money with extra decimal places instead of rejecting it', function (mixed $value, ?string $expected) {
    expect(AlpacaFormat::money($value)?->format())->toBe($expected);
})->with([
    'more than two places' => ['1234.567', '$1,234.57'],
    'a whole number' => ['100000', '$100,000.00'],
    'negative' => ['-71.345', '-$71.35'],
    'a JSON number' => [754.29, '$754.29'],
    'missing' => [null, null],
    'text' => ['n/a', null],
    'an exponent' => ['1e5', null],
    'a list' => [['1'], null],
]);

it('reads per-share prices, leaving out negative ones', function (mixed $value, ?string $expected) {
    expect(AlpacaFormat::price($value)?->toDecimal())->toBe($expected);
})->with([
    'more than four places' => ['320.123456', '320.1235'],
    'a JSON number' => [12.26, '12.26'],
    'zero stays zero' => [0, '0'],
    'negative' => ['-1', null],
    'missing' => [null, null],
]);

it('shows fractions as percentages and trims plain numbers', function () {
    expect(AlpacaFormat::percent('0.0331'))->toBe('3.31%')
        ->and(AlpacaFormat::percent(null))->toBeNull()
        ->and(AlpacaFormat::decimal('10.500'))->toBe('10.5')
        ->and(AlpacaFormat::decimal('100'))->toBe('100')
        ->and(AlpacaFormat::decimal(0.5234, 3))->toBe('0.523')
        ->and(AlpacaFormat::decimal(null))->toBeNull();
});

it('reads times with nanoseconds and refuses unreadable ones', function () {
    expect(AlpacaFormat::timestamp('2026-11-03T15:59:30.286587431Z')?->format('Y-m-d H:i:s.u'))->toBe('2026-11-03 15:59:30.286587')
        ->and(AlpacaFormat::timestamp('whenever'))->toBeNull()
        ->and(AlpacaFormat::timestamp(null))->toBeNull();
});

it('names option symbols readably and leaves other symbols as they are', function (string $symbol, string $expected) {
    expect(AlpacaFormat::symbolLabel($symbol))->toBe($expected);
})->with([
    'a whole-dollar put' => ['AAPL261120P00300000', 'AAPL $300 Put · Nov 20, 2026'],
    'a call with cents' => ['TSLA261120C00327500', 'TSLA $327.50 Call · Nov 20, 2026'],
    'a stock' => ['AAPL', 'AAPL'],
    'a share class' => ['BRK.B', 'BRK.B'],
    'an incomplete option symbol stays as sent' => ['AAPL261120P003', 'AAPL261120P003'],
    'a date that does not exist stays as sent' => ['AAPL261345P00300000', 'AAPL261345P00300000'],
    'a zero strike stays as sent' => ['AAPL261120P00000000', 'AAPL261120P00000000'],
]);

it('states the side and number of contracts from the order or position data', function (mixed $side, mixed $quantity, ?string $expected) {
    expect(AlpacaFormat::contractsLabel($side, $quantity))->toBe($expected);
})->with([
    'one contract' => ['sell', '1', 'Sell 1 contract'],
    'several' => ['buy', '3', 'Buy 3 contracts'],
    'a short position arrives negative' => ['short', '-2', 'Short 2 contracts'],
    'no quantity' => ['buy', null, 'Buy, number of contracts not given'],
    'no side' => [null, '2', '2 contracts'],
    'neither' => [null, null, null],
]);

it('formats a contract\'s figures, leaving missing ones empty rather than zero', function () {
    $figures = AlpacaFormat::contractFigures(['bid' => 1.26, 'ask' => 1.33, 'impliedVolatility' => 0.4663, 'greeks' => ['delta' => 0.2672, 'theta' => -1.333, 'gamma' => null]]);

    expect($figures['Bid'])->toBe('$1.26')
        ->and($figures['Ask'])->toBe('$1.33')
        ->and($figures['Last'])->toBeNull()
        ->and($figures['IV'])->toBe('46.63%')
        ->and($figures['Delta'])->toBe('0.267')
        ->and($figures['Theta'])->toBe('-1.333')
        ->and($figures['Gamma'])->toBeNull()
        ->and(AlpacaFormat::contractFigures([])['Bid'])->toBeNull();
});

it('reads a standard option symbol and leaves others alone', function () {
    $contract = AlpacaFormat::optionSymbol('SPY261120P00750500');

    expect($contract['underlying'])->toBe('SPY')
        ->and($contract['type'])->toBe('Put')
        ->and($contract['expiration']->format('Y-m-d'))->toBe('2026-11-20')
        ->and($contract['strike']->format())->toBe('$750.50')
        ->and(AlpacaFormat::optionSymbol('AAPL'))->toBeNull();
});
