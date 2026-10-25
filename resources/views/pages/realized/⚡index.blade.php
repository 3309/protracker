<?php

use App\Livewire\ReportPage;
use App\Models\Dividend;
use App\Models\OptionEvent;
use App\Models\StockSaleAllocation;
use App\Support\CsvExport;
use App\Support\Money;
use App\Support\RealizedProfit;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Realized P/L')] class extends ReportPage
{
    #[Computed]
    public function realized(): RealizedProfit
    {
        return $this->reportingPeriod === null
            ? new RealizedProfit(new Collection, new Collection, new Collection)
            : RealizedProfit::for($this->reportingPeriod, $this->filteredAccountIds(), $this->tickerFilter());
    }

    /**
     * @return Collection<int, StockSaleAllocation>
     */
    #[Computed]
    public function stockPieces(): Collection
    {
        return $this->realized->stockPieces;
    }

    /**
     * @return Collection<int, OptionEvent>
     */
    #[Computed]
    public function optionCloses(): Collection
    {
        return $this->realized->optionCloses;
    }

    /**
     * @return Collection<int, Dividend>
     */
    #[Computed]
    public function dividends(): Collection
    {
        return $this->realized->dividends;
    }

    #[Computed]
    public function dividendTotal(): Money
    {
        return $this->realized->dividendTotal();
    }

    #[Computed]
    public function combinedNet(): Money
    {
        return $this->realized->combinedNet();
    }

    /**
     * @return array{gross: Money, fees: Money, net: Money}
     */
    #[Computed]
    public function stockTotals(): array
    {
        return $this->realized->stockTotals();
    }

    /**
     * @return array{gross: Money, fees: Money, net: Money}
     */
    #[Computed]
    public function optionTotals(): array
    {
        return $this->realized->optionTotals();
    }

    /**
     * One section of the report (stocks, options or dividends) as a CSV
     * file, with the section total as the last row.
     */
    public function export(string $section): StreamedResponse
    {
        return match ($section) {
            'stocks' => CsvExport::download('realized-stocks', [
                'sold_on', 'account', 'ticker', 'lot_bought_on', 'shares', 'proceeds', 'cost', 'gross_pl', 'fees', 'net_pl',
            ], [
                ...$this->stockPieces->map(fn (StockSaleAllocation $piece) => [
                    $piece->sold_on, $piece->account->displayName(), $piece->ticker, $piece->lot->acquired_on, $piece->shares,
                    $piece->proceeds, $piece->cost, $piece->grossProfit(), $piece->fees(), $piece->netProfit(),
                ])->all(),
                ['Total', null, null, null, null, null, null, $this->stockTotals['gross'], $this->stockTotals['fees'], $this->stockTotals['net']],
            ]),
            'options' => CsvExport::download('realized-options', [
                'closed_on', 'account', 'option', 'event', 'contracts', 'premium_kept', 'closing_cost', 'gross_pl', 'fees', 'net_pl',
            ], [
                ...$this->optionCloses->map(fn (OptionEvent $event) => [
                    $event->event_date, $event->account->displayName(), $event->position->description(), $event->event_type->label(), $event->contracts,
                    $event->allocated_premium, $event->closingCost(), $event->grossProfit(), $event->totalFees(), $event->netProfit(),
                ])->all(),
                ['Total', null, null, null, null, null, null, $this->optionTotals['gross'], $this->optionTotals['fees'], $this->optionTotals['net']],
            ]),
            'dividends' => CsvExport::download('realized-dividends', [
                'paid_on', 'account', 'ticker', 'ex_date', 'expected_amount', 'received_amount',
            ], [
                ...$this->dividends->map(fn (Dividend $dividend) => [
                    $dividend->pay_date, $dividend->account->displayName(), $dividend->ticker, $dividend->ex_date,
                    $dividend->expected_amount, $dividend->actual_amount,
                ])->all(),
                ['Total', null, null, null, null, $this->dividendTotal],
            ]),
            default => abort(404),
        };
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
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Dividends received</div>
                <div class="text-xl font-semibold tabular-nums">{{ $this->dividendTotal->format() }}</div>
                <div class="text-xs text-zinc-500">Confirmed amounts, by pay date</div>
            </div>
            <div class="card">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Combined net · {{ $this->reportingPeriod->label() }}</div>
                <div class="text-xl font-semibold tabular-nums">{{ $this->combinedNet->format() }}</div>
                <div class="text-xs text-zinc-500">Stocks + options + dividends</div>
            </div>
        </div>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-lg font-semibold">Stocks</h2>
                <button type="button" wire:click="export('stocks')" class="btn-secondary">Download CSV</button>
            </div>

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
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-lg font-semibold">Options</h2>
                <button type="button" wire:click="export('options')" class="btn-secondary">Download CSV</button>
            </div>

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

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-lg font-semibold">Dividends</h2>
                <button type="button" wire:click="export('dividends')" class="btn-secondary">Download CSV</button>
            </div>

            @if ($this->dividends->isEmpty())
                <div class="card">No confirmed dividends were paid in this period.</div>
            @else
                <div class="card overflow-x-auto p-0">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3 font-medium">Paid on</th>
                                <th class="px-4 py-3 font-medium">Account</th>
                                <th class="px-4 py-3 font-medium">Ticker</th>
                                <th class="px-4 py-3 font-medium">Ex-date</th>
                                <th class="px-4 py-3 text-right font-medium">Expected</th>
                                <th class="px-4 py-3 text-right font-medium">Received</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->dividends as $dividend)
                                <tr wire:key="realized-dividend-{{ $dividend->id }}">
                                    <td class="px-4 py-2">
                                        <a href="{{ route('dividends.edit', $dividend) }}" class="underline underline-offset-4" wire:navigate>{{ $dividend->pay_date->format('M j, Y') }}</a>
                                    </td>
                                    <td class="px-4 py-2">{{ $dividend->account->displayName() }}</td>
                                    <td class="px-4 py-2 font-medium">{{ $dividend->ticker }}</td>
                                    <td class="px-4 py-2">{{ $dividend->ex_date->format('M j, Y') }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $dividend->expected_amount?->format() }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $dividend->actual_amount?->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-zinc-200 font-medium dark:border-zinc-700">
                            <tr>
                                <td colspan="5" class="px-4 py-2">Dividend total</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $this->dividendTotal->format() }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
