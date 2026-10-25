<?php

use App\Livewire\ReportPage;
use App\Models\Dividend;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Dividends')] class extends ReportPage
{
    /**
     * Dividends paid in the selected period, voided ones included but marked.
     *
     * @return Collection<int, Dividend>
     */
    #[Computed]
    public function dividends(): Collection
    {
        if ($this->reportingPeriod === null) {
            return new Collection;
        }

        $query = Dividend::query()->with('account');

        $this->applyAccountFilter($query);
        $this->applyTickerFilter($query);
        $this->reportingPeriod->applyTo($query, 'pay_date');

        return $query->orderBy('pay_date')->orderBy('ticker')->orderBy('id')->get();
    }

    /**
     * The dividends shown, one row each, as a CSV file.
     */
    public function export(): StreamedResponse
    {
        return CsvExport::download('dividends', [
            'pay_date', 'ex_date', 'account', 'ticker', 'amount_per_share', 'eligible_shares', 'expected_amount',
            'actual_amount', 'confirmed', 'voided', 'note',
        ], $this->dividends->map(fn (Dividend $dividend) => [
            $dividend->pay_date,
            $dividend->ex_date,
            $dividend->account->displayName(),
            $dividend->ticker,
            $dividend->amount_per_share,
            $dividend->eligible_shares ?? $dividend->currentEligibleShares(),
            $dividend->expected_amount ?? $dividend->currentExpectedAmount(),
            $dividend->actual_amount,
            $dividend->isConfirmed(),
            $dividend->isVoided(),
            $dividend->reference_note,
        ])->all());
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">Dividends</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">By pay date. Only confirmed amounts count as income and cash. Dividends never change stock cost.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <button type="button" wire:click="export" class="btn-secondary"><x-icon name="download" class="size-4" />Download CSV</button>
            <a href="{{ route('dividends.create') }}" class="btn-primary" wire:navigate>Add dividend</a>
        </div>
    </div>

    @include('partials.report-filters')

    @if ($this->dividends->isEmpty())
        <x-empty-state>No dividends in this period.</x-empty-state>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Pay date</th>
                        <th>Ex-date</th>
                        <th>Account</th>
                        <th>Ticker</th>
                        <th class="text-right">Per share</th>
                        <th class="text-right">Eligible shares</th>
                        <th class="text-right">Expected</th>
                        <th class="text-right">Actual</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->dividends as $dividend)
                        @php
                            $eligibleShares = $dividend->eligible_shares ?? $dividend->currentEligibleShares();
                            $expected = $dividend->expected_amount ?? $dividend->currentExpectedAmount();
                        @endphp
                        <tr wire:key="dividend-{{ $dividend->id }}" @class(['text-zinc-400 line-through dark:text-zinc-500' => $dividend->isVoided()])>
                            <td>{{ $dividend->pay_date->format('M j, Y') }}</td>
                            <td>{{ $dividend->ex_date->format('M j, Y') }}</td>
                            <td>{{ $dividend->account->displayName() }}</td>
                            <td class="font-medium">{{ $dividend->ticker }}</td>
                            <td class="num">{{ $dividend->amount_per_share->format() }}</td>
                            <td class="num">{{ $eligibleShares->format() }}</td>
                            <td class="num">{{ $expected->format() }}</td>
                            <td class="num">
                                @if ($dividend->isVoided())
                                    {{ $dividend->actual_amount?->format() ?? '—' }}
                                @else
                                    <x-money :amount="$dividend->actual_amount" />
                                @endif
                            </td>
                            <td>
                                @if ($dividend->isVoided())
                                    <x-badge>Voided</x-badge>
                                @elseif ($dividend->isConfirmed() && $dividend->hasDifference())
                                    <x-badge tone="amber"><x-icon name="warning" class="size-3.5" />Confirmed · differs by {{ $dividend->difference()?->format() }}</x-badge>
                                @elseif ($dividend->isConfirmed())
                                    <x-badge tone="green"><x-icon name="success" class="size-3.5" />Confirmed</x-badge>
                                @elseif ($eligibleShares->isZero())
                                    <x-badge tone="amber"><x-icon name="warning" class="size-3.5" />No shares on ex-date</x-badge>
                                @else
                                    <x-badge tone="blue"><x-icon name="clock" class="size-3.5" />Expected · not confirmed</x-badge>
                                @endif
                            </td>
                            <td class="text-right">
                                <a href="{{ route('dividends.edit', $dividend) }}" class="link" wire:navigate>{{ $dividend->isVoided() ? 'View' : 'Open' }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
