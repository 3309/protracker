<?php

namespace App\Support;

use App\Actions\Cash\BuildCashLedger;
use App\Enums\OptionType;
use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Data for the dashboard charts. Nothing is calculated anew: the figures are
 * the existing realized profit, cash ledger and open position amounts,
 * grouped by month, broker or expiration date, so they add up to the same
 * totals as the reports.
 */
final class DashboardCharts
{
    /**
     * Realized P/L for each month of the period, up to the current month,
     * with the running total since the start of the period. Months without
     * activity are included with zero.
     *
     * @return list<array{month: CarbonImmutable, stocks: Money, options: Money, dividends: Money, total: Money, cumulative: Money}>
     */
    public static function realizedByMonth(RealizedProfit $realized, ReportingPeriod $period, CarbonImmutable $today): array
    {
        $months = [];

        foreach (self::months($period, $today) as $month) {
            $months[$month->format('Y-m')] = ['month' => $month, 'stocks' => Money::zero(), 'options' => Money::zero(), 'dividends' => Money::zero()];
        }

        foreach ($realized->stockPieces as $piece) {
            self::addTo($months, $piece->sold_on, 'stocks', $piece->netProfit());
        }

        foreach ($realized->optionCloses as $event) {
            self::addTo($months, $event->event_date, 'options', $event->netProfit());
        }

        foreach ($realized->dividends as $dividend) {
            self::addTo($months, $dividend->pay_date, 'dividends', $dividend->actual_amount ?? Money::zero());
        }

        $cumulative = Money::zero();
        $rows = [];

        foreach ($months as $row) {
            $total = $row['stocks']->plus($row['options'])->plus($row['dividends']);
            $cumulative = $cumulative->plus($total);
            $rows[] = [...$row, 'total' => $total, 'cumulative' => $cumulative];
        }

        return $rows;
    }

    /**
     * Total cash per broker (and all brokers together) at the end of the
     * first day of the period and at the end of each month; the current month
     * ends today.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array{dates: list<CarbonImmutable>, brokers: array<string, array{label: string, values: list<Money>}>, total: list<Money>}
     */
    public static function cashByMonth(Collection $accounts, ReportingPeriod $period, CarbonImmutable $today): array
    {
        $dates = [];

        foreach (self::months($period, $today) as $month) {
            $dates[] = $month->endOfMonth()->startOfDay()->min($period->end)->min($today);
        }

        if ($dates === []) {
            return ['dates' => [], 'brokers' => [], 'total' => []];
        }

        if (! $dates[0]->isSameDay($period->start)) {
            array_unshift($dates, $period->start);
        }

        $brokers = [];
        $total = array_fill(0, count($dates), Money::zero());

        foreach ($accounts->sortBy(fn (Account $account) => $account->broker->label()) as $account) {
            $ledger = app(BuildCashLedger::class)->handle($account->id);
            $key = $account->broker->value;
            $brokers[$key] ??= ['label' => $account->broker->label(), 'values' => array_fill(0, count($dates), Money::zero())];

            foreach ($dates as $index => $date) {
                $cash = $ledger->totalOn($date);
                $brokers[$key]['values'][$index] = $brokers[$key]['values'][$index]->plus($cash);
                $total[$index] = $total[$index]->plus($cash);
            }
        }

        return ['dates' => $dates, 'brokers' => $brokers, 'total' => array_values($total)];
    }

    /**
     * Contracts still open for each expiration date, soonest first: sold
     * puts, sold calls and bought options. Partly closed positions count only
     * their open contracts.
     *
     * @return list<array{date: CarbonImmutable, daysToExpiry: int, shortPuts: int, shortCalls: int, long: int}>
     */
    public static function openContractsByExpiration(OpenPositionsReport $open): array
    {
        $dates = [];

        foreach ($open->options as $option) {
            $position = $option['position'];
            $key = $position->expires_on->format('Y-m-d');
            $dates[$key] ??= ['date' => CarbonImmutable::parse($key), 'daysToExpiry' => $option['daysToExpiry'], 'shortPuts' => 0, 'shortCalls' => 0, 'long' => 0];

            $series = match (true) {
                $position->isLong() => 'long',
                $position->option_type === OptionType::Put => 'shortPuts',
                default => 'shortCalls',
            };

            $dates[$key][$series] += $option['openContracts'];
        }

        ksort($dates);

        return array_values($dates);
    }

    /**
     * The first day of each month from the start of the period to the
     * earlier of its end and today.
     *
     * @return list<CarbonImmutable>
     */
    private static function months(ReportingPeriod $period, CarbonImmutable $today): array
    {
        $last = $period->end->min($today)->startOfMonth();
        $months = [];

        for ($month = $period->start->startOfMonth(); $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
            $months[] = $month;
        }

        return $months;
    }

    /**
     * @param  array<string, array{month: CarbonImmutable, stocks: Money, options: Money, dividends: Money}>  $months
     * @param  'stocks'|'options'|'dividends'  $series
     */
    private static function addTo(array &$months, CarbonImmutable $date, string $series, Money $amount): void
    {
        $key = $date->format('Y-m');

        if (isset($months[$key])) {
            $months[$key][$series] = $months[$key][$series]->plus($amount);
        }
    }
}
