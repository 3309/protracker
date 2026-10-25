<?php

namespace App\Enums;

enum PriceInstrument: string
{
    case Stock = 'stock';
    case Option = 'option';

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Stock',
            self::Option => 'Option',
        };
    }
}
