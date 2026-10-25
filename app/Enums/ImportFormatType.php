<?php

namespace App\Enums;

/**
 * The layout of an uploaded CSV file.
 *
 * Broker mappings are built only from real sample exports, never guessed:
 * Fidelity is built from a sample activity file; Robinhood is listed but
 * not yet supported.
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
            self::Fidelity => 'Fidelity activity',
            self::Robinhood => 'Robinhood account activity',
        };
    }

    public function isSupported(): bool
    {
        return $this !== self::Robinhood;
    }
}
