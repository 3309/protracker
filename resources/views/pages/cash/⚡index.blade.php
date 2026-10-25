<?php

use App\Actions\Cash\BuildCashLedger;
use App\Livewire\ReportPage;
use App\Support\CashLedger;
use App\Support\CashLedgerEntry;
use App\Support\CsvExport;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Cash')] class extends ReportPage
{
    #[Computed]
    public function ledger(): CashLedger
    {
        return app(BuildCashLedger::class)->handle($this->filteredAccountIds());
    }

    /**
     * The entries in the selected period with the running total cash.
     *
     * @return Collection<int, array{entry: CashLedgerEntry, balance: Money}>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->reportingPeriod === null ? collect() : $this->ledger->entriesWithBalances($this->reportingPeriod);
    }

    /**
     * Balances are shown at the end of the period, or today if the period
     * has not ended yet.
     */
    #[Computed]
    public function balanceDate(): CarbonImmutable
    {
        $periodEnd = $this->reportingPeriod?->end ?? $this->today();

        return $periodEnd->lessThan($this->today()) ? $periodEnd : $this->today();
    }

    /**
     * The ledger entries shown with the running balance, as a CSV file. The
     * first row is the balance brought forward.
     */
    public function export(): ?StreamedResponse
    {
        if ($this->reportingPeriod === null) {
            return null;
        }

        return CsvExport::download('cash-ledger', [
            'date', 'settlement_date', 'account', 'type', 'description', 'gross', 'fees', 'cash_amount', 'balance',
        ], [
            [$this->reportingPeriod->start, null, null, 'Balance brought forward', null, null, null, null, $this->ledger->totalBefore($this->reportingPeriod)],
            ...$this->rows->map(fn (array $row) => [
                $row['entry']->date,
                $row['entry']->settlementDate,
                $this->accountNames[$row['entry']->accountId] ?? '',
                $row['entry']->category->label(),
                $row['entry']->description,
                $row['entry']->gross,
                $row['entry']->fees->isZero() ? $row['entry']->fees : Money::zero()->minus($row['entry']->fees),
                $row['entry']->amount(),
                $row['balance'],
            ])->all(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function accountNames(): array
    {
        return $this->filterAccounts->mapWithKeys(fn ($account) => [$account->id => $account->displayName()])->all();
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Cash</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Every cash movement, worked out from your records. Opening cash is the starting balance, not a deposit or income.
            </p>
        </div>
        <div class="flex flex-wrap gap-3">
            <button type="button" wire:click="export" class="btn-secondary">Download CSV</button>
            <a href="{{ route('cash.reconciliation', ['account' => $account]) }}" class="btn-secondary" wire:navigate>Reconcile with broker</a>
            <a href="{{ route('cash.movements.create') }}" class="btn-primary" wire:navigate>Add deposit, withdrawal or other</a>
        </div>
    </div>

    @include('partials.report-filters', ['showTicker' => false])

    @if ($this->reportingPeriod !== null)
        @php
            $total = $this->ledger->totalOn($this->balanceDate);
            $settled = $this->ledger->settledOn($this->balanceDate);
        @endphp
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="card">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Total cash · {{ $this->balanceDate->format('M j, Y') }}</div>
                <div class="text-xl font-semibold tabular-nums">{{ $total->format() }}</div>
            </div>
            <div class="card">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Settled cash</div>
                <div class="text-xl font-semibold tabular-nums">{{ $settled->format() }}</div>
            </div>
            <div class="card">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Unsettled</div>
                <div class="text-xl font-semibold tabular-nums">{{ $total->minus($settled)->format() }}</div>
            </div>
        </div>

        <div class="card overflow-x-auto p-0">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">Date</th>
                        <th class="px-4 py-3 font-medium">Settles</th>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Type</th>
                        <th class="px-4 py-3 font-medium">Description</th>
                        <th class="px-4 py-3 text-right font-medium">Gross</th>
                        <th class="px-4 py-3 text-right font-medium">Fees</th>
                        <th class="px-4 py-3 text-right font-medium">Cash</th>
                        <th class="px-4 py-3 text-right font-medium">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                        <td colspan="8" class="px-4 py-2 font-medium">Balance brought forward</td>
                        <td class="px-4 py-2 text-right font-medium tabular-nums">{{ $this->ledger->totalBefore($this->reportingPeriod)->format() }}</td>
                    </tr>
                    @forelse ($this->rows as $row)
                        <tr wire:key="cash-{{ $row['entry']->sourceKey }}">
                            <td class="px-4 py-2">{{ $row['entry']->date->format('M j, Y') }}</td>
                            <td class="px-4 py-2">{{ $row['entry']->settlementDate->format('M j, Y') }}</td>
                            <td class="px-4 py-2">{{ $this->accountNames[$row['entry']->accountId] ?? '' }}</td>
                            <td class="px-4 py-2">{{ $row['entry']->category->label() }}</td>
                            <td class="px-4 py-2">
                                <a href="{{ $row['entry']->sourceUrl }}" class="underline underline-offset-4" wire:navigate>{{ $row['entry']->description }}</a>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $row['entry']->gross->format() }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $row['entry']->fees->isZero() ? '' : '-'.$row['entry']->fees->format() }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $row['entry']->amount()->format() }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $row['balance']->format() }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-3">No cash movements in this period.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
