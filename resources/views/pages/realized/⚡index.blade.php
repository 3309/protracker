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
                'closed_on', 'account', 'option', 'event', 'contracts', 'opening_premium', 'closing_amount', 'gross_pl', 'fees', 'net_pl',
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
        <h1 class="page-title">Realized P/L</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Profit and loss on stock sold and options closed in the period. Stock cost is the actual purchase price (Method B);
            option premium is counted in option P/L when contracts close, never in stock cost. This is a tracking view, not a tax report.
        </p>
    </div>

    @include('partials.report-filters')

    @if ($this->reportingPeriod !== null)
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Stocks · net" icon="portfolio" :hint="'Gross '.$this->stockTotals['gross']->format().' · fees '.$this->stockTotals['fees']->format()">
                <x-money :amount="$this->stockTotals['net']" />
            </x-stat-card>
            <x-stat-card label="Options · net" icon="options" :hint="'Gross '.$this->optionTotals['gross']->format().' · fees '.$this->optionTotals['fees']->format()">
                <x-money :amount="$this->optionTotals['net']" />
            </x-stat-card>
            <x-stat-card label="Dividends received" icon="cash" hint="Confirmed amounts, by pay date">
                <x-money :amount="$this->dividendTotal" />
            </x-stat-card>
            <x-stat-card :label="'Combined net · '.$this->reportingPeriod->label()" icon="report" hint="Stocks + options + dividends">
                <x-money :amount="$this->combinedNet" />
            </x-stat-card>
        </div>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between gap-4">
                <h2 class="section-title">Stocks</h2>
                <button type="button" wire:click="export('stocks')" class="btn-secondary"><x-icon name="download" class="size-4" />Download CSV</button>
            </div>

            @if ($this->stockPieces->isEmpty())
                <x-empty-state>No shares were sold in this period.</x-empty-state>
            @else
                <div class="card overflow-x-auto p-0">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Sold on</th>
                                <th>Account</th>
                                <th>Ticker</th>
                                <th>Lot bought on</th>
                                <th class="text-right">Shares</th>
                                <th class="text-right">Proceeds</th>
                                <th class="text-right">Cost</th>
                                <th class="text-right">Gross P/L</th>
                                <th class="text-right">Fees</th>
                                <th class="text-right">Net P/L</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->stockPieces as $piece)
                                <tr wire:key="realized-stock-{{ $piece->id }}">
                                    <td>
                                        <a href="{{ route('stock-trades.edit', $piece->stock_trade_id) }}" class="link" wire:navigate>{{ $piece->sold_on->format('M j, Y') }}</a>
                                    </td>
                                    <td>{{ $piece->account->displayName() }}</td>
                                    <td class="font-medium">{{ $piece->ticker }}</td>
                                    <td>{{ $piece->lot->acquired_on->format('M j, Y') }}</td>
                                    <td class="num">{{ $piece->shares->format() }}</td>
                                    <td class="num">{{ $piece->proceeds->format() }}</td>
                                    <td class="num">{{ $piece->cost->format() }}</td>
                                    <td class="num"><x-money :amount="$piece->grossProfit()" /></td>
                                    <td class="num">{{ $piece->fees()->format() }}</td>
                                    <td class="num"><x-money :amount="$piece->netProfit()" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="7">Stock total</td>
                                <td class="num"><x-money :amount="$this->stockTotals['gross']" /></td>
                                <td class="num">{{ $this->stockTotals['fees']->format() }}</td>
                                <td class="num"><x-money :amount="$this->stockTotals['net']" /></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between gap-4">
                <h2 class="section-title">Options</h2>
                <button type="button" wire:click="export('options')" class="btn-secondary"><x-icon name="download" class="size-4" />Download CSV</button>
            </div>

            @if ($this->optionCloses->isEmpty())
                <x-empty-state>No option contracts were closed in this period.</x-empty-state>
            @else
                <div class="card overflow-x-auto p-0">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Closed on</th>
                                <th>Account</th>
                                <th>Option</th>
                                <th>Event</th>
                                <th class="text-right">Contracts</th>
                                <th class="text-right">Opening premium</th>
                                <th class="text-right">Closing amount</th>
                                <th class="text-right">Gross P/L</th>
                                <th class="text-right">Fees</th>
                                <th class="text-right">Net P/L</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->optionCloses as $event)
                                <tr wire:key="realized-option-{{ $event->id }}">
                                    <td>
                                        <a href="{{ route('options.show', $event->option_position_id) }}" class="link" wire:navigate>{{ $event->event_date->format('M j, Y') }}</a>
                                    </td>
                                    <td>{{ $event->account->displayName() }}</td>
                                    <td class="font-medium">{{ $event->position->description() }}</td>
                                    <td>{{ $event->event_type->label() }}</td>
                                    <td class="num">{{ $event->contracts }}</td>
                                    <td class="num">{{ $event->allocated_premium->format() }}</td>
                                    <td class="num">{{ $event->closingCost()->format() }}</td>
                                    <td class="num"><x-money :amount="$event->grossProfit()" /></td>
                                    <td class="num">{{ $event->totalFees()->format() }}</td>
                                    <td class="num"><x-money :amount="$event->netProfit()" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="7">Option total</td>
                                <td class="num"><x-money :amount="$this->optionTotals['gross']" /></td>
                                <td class="num">{{ $this->optionTotals['fees']->format() }}</td>
                                <td class="num"><x-money :amount="$this->optionTotals['net']" /></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between gap-4">
                <h2 class="section-title">Dividends</h2>
                <button type="button" wire:click="export('dividends')" class="btn-secondary"><x-icon name="download" class="size-4" />Download CSV</button>
            </div>

            @if ($this->dividends->isEmpty())
                <x-empty-state>No confirmed dividends were paid in this period.</x-empty-state>
            @else
                <div class="card overflow-x-auto p-0">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Paid on</th>
                                <th>Account</th>
                                <th>Ticker</th>
                                <th>Ex-date</th>
                                <th class="text-right">Expected</th>
                                <th class="text-right">Received</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->dividends as $dividend)
                                <tr wire:key="realized-dividend-{{ $dividend->id }}">
                                    <td>
                                        <a href="{{ route('dividends.edit', $dividend) }}" class="link" wire:navigate>{{ $dividend->pay_date->format('M j, Y') }}</a>
                                    </td>
                                    <td>{{ $dividend->account->displayName() }}</td>
                                    <td class="font-medium">{{ $dividend->ticker }}</td>
                                    <td>{{ $dividend->ex_date->format('M j, Y') }}</td>
                                    <td class="num">{{ $dividend->expected_amount?->format() }}</td>
                                    <td class="num">{{ $dividend->actual_amount?->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5">Dividend total</td>
                                <td class="num"><x-money :amount="$this->dividendTotal" /></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
