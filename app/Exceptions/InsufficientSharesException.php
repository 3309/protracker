<?php

namespace App\Exceptions;

use App\Models\StockTrade;
use App\Support\Quantity;
use Exception;

/**
 * Thrown when a sale needs more shares than the account held on its trade
 * date. Short selling is not supported, so the change is refused.
 */
class InsufficientSharesException extends Exception
{
    public static function forSale(StockTrade $sale, Quantity $available): self
    {
        return new self(sprintf(
            'Not enough %s shares in this account for the sale on %s: %s needed, %s available. Short selling is not supported.',
            $sale->ticker,
            $sale->trade_date->format('M j, Y'),
            $sale->shares->format(),
            $available->format(),
        ));
    }
}
