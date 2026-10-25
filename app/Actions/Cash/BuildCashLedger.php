<?php

namespace App\Actions\Cash;

use App\Enums\CashCategory;
use App\Enums\OptionEventType;
use App\Enums\PositionOrigin;
use App\Enums\TradeSide;
use App\Models\AccountOpening;
use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Models\StockTrade;
use App\Support\CashLedger;
use App\Support\CashLedgerEntry;
use App\Support\MarketCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds the cash ledger from the records themselves, so it can never drift
 * out of step with them and every line links to its source.
 *
 * Each source contributes once:
 * - opening cash is the starting balance, never a deposit or income;
 * - stock buys and sales (including those caused by an assignment) at
 *   shares × price, with their fee;
 * - option premium of sell-to-open trades, with the opening fee (premium of
 *   opening-balance options arrived before the reporting start and is
 *   already inside the opening cash), and the premium paid for options
 *   bought to open, with the opening fee;
 * - option buy-backs, including the buy-back of a roll, and sales to close
 *   of bought options, with their fee (expiry and assignment move no option
 *   cash);
 * - confirmed dividends, on the pay date;
 * - standalone deposits, withdrawals, interest and other items.
 *
 * Voided records are left out.
 */
class BuildCashLedger
{
    /**
     * @param  int|list<int>|null  $accountId  one account, several, or null for all accounts
     */
    public function handle(int|array|null $accountId = null): CashLedger
    {
        $accountIds = is_int($accountId) ? [$accountId] : $accountId;

        $entries = [
            ...$this->openingEntries($accountIds)->all(),
            ...$this->stockTradeEntries($accountIds)->all(),
            ...$this->optionPremiumEntries($accountIds)->all(),
            ...$this->optionBuyBackEntries($accountIds)->all(),
            ...$this->dividendEntries($accountIds)->all(),
            ...$this->cashMovementEntries($accountIds)->all(),
        ];

        usort($entries, fn (CashLedgerEntry $a, CashLedgerEntry $b): int => $this->sortKey($a) <=> $this->sortKey($b));

        return new CashLedger(collect($entries));
    }

    /**
     * Date order, the opening balance first within its date, then by source.
     *
     * @return array{string, bool, string}
     */
    private function sortKey(CashLedgerEntry $entry): array
    {
        return [$entry->date->format('Y-m-d'), $entry->category !== CashCategory::OpeningBalance, $entry->sourceKey];
    }

    /**
     * Opening cash. When the unsettled part is known, it is assumed to settle
     * on the first trading day on or after the reporting start date.
     *
     * @param  list<int>|null  $accountIds
     * @return Collection<int, CashLedgerEntry>
     */
    private function openingEntries(?array $accountIds): Collection
    {
        return $this->forAccounts(AccountOpening::query()->whereNotNull('cash_balance'), $accountIds)->get()->toBase()
            ->flatMap(function (AccountOpening $opening): array {
                $cash = $opening->cash_balance ?? Money::zero();
                $unsettled = $opening->unsettled_cash ?? Money::zero();
                $url = route('opening-balances.show', $opening->account_id);
                $entries = [];

                $entries[] = new CashLedgerEntry(
                    $opening->account_id,
                    CashCategory::OpeningBalance,
                    $opening->as_of_date,
                    $opening->as_of_date,
                    $unsettled->isZero() ? 'Opening cash' : 'Opening cash (settled part)',
                    $cash->minus($unsettled),
                    Money::zero(),
                    $url,
                    sprintf('0-%010d-a', $opening->id),
                );

                if (! $unsettled->isZero()) {
                    $entries[] = new CashLedgerEntry(
                        $opening->account_id,
                        CashCategory::OpeningBalance,
                        $opening->as_of_date,
                        MarketCalendar::addTradingDays($opening->as_of_date->subDay(), 1),
                        'Opening cash (unsettled part, assumed to settle on the first trading day)',
                        $unsettled,
                        Money::zero(),
                        $url,
                        sprintf('0-%010d-b', $opening->id),
                    );
                }

                return $entries;
            });
    }

    /**
     * @param  list<int>|null  $accountIds
     * @return Collection<int, CashLedgerEntry>
     */
    private function stockTradeEntries(?array $accountIds): Collection
    {
        return $this->forAccounts(StockTrade::query()->notVoided(), $accountIds)->get()
            ->map(function (StockTrade $trade): CashLedgerEntry {
                $isBuy = $trade->side === TradeSide::Buy;
                $gross = $trade->grossAmount();

                return new CashLedgerEntry(
                    $trade->account_id,
                    $isBuy ? CashCategory::StockBuy : CashCategory::StockSale,
                    $trade->trade_date,
                    $trade->settlement_date,
                    sprintf(
                        '%s %s %s at %s%s',
                        $isBuy ? 'Bought' : 'Sold',
                        $trade->shares->format(),
                        $trade->ticker,
                        $trade->price_per_share->format(),
                        $trade->isFromAssignment() ? ' (assignment)' : '',
                    ),
                    $isBuy ? Money::zero()->minus($gross) : $gross,
                    $trade->fees,
                    route('stock-trades.edit', $trade),
                    sprintf('1-%010d', $trade->id),
                );
            });
    }

