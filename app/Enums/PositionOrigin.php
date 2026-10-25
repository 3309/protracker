<?php

namespace App\Enums;

/**
 * How a stock lot or option position came into the app.
 *
 * Opening balances were already held on the reporting start date. Purchases
 * come from stock buy trades, put assignments from shares bought at the
 * strike when a sold put was assigned, and sell-to-open and buy-to-open from
 * option trades.
 */
enum PositionOrigin: string
{
    case OpeningBalance = 'opening_balance';
    case Purchase = 'purchase';
    case SellToOpen = 'sell_to_open';
    case BuyToOpen = 'buy_to_open';
    case PutAssignment = 'put_assignment';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balance',
            self::Purchase => 'Purchase',
            self::SellToOpen => 'Sell to open',
            self::BuyToOpen => 'Buy to open',
            self::PutAssignment => 'Put assignment',
        };
    }
}
