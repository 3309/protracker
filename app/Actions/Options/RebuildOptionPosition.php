<?php

namespace App\Actions\Options;

use App\Enums\OptionEventType;
use App\Exceptions\OptionPositionException;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Support\Money;
use App\Support\Quantity;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Checks an option position's events and works out the part of the opening
 * premium and opening fees that belongs to the contracts each event closed.
 *
 * Events are taken in date order. The premium and fees are split by
 * contract with running totals, so the pieces always add up exactly to the
 * whole premium and fees once every contract has closed.
 */
class RebuildOptionPosition
{
    /**
     * Make a change to a position or its events, then recheck and
     * recalculate. If the result is inconsistent, the whole change is rolled
     * back.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult
     *
     * @throws OptionPositionException
     */
    public function afterChange(OptionPosition $position, Closure $change): mixed
    {
        return DB::transaction(function () use ($position, $change) {
            $result = $change();

            $this->handle($position);

            return $result;
        });
    }

    /**
     * @throws OptionPositionException
     */
    public function handle(OptionPosition $position): void
    {
        $events = $position->events()->notVoided()->orderBy('event_date')->orderBy('id')->get();

        $totalClosed = (int) $events->sum('contracts');

        if ($totalClosed > $position->contracts) {
            throw OptionPositionException::tooManyContractsClosed($position, $totalClosed);
        }

        $closedBefore = 0;

        foreach ($events as $event) {
            $eventDate = $event->event_date->format('Y-m-d');
            $latestDate = $position->expires_on->addDays($event->event_type->daysAllowedAfterExpiration())->format('Y-m-d');

            if ($eventDate < $position->opened_on->format('Y-m-d') || $eventDate > $latestDate) {
                throw OptionPositionException::closedOutsideLifetime($position, $event);
            }

            if ($event->event_type === OptionEventType::Expired && $eventDate < $position->expires_on->format('Y-m-d')) {
                throw OptionPositionException::expiredEarly($position);
            }

            $closedAfter = $closedBefore + $event->contracts;

            OptionEvent::query()->whereKey($event->id)->update([
                'allocated_premium' => $this->premiumFor($position, $closedAfter)->minus($this->premiumFor($position, $closedBefore))->cents,
                'allocated_opening_fees' => $position->opening_fees->share($closedAfter, $position->contracts)
                    ->minus($position->opening_fees->share($closedBefore, $position->contracts))->cents,
            ]);

            $closedBefore = $closedAfter;
        }
    }

    /**
     * The premium received for the first $contracts contracts.
     */
    private function premiumFor(OptionPosition $position, int $contracts): Money
    {
        return $position->premium_per_share->totalFor(Quantity::fromInteger($contracts * $position->multiplier));
    }
}
