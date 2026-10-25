<?php

use App\Livewire\ReportPage;
use App\Models\StockTrade;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\WithPagination;

new #[Title('Stock trades')] class extends ReportPage
{
    use WithPagination;

    public function updated(string $property): void
    {
        if (in_array($property, ['account', 'year', 'from', 'to'], true)) {
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
        $query = StockTrade::query()->with('account');

        $this->applyAccountFilter($query);
        $this->reportingPeriod?->applyTo($query, 'trade_date');

        if ($this->reportingPeriod === null) {
            $query->whereRaw('0 = 1');
        }

        return $query->orderByDesc('trade_date')->orderByDesc('id')->paginate(100);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">Stock trades</h1>
        <a href="{{ route('stock-trades.create') }}" class="btn-primary" wire:navigate>Add trade</a>
    </div>

    @include('partials.report-filters')

    @if ($this->trades->isEmpty())
        <div class="card">No stock trades in this period.</div>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">Trade date</th>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Side</th>
                        <th class="px-4 py-3 font-medium">Ticker</th>
                        <th class="px-4 py-3 text-right font-medium">Shares</th>
                        <th class="px-4 py-3 text-right font-medium">Price</th>
                        <th class="px-4 py-3 text-right font-medium">Amount</th>
                        <th class="px-4 py-3 text-right font-medium">Fee</th>
                        <th class="px-4 py-3 font-medium">Settles</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->trades as $trade)
                        <tr wire:key="trade-{{ $trade->id }}" @class(['text-zinc-400 line-through dark:text-zinc-500' => $trade->isVoided()])>
                            <td class="px-4 py-3">{{ $trade->trade_date->format('M j, Y') }}</td>
                            <td class="px-4 py-3">{{ $trade->account->displayName() }}</td>
                            <td class="px-4 py-3">{{ $trade->side->label() }}</td>
                            <td class="px-4 py-3 font-medium">{{ $trade->ticker }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $trade->shares->format() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $trade->price_per_share->format() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $trade->grossAmount()->format() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $trade->fees->format() }}</td>
                            <td class="px-4 py-3">{{ $trade->settlement_date->format('M j, Y') }}</td>
                            <td class="px-4 py-3 text-right">
                                @if ($trade->isVoided())
                                    <span>Voided</span>
                                @endif
                                <a href="{{ route('stock-trades.edit', $trade) }}" class="underline underline-offset-4" wire:navigate>{{ $trade->isVoided() ? 'View' : 'Edit' }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $this->trades->links() }}
    @endif
</div>
