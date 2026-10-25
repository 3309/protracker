<?php

namespace App\Actions\Wheel;

use App\Enums\OptionType;
use App\Enums\WheelCycleKind;
use App\Models\Dividend;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Models\StockLot;
use App\Models\StockSaleAllocation;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\WheelCycle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Finds wheel cycles per account and ticker.
 *
 * A cycle starts on a day a put is sold while the account holds no shares
 * and no open options on the ticker, or on a day a call is sold on shares
 * already held while no options are open. It ends at the end of the first
 * day the account again holds no shares and no open options on the ticker.
 * Everything on that ticker in that account between those dates belongs to
 * the cycle: option results by event date, stock results by sale date and
 * confirmed dividends by ex-date. So each record counts in one cycle only.
 *
 * Capital at risk is the largest amount tied up on any day of the cycle:
 * strike × multiplier × open put contracts, plus the cost of shares held.
 */
class BuildWheelCycles
{
    /**
     * @param  list<int>|null  $accountIds  null for all accounts
     * @return BaseCollection<int, WheelCycle>
     */
    public function handle(?array $accountIds, ?string $ticker): BaseCollection
    {
        $positions = OptionPosition::query()
            ->notVoided()
            ->with(['events' => fn ($events) => $events->whereNull('voided_at')])
            ->when($accountIds !== null, fn ($query) => $query->whereIn('account_id', $accountIds))
            ->when($ticker !== null, fn ($query) => $query->where('underlying', $ticker))
            ->get();

        return $positions
            ->groupBy(fn (OptionPosition $position) => $position->account_id.'|'.$position->underlying)
            ->toBase()
            ->flatMap(fn (Collection $group) => $this->cyclesFor($group))
            ->sortBy(fn (WheelCycle $cycle) => $cycle->start->format('Y-m-d').'|'.$cycle->ticker)
            ->values();
    }

    /**
     * @param  Collection<int, OptionPosition>  $positions  one account and ticker
     * @return list<WheelCycle>
     */
    private function cyclesFor(Collection $positions): array
    {
        $first = $positions->firstOrFail();
        $accountId = $first->account_id;
        $ticker = $first->underlying;

        $lots = StockLot::query()->where('account_id', $accountId)->where('ticker', $ticker)->notVoided()->get();
        $sales = StockSaleAllocation::query()->where('account_id', $accountId)->where('ticker', $ticker)->get();
        $dividends = Dividend::query()->where('account_id', $accountId)->where('ticker', $ticker)->notVoided()->whereNotNull('actual_amount')->get();
        $events = $positions->flatMap(fn (OptionPosition $position) => $position->events->each(fn (OptionEvent $event) => $event->setRelation('position', $position)));

        $dates = collect()
            ->merge($lots->map(fn (StockLot $lot) => $lot->acquired_on->format('Y-m-d')))
            ->merge($sales->map(fn (StockSaleAllocation $sale) => $sale->sold_on->format('Y-m-d')))
            ->merge($positions->map(fn (OptionPosition $position) => $position->opened_on->format('Y-m-d')))
            ->merge($events->map(fn (OptionEvent $event) => $event->event_date->format('Y-m-d')))
            ->unique()
            ->sort()
            ->values();

        $cycles = [];
        $active = null;
        $previous = ['shares' => 0, 'open' => 0, 'shareCost' => Money::zero()];

        foreach ($dates as $date) {
            $openedToday = $positions->filter(fn (OptionPosition $position) => $position->opened_on->format('Y-m-d') === $date);

            if ($active === null && $previous['open'] === 0) {
                $soldPut = $openedToday->contains(fn (OptionPosition $position) => $position->option_type === OptionType::Put);
                $soldCall = $openedToday->contains(fn (OptionPosition $position) => $position->option_type === OptionType::Call);

                if ($previous['shares'] === 0 && $soldPut) {
                    $active = ['kind' => WheelCycleKind::Put, 'start' => $date, 'capital' => Money::zero()];
                } elseif ($previous['shares'] > 0 && $soldCall) {
                    $active = ['kind' => WheelCycleKind::CoveredCall, 'start' => $date, 'capital' => $previous['shareCost']];
                }
            }

            $state = $this->stateAtEndOf($date, $lots, $sales, $positions, $events);

            if ($active !== null) {
                $tiedUp = $state['putObligation']->plus($state['shareCost']);

                if ($tiedUp->cents > $active['capital']->cents) {
                    $active['capital'] = $tiedUp;
                }

                if ($state['shares'] === 0 && $state['open'] === 0) {
                    $cycles[] = $this->cycle($accountId, $ticker, $active, $date, $positions, $events, $sales, $dividends);
                    $active = null;
                }
            }

            $previous = $state;
        }

        if ($active !== null) {
            $cycles[] = $this->cycle($accountId, $ticker, $active, null, $positions, $events, $sales, $dividends);
        }

        return $cycles;
    }

