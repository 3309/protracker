<?php

namespace App\Enums;

/**
 * How a wheel cycle began: by selling a put while holding nothing, or by
 * selling a call on shares already held.
 */
enum WheelCycleKind: string
{
    case Put = 'put';
    case CoveredCall = 'covered_call';

    public function label(): string
    {
        return match ($this) {
            self::Put => 'Started with a put',
            self::CoveredCall => 'Covered call on shares held',
        };
    }
}
