<?php

namespace App\Enums;

/**
 * Whether an option was sold (short) or bought (long).
 *
 * Version 1 tracks only short options sold for premium. Long options are out
 * of scope for now; the database column exists so they can be added later.
 */
enum OptionSide: string
{
    case Short = 'short';

    public function label(): string
    {
        return match ($this) {
            self::Short => 'Short (sold)',
        };
    }
}
