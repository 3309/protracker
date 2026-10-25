<?php

namespace App\Enums;

/**
 * What is known about, or happened to, one row of an uploaded file.
 */
enum ImportRowStatus: string
{
    case Recognized = 'recognized';
    case Unrecognized = 'unrecognized';
    case Incomplete = 'incomplete';
    case Invalid = 'invalid';
    case Ambiguous = 'ambiguous';
    case PossibleDuplicate = 'possible_duplicate';
    case Duplicate = 'duplicate';
    case Imported = 'imported';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Recognized => 'Ready to import',
            self::Unrecognized => 'Not recognized',
            self::Incomplete => 'Incomplete',
            self::Invalid => 'Invalid value',
            self::Ambiguous => 'Needs a decision',
            self::PossibleDuplicate => 'Possible duplicate of an entry made by hand',
            self::Duplicate => 'Already imported',
            self::Imported => 'Imported',
            self::Skipped => 'Skipped',
            self::Failed => 'Could not be imported',
        };
    }

    /**
     * Whether the row waits in the review queue for a correction or decision.
     */
    public function needsReview(): bool
    {
        return in_array($this, [self::Unrecognized, self::Incomplete, self::Invalid, self::Ambiguous, self::Failed], true);
    }
}
