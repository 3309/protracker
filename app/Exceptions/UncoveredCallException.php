<?php

namespace App\Exceptions;

use App\Support\CoveredCallCapacity;
use Exception;

/**
 * Thrown when sold calls would need more shares than the account holds.
 * Shares can cover only one sold call at a time, so the change is refused.
 */
class UncoveredCallException extends Exception
{
    public static function on(CoveredCallCapacity $capacity): self
    {
        return new self(sprintf(
            'Not enough %s shares in this account to cover the calls sold: on %s the open calls would need %s shares, but %s are held. Shares already covering a call cannot cover another one.',
            $capacity->ticker,
            $capacity->date->format('M j, Y'),
            $capacity->committed()->format(),
            $capacity->held->format(),
        ));
    }
}
