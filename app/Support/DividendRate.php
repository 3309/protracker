<?php

namespace App\Support;

/**
 * An exact dividend amount per share in dollars, with up to 6 decimal places
 * (some funds and foreign shares pay amounts such as $0.123456).
 */
final readonly class DividendRate extends PerShareAmount
{
    protected static function decimalPlaces(): int
    {
        return 6;
    }
}
