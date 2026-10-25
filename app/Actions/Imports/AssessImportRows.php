<?php

namespace App\Actions\Imports;

use App\Enums\ImportAction;
use App\Enums\ImportRowStatus;
use App\Enums\OptionEventType;
use App\Enums\OptionSide;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\ImportRow;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Models\Setting;
use App\Models\StockTrade;
use App\Support\Imports\RowInterpreter;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Decides what each row of an uploaded file is and whether it is safe to
 * import:
 * - checks its values for its action;
 * - marks rows already imported before (same broker transaction ID, or the
 *   same fingerprint) as duplicates, so re-importing a file adds nothing;
 * - flags identical rows within one file for a decision rather than
 *   dropping them, since they may be separate fills;
 * - flags rows that match an entry made by hand as possible duplicates;
 * - flags closes, expiries and assignments with no open option to act on;
 * - pairs an assignment with the stock row the broker lists for it, so the
 *   stock trade is recorded once, by the assignment.
 */
class AssessImportRows
{
    /**
     * Assess every row of a newly read file.
     *
     * @param  Collection<int, ImportRow>  $rows  the batch's rows, in file order
     */
    public function batch(Collection $rows): void
    {
        foreach ($rows as $row) {
            $this->interpret($row);
        }

        foreach ($rows as $row) {
            $this->assess($row, $rows);
        }

        $this->flagIdenticalRows($rows);
        $this->pairAssignments($rows);

        foreach ($rows as $row) {
            $row->save();
        }
    }

    /**
     * Assess one row again after it was corrected in the review queue.
     */
    public function row(ImportRow $row): void
    {
        $row->paired_row_id = null;
        $this->interpret($row);
        $this->assess($row, $row->batch->rows()->whereKeyNot($row->id)->get());
        $row->save();
    }

    private function interpret(ImportRow $row): void
    {
        $result = RowInterpreter::check($row->action, $row->data, Setting::current()->reporting_start_date->format('Y-m-d'));

        $row->status = $result['status'];
        $row->messages = $row->action === null ? $row->messages : $result['messages'];
        $row->fingerprint = $row->action !== null ? RowInterpreter::fingerprint($row->batch->account_id, $row->action, $row->data) : null;
        $row->broker_reference = $row->value('transaction_id') !== '' ? $row->value('transaction_id') : null;
    }

    /**
     * @param  Collection<int, ImportRow>  $batchRows
     */
    private function assess(ImportRow $row, Collection $batchRows): void
    {
        if (! in_array($row->status, [ImportRowStatus::Recognized, ImportRowStatus::Ambiguous], true) || $row->action === null) {
            return;
        }

        $earlierImport = $this->earlierImport($row);

        if ($earlierImport !== null) {
            $row->status = ImportRowStatus::Duplicate;
            $row->messages = ["Already imported from {$earlierImport->batch->original_filename} (row {$earlierImport->row_number}). It is not imported again."];

            return;
        }

        if ($row->action->closesOption() && ! $this->hasOptionToClose($row, $batchRows)) {
            $row->status = ImportRowStatus::Ambiguous;
            $row->messages = ['No open option matches this contract in this account (ticker, put or call, strike and expiration). Enter or import the sale first, or correct the row.'];

            return;
        }

        if ($row->status === ImportRowStatus::Recognized && $this->matchesManualEntry($row)) {
            $row->status = ImportRowStatus::PossibleDuplicate;
            $row->messages = ['An entry made by hand matches this row. It will be skipped unless you choose to import it anyway.'];
        }
    }

    private function earlierImport(ImportRow $row): ?ImportRow
    {
        $accountId = $row->batch->account_id;

        return ImportRow::query()
            ->with('batch')
            ->whereKeyNot($row->id ?? 0)
            ->where('status', ImportRowStatus::Imported)
            ->whereHas('batch', fn (Builder $batch) => $batch->where('account_id', $accountId))
            ->where(fn (Builder $query) => $row->broker_reference !== null
                ? $query->where('broker_reference', $row->broker_reference)
                : $query->where('fingerprint', $row->fingerprint))
            ->first();
    }

    /**
     * @param  Collection<int, ImportRow>  $batchRows
     */
    private function hasOptionToClose(ImportRow $row, Collection $batchRows): bool
    {
        $openPosition = $this->contractQuery(OptionPosition::query()->short(), $row, 'underlying')
            ->where('account_id', $row->batch->account_id)
            ->notVoided()
            ->open()
            ->exists();

        if ($openPosition) {
            return true;
        }

        return $batchRows->contains(fn (ImportRow $other) => $other->id !== $row->id
            && $other->action === ImportAction::SellToOpen
            && $other->status !== ImportRowStatus::Duplicate
            && $this->sameContract($row, $other)
            && $other->value('date') <= $row->value('date'));
    }

