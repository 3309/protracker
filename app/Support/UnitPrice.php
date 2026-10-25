<?php

namespace App\Support;

use RoundingMode;

/**
 * An exact per-share price in dollars, with up to 4 decimal places.
 *
 * Used for stock costs, option strikes and option premiums, which are all
 * quoted per share.
 */
final readonly class UnitPrice extends FixedDecimal
{
    protected static function decimalPlaces(): int
    {
        return 4;
    }

    /**
     * The total for a number of shares, rounded to the nearest cent with
     * exact half cents rounded away from zero.
     */
    public function totalFor(Quantity $quantity): Money
    {
        $cents = $this->toNumber()
            ->mul($quantity->toNumber())
            ->mul(100)
            ->round(0, RoundingMode::HalfAwayFromZero);

        return Money::fromCents((int) $cents->value);
    }

    /**
     * The price for display, e.g. "$50.00" or "$0.2425".
     */
    public function format(): string
    {
        return $this->formatWith(minimumDecimals: 2, prefix: '$');
    }
}
