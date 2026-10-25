<?php

namespace App\Enums;

/**
 * Where an opening balance figure came from.
 */
enum OpeningBalanceSource: string
{
    case ManualEntry = 'manual_entry';
    case BrokerStatement = 'broker_statement';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ManualEntry => 'Manual entry',
            self::BrokerStatement => 'Broker statement',
            self::Other => 'Other',
        };
    }
}
