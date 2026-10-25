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
            <h1 class="page-title">Cash</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Every cash movement, worked out from your records. Opening cash is the starting balance, not a deposit or income.
            </p>
        </div>
        <div class="flex flex-wrap gap-3">
            <button type="button" wire:click="export" class="btn-secondary"><x-icon name="download" class="size-4" />Download CSV</button>
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
        @if ($total->isNegative())
            <x-alert level="critical" title="Negative cash balance">
                Cash on {{ $this->balanceDate->format('M j, Y') }} is {{ $total->format() }}. Check for a missing deposit or a mistyped trade. Nothing has been changed or blocked.
            </x-alert>
        @endif

        <div class="grid gap-4 sm:grid-cols-3">
            <x-stat-card :label="'Total cash · '.$this->balanceDate->format('M j, Y')" icon="cash" :warning="$total->isNegative() ? 'Negative cash balance' : null">
                <x-money :amount="$total" tone="balance" />
            </x-stat-card>
            <x-stat-card label="Settled cash" icon="cash" hint="Trades whose settlement date has passed">
                <x-money :amount="$settled" tone="balance" />
            </x-stat-card>
            <x-stat-card label="Unsettled" icon="clock" hint="Waiting for trades to settle">
                <x-money :amount="$total->minus($settled)" tone="plain" />
            </x-stat-card>
        </div>

        <div class="card overflow-x-auto p-0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Settles</th>
                        <th>Account</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th class="text-right">Gross</th>
                        <th class="text-right">Fees</th>
                        <th class="text-right">Cash</th>
                        <th class="text-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                        <td colspan="8" class="font-medium">Balance brought forward</td>
                        <td class="num font-medium"><x-money :amount="$this->ledger->totalBefore($this->reportingPeriod)" tone="balance" /></td>
                    </tr>
                    @forelse ($this->rows as $row)
                        <tr wire:key="cash-{{ $row['entry']->sourceKey }}">
                            <td>{{ $row['entry']->date->format('M j, Y') }}</td>
                            <td>{{ $row['entry']->settlementDate->format('M j, Y') }}</td>
                            <td>{{ $this->accountNames[$row['entry']->accountId] ?? '' }}</td>
                            <td>{{ $row['entry']->category->label() }}</td>
                            <td>
                                <a href="{{ $row['entry']->sourceUrl }}" class="link" wire:navigate>{{ $row['entry']->description }}</a>
                            </td>
                            <td class="num">{{ $row['entry']->gross->format() }}</td>
                            <td class="num">{{ $row['entry']->fees->isZero() ? '' : '-'.$row['entry']->fees->format() }}</td>
                            <td class="num">{{ $row['entry']->amount()->format() }}</td>
                            <td class="num font-medium"><x-money :amount="$row['balance']" tone="balance" /></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-8 text-center text-zinc-500">No cash movements in this period.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
