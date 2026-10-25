<?php

use App\Livewire\ReportPage;
use App\Models\OptionEvent;
use App\Models\StockSaleAllocation;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Realized P/L')] class extends ReportPage
{
    /**
     * Stock sale pieces sold in the selected period. Voided sales have no pieces.
     *
     * @return Collection<int, StockSaleAllocation>
     */
    #[Computed]
    public function stockPieces(): Collection
    {
        if ($this->reportingPeriod === null) {
            return new Collection;
        }

        $query = StockSaleAllocation::query()->with(['account', 'lot', 'sale']);

        $this->applyAccountFilter($query);
        $this->reportingPeriod->applyTo($query, 'sold_on');

        return $query->orderBy('sold_on')->orderBy('stock_trade_id')->orderBy('id')->get();
    }

    /**
     * Option closes in the selected period, by close date. Voided closes are left out.
     *
     * @return Collection<int, OptionEvent>
     */
    #[Computed]
    public function optionCloses(): Collection
    {
        if ($this->reportingPeriod === null) {
            return new Collection;
        }

        $query = OptionEvent::query()->with(['account', 'position'])->notVoided();

        $this->applyAccountFilter($query);
        $this->reportingPeriod->applyTo($query, 'event_date');

        return $query->orderBy('event_date')->orderBy('id')->get();
    }

    /**
     * @return array{gross: Money, fees: Money, net: Money}
     */
    #[Computed]
    public function stockTotals(): array
    {
        return [
            'gross' => $this->sum($this->stockPieces, fn (StockSaleAllocation $piece) => $piece->grossProfit()),
            'fees' => $this->sum($this->stockPieces, fn (StockSaleAllocation $piece) => $piece->fees()),
            'net' => $this->sum($this->stockPieces, fn (StockSaleAllocation $piece) => $piece->netProfit()),
        ];
    }

    /**
     * @return array{gross: Money, fees: Money, net: Money}
     */
    #[Computed]
    public function optionTotals(): array
    {
        return [
            'gross' => $this->sum($this->optionCloses, fn (OptionEvent $event) => $event->grossProfit()),
            'fees' => $this->sum($this->optionCloses, fn (OptionEvent $event) => $event->totalFees()),
            'net' => $this->sum($this->optionCloses, fn (OptionEvent $event) => $event->netProfit()),
        ];
    }

    /**
     * @template TItem
     *
     * @param  Collection<int, TItem>  $items
     * @param  callable(TItem): Money  $amount
     */
    private function sum(Collection $items, callable $amount): Money
    {
        return $items->reduce(fn (Money $total, mixed $item) => $total->plus($amount($item)), Money::zero());
    }
};
?>

