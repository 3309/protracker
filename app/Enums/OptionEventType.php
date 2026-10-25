<?php

namespace App\Enums;

/**
 * Something that happens to an open option position. Phase 5 adds expiry,
 * assignment and rolls.
 */
enum OptionEventType: string
{
    case BuyToClose = 'buy_to_close';

    public function label(): string
    {
        return match ($this) {
            self::BuyToClose => 'Bought to close',
        };
    }
}
