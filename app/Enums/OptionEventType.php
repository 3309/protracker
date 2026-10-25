<?php

namespace App\Enums;

/**
 * Something that happens to some or all contracts of an open option.
 */
enum OptionEventType: string
{
    case BuyToClose = 'buy_to_close';
    case Rolled = 'rolled';
    case Expired = 'expired';
    case Assigned = 'assigned';

    public function label(): string
    {
        return match ($this) {
            self::BuyToClose => 'Bought to close',
            self::Rolled => 'Rolled (bought to close)',
            self::Expired => 'Expired worthless',
            self::Assigned => 'Assigned',
        };
    }

    /**
     * Whether contracts were bought back at a price.
     */
    public function isBuyBack(): bool
    {
        return $this === self::BuyToClose || $this === self::Rolled;
    }

    /**
     * How many calendar days after expiration the broker may date this event.
     * Expiry and assignment are often posted on the next trading day, so a
     * few days are allowed; a buy-back must happen by expiration.
     */
    public function daysAllowedAfterExpiration(): int
    {
        return $this->isBuyBack() ? 0 : 7;
    }
}
