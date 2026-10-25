<?php

use App\Livewire\ReportPage;
use App\Models\Dividend;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

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
        $this->reportingPeriod->applyTo($query, 'pay_date');

        return $query->orderBy('pay_date')->orderBy('ticker')->orderBy('id')->get();
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Dividends</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">By pay date. Only confirmed amounts count as income and cash. Dividends never change stock cost.</p>
        </div>
        <a href="{{ route('dividends.create') }}" class="btn-primary" wire:navigate>Add dividend</a>
    </div>

    @include('partials.report-filters')

    @if ($this->dividends->isEmpty())
        <div class="card">No dividends in this period.</div>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">Pay date</th>
                        <th class="px-4 py-3 font-medium">Ex-date</th>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Ticker</th>
                        <th class="px-4 py-3 text-right font-medium">Per share</th>
                        <th class="px-4 py-3 text-right font-medium">Eligible shares</th>
                        <th class="px-4 py-3 text-right font-medium">Expected</th>
                        <th class="px-4 py-3 text-right font-medium">Actual</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->dividends as $dividend)
                        @php
                            $eligibleShares = $dividend->eligible_shares ?? $dividend->currentEligibleShares();
                            $expected = $dividend->expected_amount ?? $dividend->currentExpectedAmount();
                        @endphp
                        <tr wire:key="dividend-{{ $dividend->id }}" @class(['text-zinc-400 line-through dark:text-zinc-500' => $dividend->isVoided()])>
                            <td class="px-4 py-3">{{ $dividend->pay_date->format('M j, Y') }}</td>
                            <td class="px-4 py-3">{{ $dividend->ex_date->format('M j, Y') }}</td>
                            <td class="px-4 py-3">{{ $dividend->account->displayName() }}</td>
                            <td class="px-4 py-3 font-medium">{{ $dividend->ticker }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $dividend->amount_per_share->format() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $eligibleShares->format() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $expected->format() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $dividend->actual_amount?->format() ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($dividend->isVoided())
                                    Voided
                                @elseif ($dividend->isConfirmed() && $dividend->hasDifference())
                                    <span class="font-medium text-amber-700 dark:text-amber-400">Confirmed · differs by {{ $dividend->difference()?->format() }}</span>
                                @elseif ($dividend->isConfirmed())
                                    Confirmed
                                @elseif ($eligibleShares->isZero())
                                    <span class="font-medium text-amber-700 dark:text-amber-400">No shares on ex-date</span>
                                @else
                                    Expected · not confirmed
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('dividends.edit', $dividend) }}" class="underline underline-offset-4" wire:navigate>{{ $dividend->isVoided() ? 'View' : 'Open' }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
