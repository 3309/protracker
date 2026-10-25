<?php

namespace App\Support;

use RoundingMode;

/**
 * An exact dollar amount per share, such as a price or a dividend rate.
 */
abstract readonly class PerShareAmount extends FixedDecimal
{
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
     * The amount for display, e.g. "$50.00" or "$0.2425".
     */
    public function format(): string
    {
        return $this->formatWith(minimumDecimals: 2, prefix: '$');
    }
}
