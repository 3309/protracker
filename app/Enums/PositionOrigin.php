<?php

namespace App\Enums;

/**
 * How a stock lot or option position came into the app.
 *
 * Opening balances were already held on the reporting start date. Purchases
 * come from stock buy trades. Later phases add origins such as put
 * assignments.
 */
enum PositionOrigin: string
{
    case OpeningBalance = 'opening_balance';
    case Purchase = 'purchase';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balance',
            self::Purchase => 'Purchase',
        };
    }
}
