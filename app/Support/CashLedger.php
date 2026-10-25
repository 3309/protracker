<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * The cash ledger of one or more accounts, in date order.
 *
 * Total cash counts every entry by its trade or event date. Settled cash
 * counts only entries whose settlement date has been reached. The
 * difference between them is unsettled cash.
 */
final readonly class CashLedger
{
    /**
     * @param  Collection<int, CashLedgerEntry>  $entries  in date order
     */
    public function __construct(public Collection $entries) {}

    /**
     * Total cash at the end of a date.
     */
    public function totalOn(DateTimeInterface $date): Money
    {
        return $this->sum($this->entries->filter(fn (CashLedgerEntry $entry) => $entry->isOnOrBefore($date)));
    }

    /**
     * Settled cash at the end of a date.
     */
    public function settledOn(DateTimeInterface $date): Money
    {
        return $this->sum($this->entries->filter(fn (CashLedgerEntry $entry) => $entry->isSettledBy($date)));
    }

    /**
     * Total cash before a period starts, carried into it.
     */
    public function totalBefore(ReportingPeriod $period): Money
    {
        return $this->totalOn($period->start->subDay());
    }

    /**
     * The entries in a period, each with the running total cash after it.
     *
     * @return Collection<int, array{entry: CashLedgerEntry, balance: Money}>
     */
    public function entriesWithBalances(ReportingPeriod $period): Collection
    {
        $balance = $this->totalBefore($period);
        $rows = collect();

        foreach ($this->entries as $entry) {
            if (! $period->contains($entry->date)) {
                continue;
            }

            $balance = $balance->plus($entry->amount());
            $rows->push(['entry' => $entry, 'balance' => $balance]);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, CashLedgerEntry>  $entries
     */
    private function sum(Collection $entries): Money
    {
        return $entries->reduce(fn (Money $total, CashLedgerEntry $entry) => $total->plus($entry->amount()), Money::zero());
    }
}
