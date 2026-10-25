<?php

use App\Actions\Cash\BuildCashLedger;
use App\Livewire\Forms\BrokerBalanceForm;
use App\Livewire\ReportPage;
use App\Models\BrokerBalance;
use App\Support\CashLedger;
use App\Support\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Cash reconciliation')] class extends ReportPage
{
    public BrokerBalanceForm $form;

    /** @var array<int, CashLedger> */
    private array $ledgers = [];

    public function mount(): void
    {
        $this->form->account_id = $this->account;
    }

    public function updatedAccount(): void
    {
        $this->form->account_id = $this->account;
    }

    public function save(): void
    {
        $balance = $this->form->store();

        unset($this->comparisons);

        session()->now('message', "Broker balance for {$balance->as_of_date->format('M j, Y')} saved.");
    }

    /**
     * Each broker balance beside the calculated balances for the same
     * account and date, newest first.
     *
     * @return Collection<int, array{balance: BrokerBalance, total: Money, totalDifference: Money, settled: Money, settledDifference: Money|null}>
     */
    #[Computed]
    public function comparisons(): Collection
    {
        $query = BrokerBalance::query()->with('account')->notVoided();

        $this->applyAccountFilter($query);

        return $query->orderByDesc('as_of_date')->orderByDesc('id')->get()
            ->map(function (BrokerBalance $balance): array {
                $ledger = $this->ledgerFor($balance->account_id);
                $total = $ledger->totalOn($balance->as_of_date);
                $settled = $ledger->settledOn($balance->as_of_date);

                return [
                    'balance' => $balance,
                    'total' => $total,
                    'totalDifference' => $balance->total_cash->minus($total),
                    'settled' => $settled,
                    'settledDifference' => $balance->settled_cash?->minus($settled),
                ];
            })
            ->toBase();
    }

    private function ledgerFor(int $accountId): CashLedger
    {
        return $this->ledgers[$accountId] ??= app(BuildCashLedger::class)->handle($accountId);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">Cash reconciliation</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Enter the cash balance your broker shows at the end of a day. The app compares it with the cash worked out from your
                records. It never changes a transaction to make them match; a difference points to a missing or wrong entry.
            </p>
        </div>
        <a href="{{ route('cash.index') }}" class="btn-secondary" wire:navigate>Back to cash</a>
    </div>

    @if (session('message'))
        <x-alert level="success">{{ session('message') }}</x-alert>
    @endif

    @include('partials.report-filters', ['showPeriod' => false, 'showTicker' => false])

    <form wire:submit="save" class="card flex flex-col gap-4">
        <h2 class="section-title">Enter a broker balance</h2>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="balance_account" class="form-label required">Account</label>
                <select id="balance_account" wire:model="form.account_id" class="form-input">
                    <option value="">Choose…</option>
                    @foreach ($this->filterAccounts as $filterAccount)
                        <option value="{{ $filterAccount->id }}" wire:key="balance-account-{{ $filterAccount->id }}">{{ $filterAccount->displayName() }}</option>
                    @endforeach
                </select>
                @error('form.account_id') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="as_of_date" class="form-label required">Date (end of day)</label>
                <input id="as_of_date" type="date" wire:model="form.as_of_date" class="form-input">
                @error('form.as_of_date') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="total_cash" class="form-label required">Cash balance</label>
                <input id="total_cash" type="text" inputmode="decimal" wire:model="form.total_cash" class="form-input" placeholder="e.g. 2448.66">
                @error('form.total_cash') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="settled_cash" class="form-label">Settled cash (optional)</label>
                <input id="settled_cash" type="text" inputmode="decimal" wire:model="form.settled_cash" class="form-input" placeholder="If your broker shows it">
                @error('form.settled_cash') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label for="balance_note" class="form-label">Reference note (optional)</label>
                <input id="balance_note" type="text" wire:model="form.reference_note" class="form-input" placeholder="e.g. March statement">
                @error('form.reference_note') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save and compare</button>
        </div>
    </form>

    @if ($this->comparisons->isEmpty())
        <x-empty-state icon="cash">No broker balances entered yet. Enter the cash your broker shows above to compare it with your records.</x-empty-state>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Account</th>
                        <th class="text-right">Broker cash</th>
                        <th class="text-right">Calculated cash</th>
                        <th class="text-right">Difference</th>
                        <th class="text-right">Broker settled</th>
                        <th class="text-right">Calculated settled</th>
                        <th class="text-right">Difference</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->comparisons as $comparison)
                        @php
                            $matches = $comparison['totalDifference']->isZero() && ($comparison['settledDifference'] === null || $comparison['settledDifference']->isZero());
                        @endphp
                        <tr wire:key="balance-{{ $comparison['balance']->id }}">
                            <td>{{ $comparison['balance']->as_of_date->format('M j, Y') }}</td>
                            <td>{{ $comparison['balance']->account->displayName() }}</td>
                            <td class="num">{{ $comparison['balance']->total_cash->format() }}</td>
                            <td class="num">{{ $comparison['total']->format() }}</td>
                            <td class="num">{{ $comparison['totalDifference']->format() }}</td>
                            <td class="num">{{ $comparison['balance']->settled_cash?->format() ?? '—' }}</td>
                            <td class="num">{{ $comparison['settled']->format() }}</td>
                            <td class="num">{{ $comparison['settledDifference']?->format() ?? '—' }}</td>
                            <td>
                                @if ($matches)
                                    <x-badge tone="green"><x-icon name="success" class="size-3.5" />Matches</x-badge>
                                @else
                                    <x-badge tone="red"><x-icon name="warning" class="size-3.5" />Difference — look for a missing or wrong entry</x-badge>
                                @endif
                            </td>
                            <td class="text-right">
                                <a href="{{ route('cash.broker-balances.edit', $comparison['balance']) }}" class="link" wire:navigate>Edit</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="form-help">Difference is the broker's figure minus the calculated figure. A positive difference means the broker shows more cash than your records explain.</p>
    @endif
</div>
