<?php

namespace App\Actions\Options;

use App\Actions\Stocks\SaveStockTrade;
use App\Enums\OptionEventType;
use App\Enums\OptionSide;
use App\Enums\OptionType;
use App\Enums\PositionOrigin;
use App\Enums\TradeSide;
use App\Exceptions\InsufficientSharesException;
use App\Exceptions\OptionPositionException;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Support\MarketCalendar;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Records, corrects and voids option trades and lifecycle events: sell to
 * open, buy to close, expiry, assignment and rolls for sold options; buy to
 * open, sell to close and expiry for bought options.
 *
 * An assignment also records the stock trade at the strike through the
 * stock engine: a put assignment buys the shares (creating a lot), a call
 * assignment sells them oldest first. The stock trade is changed only
 * through its assignment.
 *
 * Every change runs in one database transaction: if it would leave a
 * position or the stock lots inconsistent, nothing is saved.
 */
class SaveOptionTrade
{
    public function __construct(
        private RebuildOptionPosition $rebuildOptionPosition,
        private SaveStockTrade $saveStockTrade,
    ) {}

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
     * Buy an option to open (a long call or put). Nothing is realized until
     * the contracts are sold to close or expire.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function buyToOpen(array $attributes): OptionPosition
    {
        return OptionPosition::create([
            ...$attributes,
            'side' => OptionSide::Long,
            'origin' => PositionOrigin::BuyToOpen,
        ]);
    }

    /**
     * Sell some or all open contracts of a bought option.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws OptionPositionException
     */
    public function sellToClose(OptionPosition $position, array $attributes): OptionEvent
    {
        return $this->recordEvent($position, OptionEventType::SellToClose, $attributes);
    }

    /**
     * Correct the opening details of a position. Recorded events are
     * rechecked, their profit recalculated, and the stock trades of any
     * assignments brought into line (for example a corrected strike).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws OptionPositionException
     * @throws InsufficientSharesException
     */
    public function updatePosition(OptionPosition $position, array $attributes, string $reason): void
    {
        $this->rebuildOptionPosition->afterChange($position, function () use ($position, $attributes, $reason): void {
            $position->withAuditReason($reason)->fill($attributes);

            $assignments = $position->events()->notVoided()->where('event_type', OptionEventType::Assigned)->get();

            if ($position->isDirty('option_type') && $assignments->isNotEmpty()) {
                throw OptionPositionException::typeChangeWithAssignments($position);
            }

            $position->save();

            foreach ($assignments as $assignment) {
                $assignment->setRelation('position', $position);
                $this->syncAssignmentTrade($assignment, $reason);
            }
        });
    }

    /**
     * Void a position. A leg opened by a roll is voided together with the
     * buy-back that rolled it, which reopens the earlier leg's contracts.
     *
     * @throws OptionPositionException
     */
    public function voidPosition(OptionPosition $position, string $reason): void
    {
        if ($position->events()->notVoided()->exists()) {
            throw $position->rolled_from_event_id !== null
                ? OptionPositionException::rollLegHasEvents($position)
                : OptionPositionException::hasRecordedCloses($position);
        }

        DB::transaction(function () use ($position, $reason): void {
            $position->void($reason);

            $rolledFrom = $position->rolledFromEvent;

            if ($rolledFrom !== null && ! $rolledFrom->isVoided()) {
                $this->rebuildOptionPosition->afterChange($rolledFrom->position, fn () => $rolledFrom->void($reason));
            }
        });
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
        return $this->recordEvent($position, OptionEventType::BuyToClose, $attributes);
    }

    /**
     * Record that some or all of the open contracts expired worthless.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws OptionPositionException
     */
    public function expire(OptionPosition $position, array $attributes): OptionEvent
    {
        return $this->recordEvent($position, OptionEventType::Expired, [
            ...$attributes,
            'price_per_share' => null,
            'fees' => Money::zero(),
            'settlement_date' => null,
        ]);
    }

    /**
     * Record that some or all of the open contracts were assigned, and the
     * stock buy (put) or sale (call) at the strike that it caused. The fee is
     * charged on the stock trade.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws OptionPositionException
     * @throws InsufficientSharesException
     */
    public function assign(OptionPosition $position, array $attributes, Money $stockFee): OptionEvent
    {
        $this->ensureNotVoided($position);

        $event = $this->rebuildOptionPosition->afterChange($position, function () use ($position, $attributes, $stockFee): OptionEvent {
            $event = $position->events()->create([
                ...$attributes,
                'account_id' => $position->account_id,
                'event_type' => OptionEventType::Assigned,
                'price_per_share' => null,
                'fees' => Money::zero(),
                'settlement_date' => null,
            ]);

            $event->setRelation('position', $position);

            $this->saveStockTrade->create([
                'account_id' => $position->account_id,
                'option_event_id' => $event->id,
                'side' => $position->option_type === OptionType::Put ? TradeSide::Buy : TradeSide::Sell,
                'fees' => $stockFee,
                ...$this->assignmentTradeAttributes($event),
            ]);

            return $event;
        });

        return $event->refresh();
    }

