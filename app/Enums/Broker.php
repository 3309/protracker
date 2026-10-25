<?php

namespace App\Enums;

enum Broker: string
{
    case Fidelity = 'fidelity';
    case Robinhood = 'robinhood';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fidelity => 'Fidelity',
            self::Robinhood => 'Robinhood',
            self::Other => 'Other',
        };
    }
}
