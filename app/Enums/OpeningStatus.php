<?php

namespace App\Enums;

use App\Models\AccountOpening;

/**
 * How far the opening-balance setup of one account has got.
 */
enum OpeningStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Complete = 'complete';

    /**
     * Work out the status from the account's opening record and the number
     * of opening stock lots and option positions that have not been voided.
     */
    public static function determine(?AccountOpening $opening, int $openEntryCount): self
    {
        if ($opening?->isComplete()) {
            return self::Complete;
        }

        if ($opening?->hasCash() || $openEntryCount > 0) {
            return self::InProgress;
        }

        return self::NotStarted;
    }

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::Complete => 'Complete',
        };
    }
}