    /**
     * Shares held, open contracts, open put obligation and cost of shares
     * held at the end of a date.
     *
     * @param  Collection<int, StockLot>  $lots
     * @param  Collection<int, StockSaleAllocation>  $sales
     * @param  Collection<int, OptionPosition>  $positions
     * @param  BaseCollection<int, OptionEvent>  $events
     * @return array{shares: int, open: int, shareCost: Money, putObligation: Money}
     */
    private function stateAtEndOf(string $date, Collection $lots, Collection $sales, Collection $positions, BaseCollection $events): array
    {
        $salesSoFar = $sales->filter(fn (StockSaleAllocation $sale) => $sale->sold_on->format('Y-m-d') <= $date);
        $shares = 0;
        $shareCost = Money::zero();

        foreach ($lots as $lot) {
            if ($lot->acquired_on->format('Y-m-d') > $date) {
                continue;
            }

            $soldUnits = (int) $salesSoFar->where('stock_lot_id', $lot->id)->sum(fn (StockSaleAllocation $sale) => $sale->shares->units);
            $shares += $lot->shares->units - $soldUnits;
            $shareCost = $shareCost->plus($lot->costBasis()->minus($lot->cost_per_share->totalFor(Quantity::fromUnits($soldUnits))));
        }

        $open = 0;
        $putObligation = Money::zero();

        foreach ($positions as $position) {
            if ($position->opened_on->format('Y-m-d') > $date) {
                continue;
            }

            $closed = (int) $events
                ->filter(fn (OptionEvent $event) => $event->option_position_id === $position->id && $event->event_date->format('Y-m-d') <= $date)
                ->sum(fn (OptionEvent $event) => $event->contracts);
            $openContracts = $position->contracts - $closed;
            $open += $openContracts;

            if ($position->option_type === OptionType::Put) {
                $putObligation = $putObligation->plus($position->strike->totalFor(Quantity::fromInteger($openContracts * $position->multiplier)));
            }
        }

        return ['shares' => $shares, 'open' => $open, 'shareCost' => $shareCost, 'putObligation' => $putObligation];
    }

    /**
     * @param  array{kind: WheelCycleKind, start: string, capital: Money}  $active
     * @param  Collection<int, OptionPosition>  $positions
     * @param  BaseCollection<int, OptionEvent>  $events
     * @param  Collection<int, StockSaleAllocation>  $sales
     * @param  Collection<int, Dividend>  $dividends
     */
    private function cycle(int $accountId, string $ticker, array $active, ?string $end, Collection $positions, BaseCollection $events, Collection $sales, Collection $dividends): WheelCycle
    {
        $inCycle = fn (string $date): bool => $date >= $active['start'] && ($end === null || $date <= $end);

        return new WheelCycle(
            accountId: $accountId,
            ticker: $ticker,
            kind: $active['kind'],
            start: CarbonImmutable::parse($active['start']),
            end: $end !== null ? CarbonImmutable::parse($end) : null,
            optionProfit: $events
                ->filter(fn (OptionEvent $event) => $inCycle($event->event_date->format('Y-m-d')))
                ->reduce(fn (Money $total, OptionEvent $event) => $total->plus($event->netProfit()), Money::zero()),
            stockProfit: $sales
                ->filter(fn (StockSaleAllocation $sale) => $inCycle($sale->sold_on->format('Y-m-d')))
                ->reduce(fn (Money $total, StockSaleAllocation $sale) => $total->plus($sale->netProfit()), Money::zero()),
            dividends: $dividends
                ->filter(fn (Dividend $dividend) => $inCycle($dividend->ex_date->format('Y-m-d')))
                ->reduce(fn (Money $total, Dividend $dividend) => $total->plus($dividend->actual_amount ?? Money::zero()), Money::zero()),
            capitalAtRisk: $active['capital'],
            optionLegs: $positions->filter(fn (OptionPosition $position) => $inCycle($position->opened_on->format('Y-m-d')))->count(),
        );
    }
}
