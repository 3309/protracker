<?php

namespace App\Exceptions;

use App\Models\OptionEvent;
use App\Models\OptionPosition;
use Exception;

/**
 * Thrown when a change would leave an option position inconsistent, for
 * example closing more contracts than are open. The change is refused.
 */
class OptionPositionException extends Exception
{
    /**
     * @param  string  $field  the form field the problem belongs to
     */
    public function __construct(string $message, public readonly string $field = 'contracts')
    {
        parent::__construct($message);
    }

    public static function voided(OptionPosition $position): self
    {
        return new self("{$position->description()} was voided, so it cannot be closed.");
    }

    public static function tooManyContractsClosed(OptionPosition $position, int $closedContracts): self
    {
        return new self(sprintf(
            '%s has %d %s, but %d would be closed. Close no more contracts than are open.',
            $position->description(),
            $position->contracts,
            $position->contracts === 1 ? 'contract' : 'contracts',
            $closedContracts,
        ));
    }

    public static function closedOutsideLifetime(OptionPosition $position, OptionEvent $event): self
    {
        return new self(sprintf(
            'A close on %s is outside the life of %s, which was opened on %s. Closes must be on or after the opening date and on or before expiration.',
            $event->event_date->format('M j, Y'),
            $position->description(),
            $position->opened_on->format('M j, Y'),
        ), 'event_date');
    }

    public static function hasRecordedCloses(OptionPosition $position): self
    {
        return new self("{$position->description()} has recorded closes. Void those first, then void the position.", 'reason');
    }
}
