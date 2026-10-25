<?php

namespace App\Actions\Options;

use App\Enums\OptionEventType;
use App\Enums\OptionSide;
use App\Enums\PositionOrigin;
use App\Exceptions\OptionPositionException;
use App\Models\OptionEvent;
use App\Models\OptionPosition;

/**
 * Records, corrects and voids sell-to-open trades and buy-to-close events,
 * recalculating each closed portion's realized profit afterwards.
 *
 * Every change runs in one database transaction: if it would leave the
 * position inconsistent (for example more contracts closed than opened),
 * nothing is saved.
 */
class SaveOptionTrade
{
    public function __construct(private RebuildOptionPosition $rebuildOptionPosition) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function open(array $attributes): OptionPosition
    {
        return OptionPosition::create([
            ...$attributes,
            'side' => OptionSide::Short,
            'origin' => PositionOrigin::SellToOpen,
        ]);
    }

    /**
     * Correct the opening details of a position. Closes already recorded are
     * rechecked and their profit recalculated.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws OptionPositionException
     */
    public function updatePosition(OptionPosition $position, array $attributes, string $reason): void
    {
        $this->rebuildOptionPosition->afterChange($position, fn () => $position->withAuditReason($reason)->update($attributes));
    }

    /**
     * @throws OptionPositionException
     */
    public function voidPosition(OptionPosition $position, string $reason): void
    {
        if ($position->events()->notVoided()->exists()) {
            throw OptionPositionException::hasRecordedCloses($position);
        }

        $position->void($reason);
    }

    /**
     * Buy back some or all of the open contracts.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws OptionPositionException
     */
    public function buyToClose(OptionPosition $position, array $attributes): OptionEvent
    {
        if ($position->isVoided()) {
            throw OptionPositionException::voided($position);
        }

        $event = $this->rebuildOptionPosition->afterChange($position, fn () => $position->events()->create([
            ...$attributes,
            'account_id' => $position->account_id,
            'event_type' => OptionEventType::BuyToClose,
        ]));

        return $event->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws OptionPositionException
     */
    public function updateEvent(OptionEvent $event, array $attributes, string $reason): void
    {
        $this->rebuildOptionPosition->afterChange($event->position, fn () => $event->withAuditReason($reason)->update($attributes));

        $event->refresh();
    }

    public function voidEvent(OptionEvent $event, string $reason): void
    {
        $this->rebuildOptionPosition->afterChange($event->position, fn () => $event->void($reason));
    }
}
