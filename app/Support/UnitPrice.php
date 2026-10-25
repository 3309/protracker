<?php

namespace App\Support;

/**
 * An exact per-share price in dollars, with up to 4 decimal places.
 *
 * Used for stock costs, option strikes and option premiums, which are all
 * quoted per share.
 */
final readonly class UnitPrice extends PerShareAmount
{
    protected static function decimalPlaces(): int
    {
        return 4;
    }
}
