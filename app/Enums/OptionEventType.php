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
    case SellToClose = 'sell_to_close';

    public function label(): string
    {
        return match ($this) {
            self::BuyToClose => 'Bought to close',
            self::Rolled => 'Rolled (bought to close)',
            self::Expired => 'Expired worthless',
            self::Assigned => 'Assigned',
            self::SellToClose => 'Sold to close',
        };
    }

    /**
     * Whether contracts were closed at a price (bought back or sold).
     */
    public function closesAtPrice(): bool
    {
        return $this->isBuyBack() || $this === self::SellToClose;
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
     * few days are allowed; a buy-back or sale must happen by expiration.
     */
    public function daysAllowedAfterExpiration(): int
    {
        return $this->closesAtPrice() ? 0 : 7;
    }
}
