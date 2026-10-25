<?php

use App\Livewire\ReportPage;
use App\Models\StockSaleAllocation;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Realized stock P/L')] class extends ReportPage
{
    /**
     * Sale pieces sold in the selected period. Voided sales have no pieces.
     *
     * @return Collection<int, StockSaleAllocation>
     */
    #[Computed]
    public function pieces(): Collection
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
     * @return array{proceeds: Money, cost: Money, gross: Money, fees: Money, net: Money}
     */
    #[Computed]
    public function totals(): array
    {
        $sum = fn (callable $amount): Money => $this->pieces->reduce(fn (Money $total, StockSaleAllocation $piece) => $total->plus($amount($piece)), Money::zero());

        return [
            'proceeds' => $sum(fn (StockSaleAllocation $piece) => $piece->proceeds),
            'cost' => $sum(fn (StockSaleAllocation $piece) => $piece->cost),
            'gross' => $sum(fn (StockSaleAllocation $piece) => $piece->grossProfit()),
            'fees' => $sum(fn (StockSaleAllocation $piece) => $piece->fees()),
            'net' => $sum(fn (StockSaleAllocation $piece) => $piece->netProfit()),
        ];
    }
};
?>

<div class="flex flex-col gap-6">
    <div>
        <h1 class="text-2xl font-semibold">Realized stock P/L</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Profit and loss on shares sold in the period, by sale date. Each sale is split into the lots it used, oldest first.
            Cost is the actual purchase price (Method B). This is a tracking view, not a tax report.
        </p>
    </div>

    @include('partials.report-filters')

    @if ($this->reportingPeriod !== null)
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="card">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Gross P/L</div>
                <div class="text-xl font-semibold tabular-nums">{{ $this->totals['gross']->format() }}</div>
            </div>
            <div class="card">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Fees</div>
                <div class="text-xl font-semibold tabular-nums">{{ $this->totals['fees']->format() }}</div>
            </div>
            <div class="card">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Net P/L · {{ $this->reportingPeriod->label() }}</div>
                <div class="text-xl font-semibold tabular-nums">{{ $this->totals['net']->format() }}</div>
            </div>
        </div>

        @if ($this->pieces->isEmpty())
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
                        @foreach ($this->pieces as $piece)
                            <tr wire:key="realized-{{ $piece->id }}">
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
                            <td colspan="5" class="px-4 py-2">Total</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $this->totals['proceeds']->format() }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $this->totals['cost']->format() }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $this->totals['gross']->format() }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $this->totals['fees']->format() }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $this->totals['net']->format() }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    @endif
</div>
