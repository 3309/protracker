<?php

use App\Support\Quantity;
use App\Support\UnitPrice;

it('parses per-share prices exactly with up to four decimal places', function (string $input, int $expectedUnits) {
    expect(UnitPrice::fromDecimal($input)->units)->toBe($expectedUnits);
})->with([
    'whole dollars' => ['50', 500_000],
    'dividend-style price' => ['0.2425', 2_425],
    'dollar sign' => ['$52.25', 522_500],
]);

it('rejects a price with more than four decimal places', function () {
    UnitPrice::fromDecimal('1.23456');
})->throws(InvalidArgumentException::class);

it('formats prices with at least two decimal places', function (int $units, string $expected) {
    expect(UnitPrice::fromUnits($units)->format())->toBe($expected);
})->with([
    [500_000, '$50.00'],
    [2_425, '$0.2425'],
    [12_345_000, '$1,234.50'],
]);

it('works out the total for a number of shares, rounded to the cent', function (string $price, string $shares, int $expectedCents) {
    expect(UnitPrice::fromDecimal($price)->totalFor(Quantity::fromDecimal($shares))->cents)->toBe($expectedCents);
})->with([
    '100 shares at $50' => ['50', '100', 500_000],
    'one contract of premium at $1.20' => ['1.20', '100', 12_000],
    '$0.2425 on 33 shares' => ['0.2425', '33', 800],
    'half a cent rounds away from zero' => ['0.005', '1', 1],
    'fractional shares' => ['180.25', '0.5', 9_013],
]);

it('does not treat a price and a quantity with the same digits as equal', function () {
    expect(UnitPrice::fromUnits(100)->equals(Quantity::fromUnits(100)))->toBeFalse()
        ->and(UnitPrice::fromUnits(100)->equals(UnitPrice::fromUnits(100)))->toBeTrue();
});
