<?php

namespace App\Enums;

/**
 * The layout of an uploaded CSV file.
 *
 * Fidelity and Robinhood are listed but not yet supported: their mappings
 * are built only from real sample exports, never guessed.
 */
enum ImportFormatType: string
{
    case Template = 'template';
    case Fidelity = 'fidelity';
    case Robinhood = 'robinhood';

    public function label(): string
    {
        return match ($this) {
            self::Template => 'Protracker template',
            self::Fidelity => 'Fidelity account history',
            self::Robinhood => 'Robinhood account activity',
        };
    }

    public function isSupported(): bool
    {
        return $this === self::Template;
    }
}
