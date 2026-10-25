<?php

use App\Support\DividendRate;
use App\Support\Quantity;

it('keeps up to six decimal places per share', function () {
    expect(DividendRate::fromDecimal('0.123456')->units)->toBe(123_456)
        ->and(DividendRate::fromDecimal('0.123456')->format())->toBe('$0.123456')
        ->and(DividendRate::fromDecimal('0.5')->format())->toBe('$0.50');
});

it('rejects more than six decimal places', function () {
    DividendRate::fromDecimal('0.1234567');
})->throws(InvalidArgumentException::class);

it('works out the dividend for a number of shares, rounded to the cent', function (string $rate, string $shares, string $expected) {
    expect(DividendRate::fromDecimal($rate)->totalFor(Quantity::fromDecimal($shares))->format())->toBe($expected);
})->with([
    'Example E' => ['0.50', '100', '$50.00'],
    'six decimal places' => ['0.123456', '33', '$4.07'],
    'fractional shares' => ['0.25', '0.5', '$0.13'],
]);
