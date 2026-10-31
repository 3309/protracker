<?php

namespace App\Enums;

enum PriceSource: string
{
    case Manual = 'manual';
    case Tiingo = 'tiingo';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Entered by hand',
            self::Tiingo => 'Tiingo (IEX)',
        };
    }
}