    /**
     * Roll: buy back some or all open contracts and sell a new option of the
     * same ticker and type in the same account, on the same date. Both legs
     * join the roll chain.
     *
     * @param  array<string, mixed>  $closeAttributes
     * @param  array<string, mixed>  $newLegAttributes
     *
     * @throws OptionPositionException
     */
    public function roll(OptionPosition $position, array $closeAttributes, array $newLegAttributes): OptionPosition
    {
        $this->ensureNotVoided($position);

        return DB::transaction(function () use ($position, $closeAttributes, $newLegAttributes): OptionPosition {
            $buyBack = $this->recordEvent($position, OptionEventType::Rolled, $closeAttributes);

            $chainId = $position->roll_chain_id ?? $position->id;

            if ($position->roll_chain_id === null) {
                OptionPosition::query()->whereKey($position->id)->update(['roll_chain_id' => $chainId]);
                $position->forceFill(['roll_chain_id' => $chainId])->syncOriginalAttribute('roll_chain_id');
            }

            return OptionPosition::create([
                ...$newLegAttributes,
                'account_id' => $position->account_id,
                'underlying' => $position->underlying,
                'option_type' => $position->option_type,
                'side' => OptionSide::Short,
                'origin' => PositionOrigin::SellToOpen,
                'rolled_from_event_id' => $buyBack->id,
                'roll_chain_id' => $chainId,
            ]);
        });
    }

    /**
     * Correct an event. For an assignment, the stock trade it created is
     * corrected too.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws OptionPositionException
     * @throws InsufficientSharesException
     */
    public function updateEvent(OptionEvent $event, array $attributes, string $reason, ?Money $stockFee = null): void
    {
        $this->rebuildOptionPosition->afterChange($event->position, function () use ($event, $attributes, $reason, $stockFee): void {
            $event->withAuditReason($reason)->update($attributes);

            if ($event->event_type === OptionEventType::Assigned) {
                $this->syncAssignmentTrade($event, $reason, $stockFee);
            }
        });

        $event->refresh();
    }

    /**
     * Void an event, reopening its contracts. Voiding an assignment also voids
     * its stock trade; a roll's buy-back is voided by voiding the new leg.
     *
     * @throws OptionPositionException
     * @throws InsufficientSharesException
     */
    public function voidEvent(OptionEvent $event, string $reason): void
    {
        if ($event->event_type === OptionEventType::Rolled && $event->rolledInto !== null && ! $event->rolledInto->isVoided()) {
            throw OptionPositionException::rollVoidedFromNewLeg();
        }

        $this->rebuildOptionPosition->afterChange($event->position, function () use ($event, $reason): void {
            $event->void($reason);

            $stockTrade = $event->stockTrade;

            if ($stockTrade !== null && ! $stockTrade->isVoided()) {
                $this->saveStockTrade->void($stockTrade, $reason);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws OptionPositionException
     */
    private function recordEvent(OptionPosition $position, OptionEventType $type, array $attributes): OptionEvent
    {
        $this->ensureNotVoided($position);

        $event = $this->rebuildOptionPosition->afterChange($position, fn () => $position->events()->create([
            ...$attributes,
            'account_id' => $position->account_id,
            'event_type' => $type,
        ]));

        return $event->refresh();
    }

    /**
     * @throws OptionPositionException
     */
    private function ensureNotVoided(OptionPosition $position): void
    {
        if ($position->isVoided()) {
            throw OptionPositionException::voided($position);
        }
    }

    /**
     * Bring an assignment's stock trade into line with the assignment and its
     * position.
     *
     * @throws InsufficientSharesException
     */
    private function syncAssignmentTrade(OptionEvent $assignment, string $reason, ?Money $stockFee = null): void
    {
        $stockTrade = $assignment->stockTrade;

        if ($stockTrade === null || $stockTrade->isVoided()) {
            return;
        }

        $this->saveStockTrade->update($stockTrade, [
            ...$this->assignmentTradeAttributes($assignment),
            ...($stockFee !== null ? ['fees' => $stockFee] : []),
        ], $reason);
    }

    /**
     * The stock trade an assignment causes: contracts × multiplier shares at
     * the strike, settling one trading day after the assignment date.
     *
     * @return array<string, mixed>
     */
    private function assignmentTradeAttributes(OptionEvent $assignment): array
    {
        $position = $assignment->position;

        return [
            'ticker' => $position->underlying,
            'trade_date' => $assignment->event_date,
            'settlement_date' => MarketCalendar::stockSettlementDate($assignment->event_date),
            'settlement_date_overridden' => false,
            'shares' => Quantity::fromInteger($assignment->contracts * $position->multiplier),
            'price_per_share' => $position->strike,
            'reference_note' => "Assignment of {$position->description()}",
        ];
    }
}
