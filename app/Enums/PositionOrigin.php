<?php

namespace App\Enums;

/**
 * How a stock lot or option position came into the app.
 *
 * Opening balances were already held on the reporting start date. Purchases
 * come from stock buy trades and sell-to-open from option trades. Later
 * phases add origins such as put assignments.
 */
enum PositionOrigin: string
{
    case OpeningBalance = 'opening_balance';
    case Purchase = 'purchase';
    case SellToOpen = 'sell_to_open';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balance',
            self::Purchase => 'Purchase',
            self::SellToOpen => 'Sell to open',
        };
    }
}
