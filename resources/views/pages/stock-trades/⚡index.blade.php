<?php

use App\Enums\TradeSide;
use App\Livewire\ReportPage;
use App\Models\StockTrade;
use App\Support\CsvExport;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Title('Stock trades')] class extends ReportPage
{
    use WithPagination;

    public function updated(string $property): void
    {
        if (in_array($property, ['account', 'broker', 'ticker', 'year', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Trades in the selected period, newest first. Voided trades are shown
     * but marked, so nothing disappears from view.
     *
     * @return LengthAwarePaginator<int, StockTrade>
     */
    #[Computed]
    public function trades(): LengthAwarePaginator
    {
        return $this->tradesQuery()->orderByDesc('trade_date')->orderByDesc('id')->paginate(100);
    }

    /**
     * Every trade matching the filters, oldest first, as a CSV file.
     */
    public function export(): StreamedResponse
    {
        $trades = $this->tradesQuery()->orderBy('trade_date')->orderBy('id')->lazy();

        return CsvExport::download('stock-trades', [
            'trade_date', 'settlement_date', 'account', 'side', 'ticker', 'shares', 'price_per_share',
            'gross_amount', 'fees', 'cash_amount', 'from_assignment', 'voided', 'note',
        ], $trades->map(fn (StockTrade $trade) => [
            $trade->trade_date,
            $trade->settlement_date,
            $trade->account->displayName(),
            $trade->side,
            $trade->ticker,
            $trade->shares,
            $trade->price_per_share,
            $trade->grossAmount(),
            $trade->fees,
            $trade->side === TradeSide::Buy ? Money::zero()->minus($trade->grossAmount()->plus($trade->fees)) : $trade->grossAmount()->minus($trade->fees),
            $trade->isFromAssignment(),
            $trade->isVoided(),
            $trade->reference_note,
        ]));
    }

    /**
     * @return Builder<StockTrade>
     */
    private function tradesQuery(): Builder
    {
        $query = StockTrade::query()->with('account');

        $this->applyAccountFilter($query);
        $this->applyTickerFilter($query);
        $this->reportingPeriod?->applyTo($query, 'trade_date');

        if ($this->reportingPeriod === null) {
            $query->whereRaw('0 = 1');
        }

        return $query;
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="page-title">Stock trades</h1>
        <div class="flex flex-wrap gap-3">
            <button type="button" wire:click="export" class="btn-secondary"><x-icon name="download" class="size-4" />Download CSV</button>
            <a href="{{ route('stock-trades.create') }}" class="btn-primary" wire:navigate>Add trade</a>
        </div>
    </div>

    @include('partials.report-filters')

    @if ($this->trades->isEmpty())
        <x-empty-state>No stock trades in this period.</x-empty-state>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Trade date</th>
                        <th>Account</th>
                        <th>Side</th>
                        <th>Ticker</th>
                        <th class="text-right">Shares</th>
                        <th class="text-right">Price</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Fee</th>
                        <th>Settles</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->trades as $trade)
                        <tr wire:key="trade-{{ $trade->id }}" @class(['text-zinc-400 line-through dark:text-zinc-500' => $trade->isVoided()])>
                            <td>{{ $trade->trade_date->format('M j, Y') }}</td>
                            <td>{{ $trade->account->displayName() }}</td>
                            <td>
                                <x-badge :tone="$trade->isBuy() ? 'blue' : 'gray'">{{ $trade->side->label() }}</x-badge>
                                @if ($trade->isFromAssignment())
                                    <span class="block text-xs text-zinc-500">Assignment</span>
                                @endif
                            </td>
                            <td class="font-medium">{{ $trade->ticker }}</td>
                            <td class="num">{{ $trade->shares->format() }}</td>
                            <td class="num">{{ $trade->price_per_share->format() }}</td>
                            <td class="num">{{ $trade->grossAmount()->format() }}</td>
                            <td class="num">{{ $trade->fees->format() }}</td>
                            <td>{{ $trade->settlement_date->format('M j, Y') }}</td>
                            <td class="text-right">
                                @if ($trade->isVoided())
                                    <x-badge>Voided</x-badge>
                                @endif
                                <a href="{{ route('stock-trades.edit', $trade) }}" class="link" wire:navigate>{{ $trade->isVoided() ? 'View' : 'Edit' }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $this->trades->links() }}
    @endif
</div>
