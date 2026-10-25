<?php

use App\Support\Quantity;

it('parses share quantities exactly, including fractional shares', function (string $input, int $expectedUnits) {
    expect(Quantity::fromDecimal($input)->units)->toBe($expectedUnits);
})->with([
    'whole shares' => ['100', 100_000_000],
    'half a share' => ['0.5', 500_000],
    'smallest fraction' => ['0.000001', 1],
    'thousands separator' => ['1,234.123456', 1_234_123_456],
]);

it('rejects text that is not a quantity with at most six decimal places', function (string $input) {
    Quantity::fromDecimal($input);
})->throws(InvalidArgumentException::class)->with([
    'seven decimal places' => ['0.0000001'],
    'letters' => ['abc'],
    'empty' => [''],
    'scientific notation' => ['1e3'],
    'two decimal points' => ['1.2.3'],
]);

it('creates a quantity from a whole number', function () {
    expect(Quantity::fromInteger(300)->units)->toBe(300_000_000);
});

it('formats quantities without unnecessary decimal places', function (int $units, string $formatted, string $decimal) {
    $quantity = Quantity::fromUnits($units);

    expect($quantity->format())->toBe($formatted)
        ->and($quantity->toDecimal())->toBe($decimal);
})->with([
    'whole shares' => [1_250_000_000, '1,250', '1250'],
    'fractional shares' => [500_000, '0.5', '0.5'],
    'six decimal places' => [1_234_123_456, '1,234.123456', '1234.123456'],
]);

it('reports whether a quantity is zero', function () {
    expect(Quantity::fromDecimal('0.000')->isZero())->toBeTrue()
        ->and(Quantity::fromDecimal('0.000001')->isZero())->toBeFalse();
});