    private function matchesManualEntry(ImportRow $row): bool
    {
        $action = $row->action;
        $accountId = $row->batch->account_id;
        $date = $row->value('date');

        $query = match (true) {
            $action === null => null,
            $action->isStockTrade() => StockTrade::query()
                ->where('ticker', $row->value('symbol'))
                ->where('side', $action === ImportAction::Buy ? TradeSide::Buy : TradeSide::Sell)
                ->where('trade_date', $date)
                ->where('shares', Quantity::fromDecimal(ltrim($row->value('quantity'), '-'))->units),
            $action === ImportAction::SellToOpen => $this->contractQuery(OptionPosition::query()->short(), $row, 'underlying')
                ->where('opened_on', $date)
                ->where('contracts', (int) ltrim($row->value('quantity'), '-')),
            $action->closesOption() => OptionEvent::query()
                ->whereIn('event_type', match ($action) {
                    ImportAction::BuyToClose => [OptionEventType::BuyToClose, OptionEventType::Rolled],
                    ImportAction::Expired => [OptionEventType::Expired],
                    default => [OptionEventType::Assigned],
                })
                ->where('event_date', $date)
                ->where('contracts', (int) ltrim($row->value('quantity'), '-'))
                ->whereHas('position', fn (Builder $position) => $this->contractQuery($position->where('side', OptionSide::Short), $row, 'underlying')),
            $action === ImportAction::Dividend => Dividend::query()->where('ticker', $row->value('symbol'))->where('pay_date', $date),
            default => CashMovement::query()
                ->where('movement_type', $action->cashMovementType())
                ->where('movement_date', $date)
                ->where('amount', abs(Money::fromDecimal($row->value('amount'))->cents)),
        };

        if ($query === null) {
            return false;
        }

        $morphAlias = (string) array_search($query->getModel()::class, Relation::morphMap(), true);

        return $query
            ->where('account_id', $accountId)
            ->whereNull('voided_at')
            ->whereNotIn('id', ImportRow::query()->where('imported_type', $morphAlias)->whereNotNull('imported_id')->select('imported_id'))
            ->exists();
    }

    /**
     * @param  Collection<int, ImportRow>  $rows
     */
    private function flagIdenticalRows(Collection $rows): void
    {
        $candidates = $rows->filter(fn (ImportRow $row) => $row->fingerprint !== null
            && in_array($row->status, [ImportRowStatus::Recognized, ImportRowStatus::PossibleDuplicate], true));

        foreach ($candidates->groupBy('fingerprint') as $group) {
            $first = $group->first();

            foreach ($group->slice(1) as $copy) {
                if ($copy->broker_reference !== null && $copy->broker_reference !== $first?->broker_reference) {
                    continue;
                }

                $copy->status = ImportRowStatus::Ambiguous;
                $copy->messages = ["Identical to row {$first?->row_number} in this file. If both really happened (for example two separate fills), choose Import anyway; otherwise skip it."];
            }
        }
    }

    /**
     * @param  Collection<int, ImportRow>  $rows
     */
    private function pairAssignments(Collection $rows): void
    {
        $assignments = $rows->filter(fn (ImportRow $row) => $row->action === ImportAction::Assigned && $row->status !== ImportRowStatus::Duplicate);

        foreach ($assignments as $assignment) {
            $stockAction = $assignment->value('option_type') === OptionType::Put->value ? ImportAction::Buy : ImportAction::Sell;
            $shares = Quantity::fromInteger((int) ltrim($assignment->value('quantity'), '-') * ((int) $assignment->value('multiplier') ?: 100));
            $assignedOn = CarbonImmutable::parse($assignment->value('date'));

            $stockRow = $rows->first(fn (ImportRow $row) => $row->action === $stockAction
                && $row->paired_row_id === null
                && in_array($row->status, [ImportRowStatus::Recognized, ImportRowStatus::PossibleDuplicate, ImportRowStatus::Ambiguous], true)
                && $row->value('symbol') === $assignment->value('symbol')
                && Quantity::fromDecimal(ltrim($row->value('quantity'), '-'))->equals($shares)
                && UnitPrice::fromDecimal($row->value('price'))->equals(UnitPrice::fromDecimal($assignment->value('strike')))
                && abs($assignedOn->diffInDays(CarbonImmutable::parse($row->value('date')))) <= 3);

            if ($stockRow !== null) {
                $stockRow->status = ImportRowStatus::Skipped;
                $stockRow->paired_row_id = $assignment->id;
                $stockRow->messages = ["Part of the assignment in row {$assignment->row_number}: the stock trade at the strike is recorded by the assignment, so this row is not imported separately."];
            }
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function contractQuery(Builder $query, ImportRow $row, string $tickerColumn): Builder
    {
        return $query
            ->where($tickerColumn, $row->value('symbol'))
            ->where('option_type', $row->value('option_type'))
            ->where('strike', UnitPrice::fromDecimal($row->value('strike'))->units)
            ->where('expires_on', $row->value('expiration'));
    }

    private function sameContract(ImportRow $a, ImportRow $b): bool
    {
        return $a->value('symbol') === $b->value('symbol')
            && $a->value('option_type') === $b->value('option_type')
            && $a->value('expiration') === $b->value('expiration')
            && UnitPrice::fromDecimal($a->value('strike'))->equals(UnitPrice::fromDecimal($b->value('strike')));
    }
}
