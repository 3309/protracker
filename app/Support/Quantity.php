<?php

namespace App\Support;

/**
 * An exact number of shares, with up to 6 decimal places so fractional
 * shares (for example from Robinhood) are kept exactly.
 */
final readonly class Quantity extends FixedDecimal
{
    protected static function decimalPlaces(): int
    {
        return 6;
    }

    /**
     * The quantity for display, e.g. "1,250" or "0.5".
     */
    public function format(): string
    {
        return $this->formatWith(minimumDecimals: 0, prefix: '');
    }
}