    /**
     * @param  list<int>|null  $accountIds
     * @return Collection<int, CashLedgerEntry>
     */
    private function optionPremiumEntries(?array $accountIds): Collection
    {
        return $this->forAccounts(OptionPosition::query()->notVoided()->where('origin', '!=', PositionOrigin::OpeningBalance), $accountIds)->get()
            ->map(fn (OptionPosition $position): CashLedgerEntry => new CashLedgerEntry(
                $position->account_id,
                $position->isLong() ? CashCategory::OptionPurchase : CashCategory::OptionPremium,
                $position->opened_on,
                $position->opening_settlement_date ?? $position->opened_on,
                ($position->isLong() ? 'Bought' : 'Sold')." {$position->contracts} × {$position->description()}",
                $position->isLong() ? Money::zero()->minus($position->premiumReceived()) : $position->premiumReceived(),
                $position->opening_fees,
                route('options.show', $position),
                sprintf('2-%010d', $position->id),
            ));
    }

    /**
     * @param  list<int>|null  $accountIds
     * @return Collection<int, CashLedgerEntry>
     */
    private function optionBuyBackEntries(?array $accountIds): Collection
    {
        $query = OptionEvent::query()
            ->with('position')
            ->notVoided()
            ->whereIn('event_type', [OptionEventType::BuyToClose, OptionEventType::Rolled, OptionEventType::SellToClose]);

        return $this->forAccounts($query, $accountIds)->get()
            ->map(fn (OptionEvent $event): CashLedgerEntry => $event->event_type === OptionEventType::SellToClose ? new CashLedgerEntry(
                $event->account_id,
                CashCategory::OptionSale,
                $event->event_date,
                $event->settlement_date ?? $event->event_date,
                "Sold to close {$event->contracts} × {$event->position->description()}",
                $event->closingCost(),
                $event->fees,
                route('options.show', $event->option_position_id),
                sprintf('3-%010d', $event->id),
            ) : new CashLedgerEntry(
                $event->account_id,
                CashCategory::OptionBuyBack,
                $event->event_date,
                $event->settlement_date ?? $event->event_date,
                "Bought back {$event->contracts} × {$event->position->description()}".($event->event_type === OptionEventType::Rolled ? ' (roll)' : ''),
                Money::zero()->minus($event->closingCost()),
                $event->fees,
                route('options.show', $event->option_position_id),
                sprintf('3-%010d', $event->id),
            ));
    }

    /**
     * @param  list<int>|null  $accountIds
     * @return Collection<int, CashLedgerEntry>
     */
    private function dividendEntries(?array $accountIds): Collection
    {
        return $this->forAccounts(Dividend::query()->notVoided()->whereNotNull('actual_amount'), $accountIds)->get()
            ->map(fn (Dividend $dividend): CashLedgerEntry => new CashLedgerEntry(
                $dividend->account_id,
                CashCategory::Dividend,
                $dividend->pay_date,
                $dividend->pay_date,
                "{$dividend->ticker} dividend (ex-date {$dividend->ex_date->format('M j, Y')})",
                $dividend->actual_amount ?? Money::zero(),
                Money::zero(),
                route('dividends.edit', $dividend),
                sprintf('4-%010d', $dividend->id),
            ));
    }

    /**
     * @param  list<int>|null  $accountIds
     * @return Collection<int, CashLedgerEntry>
     */
    private function cashMovementEntries(?array $accountIds): Collection
    {
        return $this->forAccounts(CashMovement::query()->notVoided(), $accountIds)->get()
            ->map(fn (CashMovement $movement): CashLedgerEntry => new CashLedgerEntry(
                $movement->account_id,
                CashCategory::fromMovementType($movement->movement_type),
                $movement->movement_date,
                $movement->settlement_date,
                $movement->movement_type->label().($movement->reference_note ? ": {$movement->reference_note}" : ''),
                $movement->signedAmount(),
                Money::zero(),
                route('cash.movements.edit', $movement),
                sprintf('5-%010d', $movement->id),
            ));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>|null  $accountIds
     * @return Builder<TModel>
     */
    private function forAccounts(Builder $query, ?array $accountIds): Builder
    {
        return $accountIds === null ? $query : $query->whereIn('account_id', $accountIds);
    }
}
