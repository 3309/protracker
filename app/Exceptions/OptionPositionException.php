<?php

namespace App\Exceptions;

use App\Enums\OptionEventType;
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
        $rule = match (true) {
            $event->event_type->daysAllowedAfterExpiration() > 0 => 'An expiry or assignment must be on or after the opening date and no more than '.$event->event_type->daysAllowedAfterExpiration().' days after expiration.',
            $event->event_type === OptionEventType::SellToClose => 'A sale to close must be on or after the opening date and on or before expiration.',
            default => 'A buy-back must be on or after the opening date and on or before expiration.',
        };

        return new self(sprintf(
            'The event on %s is outside the life of %s, which was opened on %s. %s',
            $event->event_date->format('M j, Y'),
            $position->description(),
            $position->opened_on->format('M j, Y'),
            $rule,
        ), 'event_date');
    }

    public static function expiredEarly(OptionPosition $position): self
    {
        return new self($position->isLong()
            ? "{$position->description()} cannot expire before its expiration date. Record a sell to close instead."
            : "{$position->description()} cannot expire before its expiration date. Record a buy to close or an early assignment instead.", 'event_date');
    }

    public static function eventNotAllowed(OptionPosition $position, OptionEvent $event): self
    {
        return new self($position->isLong()
            ? "{$position->description()} was bought, so it can only be sold to close or expire worthless, not {$event->event_type->label()}."
            : "{$position->description()} was sold, so it cannot be {$event->event_type->label()}.", 'reason');
    }

    public static function typeChangeWithAssignments(OptionPosition $position): self
    {
        return new self("{$position->description()} has recorded assignments. Void them before changing put or call.", 'option_type');
    }

    public static function rollVoidedFromNewLeg(): self
    {
        return new self('This buy-back is part of a roll. To undo the roll, void the new option it opened; both are voided together.', 'reason');
    }

    public static function rollLegHasEvents(OptionPosition $position): self
    {
        return new self("{$position->description()} has its own closes, expiries or assignments. Void those first, then void the roll.", 'reason');
    }

    public static function hasRecordedCloses(OptionPosition $position): self
    {
        return new self("{$position->description()} has recorded closes. Void those first, then void the position.", 'reason');
    }
}
