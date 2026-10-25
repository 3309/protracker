<?php

namespace App\Enums;

enum ImportBatchStatus: string
{
    case Previewed = 'previewed';
    case Committed = 'committed';

    public function label(): string
    {
        return match ($this) {
            self::Previewed => 'Preview, not imported yet',
            self::Committed => 'Imported',
        };
    }
}