<div class="flex flex-col gap-6">
    <div>
        <h1 class="text-2xl font-semibold">Realized P/L</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Profit and loss on stock sold and options closed in the period. Stock cost is the actual purchase price (Method B);
            option premium is counted in option P/L when contracts close, never in stock cost. This is a tracking view, not a tax report.
        </p>
    </div>

    @include('partials.report-filters')

    @if ($this->reportingPeriod !== null)
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="card">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Stocks · net</div>
                <div class="text-xl font-semibold tabular-nums">{{ $this->stockTotals['net']->format() }}</div>
                <div class="text-xs text-zinc-500 tabular-nums">Gross {{ $this->stockTotals['gross']->format() }} · fees {{ $this->stockTotals['fees']->format() }}</div>
            </div>
            <div class="card">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Options · net</div>
                <div class="text-xl font-semibold tabular-nums">{{ $this->optionTotals['net']->format() }}</div>
                <div class="text-xs text-zinc-500 tabular-nums">Gross {{ $this->optionTotals['gross']->format() }} · fees {{ $this->optionTotals['fees']->format() }}</div>
            </div>
            <div class="card">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Combined net · {{ $this->reportingPeriod->label() }}</div>
                <div class="text-xl font-semibold tabular-nums">{{ $this->stockTotals['net']->plus($this->optionTotals['net'])->format() }}</div>
                <div class="text-xs text-zinc-500">Dividends are added in a later phase.</div>
            </div>
        </div>

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">Stocks</h2>

            @if ($this->stockPieces->isEmpty())
                <div class="card">No shares were sold in this period.</div>
            @else
                <div class="card overflow-x-auto p-0">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3 font-medium">Sold on</th>
                                <th class="px-4 py-3 font-medium">Account</th>
                                <th class="px-4 py-3 font-medium">Ticker</th>
                                <th class="px-4 py-3 font-medium">Lot bought on</th>
                                <th class="px-4 py-3 text-right font-medium">Shares</th>
                                <th class="px-4 py-3 text-right font-medium">Proceeds</th>
                                <th class="px-4 py-3 text-right font-medium">Cost</th>
                                <th class="px-4 py-3 text-right font-medium">Gross P/L</th>
                                <th class="px-4 py-3 text-right font-medium">Fees</th>
                                <th class="px-4 py-3 text-right font-medium">Net P/L</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->stockPieces as $piece)
                                <tr wire:key="realized-stock-{{ $piece->id }}">
                                    <td class="px-4 py-2">
                                        <a href="{{ route('stock-trades.edit', $piece->stock_trade_id) }}" class="underline underline-offset-4" wire:navigate>{{ $piece->sold_on->format('M j, Y') }}</a>
                                    </td>
                                    <td class="px-4 py-2">{{ $piece->account->displayName() }}</td>
                                    <td class="px-4 py-2 font-medium">{{ $piece->ticker }}</td>
                                    <td class="px-4 py-2">{{ $piece->lot->acquired_on->format('M j, Y') }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $piece->shares->format() }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $piece->proceeds->format() }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $piece->cost->format() }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $piece->grossProfit()->format() }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $piece->fees()->format() }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $piece->netProfit()->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-zinc-200 font-medium dark:border-zinc-700">
                            <tr>
                                <td colspan="7" class="px-4 py-2">Stock total</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $this->stockTotals['gross']->format() }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $this->stockTotals['fees']->format() }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $this->stockTotals['net']->format() }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">Options</h2>

            @if ($this->optionCloses->isEmpty())
                <div class="card">No option contracts were closed in this period.</div>
            @else
                <div class="card overflow-x-auto p-0">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3 font-medium">Closed on</th>
                                <th class="px-4 py-3 font-medium">Account</th>
                                <th class="px-4 py-3 font-medium">Option</th>
                                <th class="px-4 py-3 font-medium">Event</th>
                                <th class="px-4 py-3 text-right font-medium">Contracts</th>
                                <th class="px-4 py-3 text-right font-medium">Premium kept</th>
                                <th class="px-4 py-3 text-right font-medium">Closing cost</th>
                                <th class="px-4 py-3 text-right font-medium">Gross P/L</th>
                                <th class="px-4 py-3 text-right font-medium">Fees</th>
                                <th class="px-4 py-3 text-right font-medium">Net P/L</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->optionCloses as $event)
                                <tr wire:key="realized-option-{{ $event->id }}">
                                    <td class="px-4 py-2">
                                        <a href="{{ route('options.show', $event->option_position_id) }}" class="underline underline-offset-4" wire:navigate>{{ $event->event_date->format('M j, Y') }}</a>
                                    </td>
                                    <td class="px-4 py-2">{{ $event->account->displayName() }}</td>
                                    <td class="px-4 py-2 font-medium">{{ $event->position->description() }}</td>
                                    <td class="px-4 py-2">{{ $event->event_type->label() }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $event->contracts }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $event->allocated_premium->format() }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $event->closingCost()->format() }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $event->grossProfit()->format() }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $event->totalFees()->format() }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $event->netProfit()->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-zinc-200 font-medium dark:border-zinc-700">
                            <tr>
                                <td colspan="7" class="px-4 py-2">Option total</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $this->optionTotals['gross']->format() }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $this->optionTotals['fees']->format() }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $this->optionTotals['net']->format() }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
