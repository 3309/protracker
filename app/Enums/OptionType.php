<?php

namespace App\Enums;

enum OptionType: string
{
    case Put = 'put';
    case Call = 'call';

    public function label(): string
    {
        return match ($this) {
            self::Put => 'Put',
            self::Call => 'Call',
        };
    }
}
