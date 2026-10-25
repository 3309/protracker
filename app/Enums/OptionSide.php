<?php

namespace App\Enums;

/**
 * Whether an option was sold (short) or bought (long).
 *
 * Short options are sold for premium (the wheel). Long options are single
 * options bought to open; they close by selling to close or by expiring
 * worthless.
 */
enum OptionSide: string
{
    case Short = 'short';
    case Long = 'long';

    public function label(): string
    {
        return match ($this) {
            self::Short => 'Short (sold)',
            self::Long => 'Long (bought)',
        };
    }

    /**
     * The events that can close contracts of this side.
     *
     * @return list<OptionEventType>
     */
    public function closingEvents(): array
    {
        return match ($this) {
            self::Short => [OptionEventType::BuyToClose, OptionEventType::Rolled, OptionEventType::Expired, OptionEventType::Assigned],
            self::Long => [OptionEventType::SellToClose, OptionEventType::Expired],
        };
    }
}
