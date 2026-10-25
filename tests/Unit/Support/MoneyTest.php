<?php

use App\Support\Money;

it('parses dollar text into exact cents', function (string $input, int $expectedCents) {
    expect(Money::fromDecimal($input)->cents)->toBe($expectedCents);
})->with([
    'plain amount' => ['1.20', 120],
    'one decimal place' => ['0.6', 60],
    'whole dollars' => ['5000', 500000],
    'dollar sign and thousands separator' => ['$1,234.56', 123456],
    'negative amount' => ['-71.34', -7134],
    'zero' => ['0', 0],
    'surrounding spaces' => ['  0.67 ', 67],
]);

it('rejects text that is not a dollar amount with at most two decimal places', function (string $input) {
    Money::fromDecimal($input);
})->throws(InvalidArgumentException::class)->with([
    'fraction of a cent' => ['0.675'],
    'letters' => ['abc'],
    'empty' => [''],
    'two decimal points' => ['1.2.3'],
    'scientific notation' => ['1e3'],
]);

it('multiplies the per-contract option fee by the number of contracts', function () {
    expect(Money::fromDecimal('0.67')->times(3)->cents)->toBe(201);
});

it('rounds fractions of a cent half away from zero', function (string $amount, string $factor, int $expectedCents) {
    expect(Money::fromDecimal($amount)->times($factor)->cents)->toBe($expectedCents);
})->with([
    'rounds down below a half cent' => ['0.01', '0.4', 0],
    'rounds an exact half cent up' => ['0.01', '0.5', 1],
    'rounds a negative exact half cent away from zero' => ['-0.01', '0.5', -1],
    'dividend of $0.2425 on 33 shares' => ['33', '0.2425', 800],
]);

it('rejects a floating-point multiplier instead of silently converting it', function () {
    Money::fromCents(100)->times(0.1);
})->throws(InvalidArgumentException::class, 'not a floating-point number');

it('rejects a multiplier that is not a number', function () {
    Money::fromCents(100)->times('abc');
})->throws(InvalidArgumentException::class, '[abc] is not a number.');

it('adds and subtracts exactly', function () {
    $cashFlow = Money::fromDecimal('-5000')
        ->plus(Money::fromDecimal('120'))
        ->plus(Money::fromDecimal('80'))
        ->plus(Money::fromDecimal('50'))
        ->plus(Money::fromDecimal('5200'))
        ->minus(Money::fromDecimal('1.34'));

    expect($cashFlow->toDecimal())->toBe('448.66');
});

it('formats amounts for display', function (int $cents, string $expected) {
    expect(Money::fromCents($cents)->format())->toBe($expected);
})->with([
    [123456, '$1,234.56'],
    [-7134, '-$71.34'],
    [5, '$0.05'],
    [0, '$0.00'],
]);

it('converts back to a plain decimal string', function (int $cents, string $expected) {
    expect(Money::fromCents($cents)->toDecimal())->toBe($expected);
})->with([
    [120, '1.20'],
    [-7134, '-71.34'],
    [5, '0.05'],
]);
