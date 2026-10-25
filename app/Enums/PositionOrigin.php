<?php

namespace App\Enums;

/**
 * How a stock lot or option position came into the app.
 *
 * Opening balances were already held on the reporting start date. Later
 * phases add origins such as purchases and put assignments.
 */
enum PositionOrigin: string
{
    case OpeningBalance = 'opening_balance';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balance',
        };
    }
}
