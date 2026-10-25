<?php

namespace App\Enums;

enum AccountType: string
{
    case Taxable = 'taxable';
    case Ira = 'ira';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Taxable => 'Taxable',
            self::Ira => 'IRA',
            self::Other => 'Other',
        };
    }
}
