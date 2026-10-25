<?php

use App\Enums\OpeningStatus;
use App\Livewire\Forms\OpeningCashForm;
use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\AuditEntry;
use App\Models\OptionPosition;
use App\Models\Setting;
use App\Models\StockLot;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Opening balances')] class extends Component
{
    public Account $account;

    public OpeningCashForm $cash;

    public function mount(Account $account): void
    {
        $this->account = $account;
        $this->cash->setOpening($account->opening);
    }

    public function saveCash(): void
    {
        $this->cash->save($this->account);

        $this->refreshOpening();

        session()->now('message', 'Starting cash saved.');
    }

    public function markComplete(): void
    {
        if (! $this->opening?->hasCash()) {
            $this->addError('completion', 'Enter the starting cash (even if it is $0.00) before marking this account complete.');

            return;
        }

        $this->opening->markComplete();

        $this->refreshOpening();

        session()->now('message', 'Opening balances marked complete. You can still correct them later.');
    }

    public function markInProgress(): void
    {
        $this->opening?->markInProgress('Reopened opening-balance setup');

        $this->refreshOpening();

        session()->now('message', 'Opening balances marked as still in progress.');
    }

    #[Computed]
    public function opening(): ?AccountOpening
    {
        return $this->account->opening()->first();
    }

    /**
     * Opening stock lots, including voided ones so nothing disappears from view.
     *
     * @return Collection<int, StockLot>
     */
    #[Computed]
    public function stockLots(): Collection
    {
        return $this->account->stockLots()->openingBalance()
            ->orderByRaw('voided_at is not null')
            ->orderBy('ticker')
            ->orderBy('acquired_on')
            ->get();
    }

    /**
     * @return Collection<int, OptionPosition>
     */
    #[Computed]
    public function optionPositions(): Collection
    {
        return $this->account->optionPositions()->openingBalance()
            ->orderByRaw('voided_at is not null')
            ->orderBy('expires_on')
            ->orderBy('underlying')
            ->get();
    }

    #[Computed]
    public function status(): OpeningStatus
    {
        $activeEntryCount = $this->stockLots->reject->isVoided()->count() + $this->optionPositions->reject->isVoided()->count();

        return OpeningStatus::determine($this->opening, $activeEntryCount);
    }

    #[Computed]
    public function totalCostBasis(): Money
    {
        return $this->stockLots->reject->isVoided()
            ->reduce(fn (Money $total, StockLot $lot) => $total->plus($lot->costBasis()), Money::zero());
    }

    #[Computed]
    public function asOfDate(): CarbonImmutable
    {
        return $this->opening->as_of_date ?? Setting::current()->reporting_start_date;
    }

    /**
     * @return Collection<int, AuditEntry>
     */
    #[Computed]
    public function cashHistory(): Collection
    {
        return $this->opening?->auditEntries()->with('user')->latest('id')->get() ?? new Collection;
    }

    #[Computed]
    public function displayTimezone(): string
    {
        return Setting::current()->display_timezone;
    }

    private function refreshOpening(): void
    {
        unset($this->opening, $this->status, $this->cashHistory);

        $this->account->unsetRelation('opening');
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">{{ $account->displayName() }}</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Opening balance — as of {{ $this->asOfDate->format('F j, Y') }} · Status: <strong>{{ $this->status->label() }}</strong>
            </p>
        </div>
        <a href="{{ route('opening-balances.index') }}" class="btn-secondary" wire:navigate>Back to opening balances</a>
    </div>

    @if (session('message'))
        <x-alert level="success">{{ session('message') }}</x-alert>
    @endif

    {{-- Starting cash --}}
    <form wire:submit="saveCash" class="card flex flex-col gap-4">
        <div>
            <h2 class="section-title">1. Starting cash</h2>
            <p class="form-help">
                The cash in the account at the start of {{ $this->asOfDate->format('F j, Y') }}. It is a starting point,
                not a deposit or income. Enter a negative amount if you owed the broker money.
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="cash_balance" class="form-label required">Starting cash</label>
                <input id="cash_balance" type="text" inputmode="decimal" wire:model="cash.cash_balance" class="form-input" placeholder="e.g. 2000.00">
                @error('cash.cash_balance') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="unsettled_cash" class="form-label">Of which unsettled (optional)</label>
                <input id="unsettled_cash" type="text" inputmode="decimal" wire:model="cash.unsettled_cash" class="form-input" placeholder="Leave blank if unknown">
                <p class="form-help">Blank means "not known", not zero.</p>
                @error('cash.unsettled_cash') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        @include('partials.opening-source-fields', ['sourceField' => 'cash.cash_source', 'noteField' => 'cash.cash_note', 'idPrefix' => 'cash'])

        @if ($this->opening?->hasCash())
            <div>
                <label for="cash_reason" class="form-label">Reason for this change</label>
                <input id="cash_reason" type="text" wire:model="cash.reason" class="form-input" placeholder="e.g. Corrected from the December statement">
                @error('cash.reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        @endif

        <div>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save starting cash</button>
        </div>
    </form>

    {{-- Stock lots --}}
    <section class="card flex flex-col gap-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="section-title">2. Stock lots already held</h2>
                <p class="form-help">One line per purchase, with its original date and cost. Enter only the shares still held on {{ $this->asOfDate->format('F j, Y') }}.</p>
            </div>
            <a href="{{ route('opening-balances.stock-lots.create', $account) }}" class="btn-secondary" wire:navigate>Add stock lot</a>
        </div>

        @if ($this->stockLots->isEmpty())
            <p class="text-sm text-zinc-500 dark:text-zinc-400">No stock lots entered.</p>
        @else
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Ticker</th>
                            <th>Bought on</th>
                            <th class="text-right">Shares</th>
                            <th class="text-right">Cost / share</th>
                            <th class="text-right">Cost basis</th>
                            <th>Source</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->stockLots as $lot)
                            <tr wire:key="lot-{{ $lot->id }}" @class(['text-zinc-400 line-through dark:text-zinc-500' => $lot->isVoided()])>
                                <td class="font-medium">{{ $lot->ticker }}</td>
                                <td>{{ $lot->acquired_on->format('M j, Y') }}</td>
                                <td class="num">{{ $lot->shares->format() }}</td>
                                <td class="num">{{ $lot->cost_per_share->format() }}</td>
                                <td class="num">{{ $lot->costBasis()->format() }}</td>
                                <td>{{ $lot->source?->label() }}</td>
                                <td class="py-2 text-right">
                                    @if ($lot->isVoided())
                                        <span>Voided</span>
                                    @endif
                                    <a href="{{ route('opening-balances.stock-lots.edit', $lot) }}" class="link" wire:navigate>{{ $lot->isVoided() ? 'View' : 'Edit' }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="font-medium">Total cost basis (excluding voided lots)</td>
                            <td class="text-right font-medium tabular-nums">{{ $this->totalCostBasis->format() }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </section>

    {{-- Option positions --}}
    <section class="card flex flex-col gap-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="section-title">3. Options still open</h2>
                <p class="form-help">Options you sold before {{ $this->asOfDate->format('F j, Y') }} that had not yet closed, expired or been assigned. Enter only the contracts still open.</p>
            </div>
            <a href="{{ route('opening-balances.options.create', $account) }}" class="btn-secondary" wire:navigate>Add option</a>
        </div>

        @if ($this->optionPositions->isEmpty())
            <p class="text-sm text-zinc-500 dark:text-zinc-400">No open options entered.</p>
        @else
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Option</th>
                            <th>Opened on</th>
                            <th class="text-right">Contracts</th>
                            <th class="text-right">Premium / share</th>
                            <th class="text-right">Premium received</th>
                            <th class="text-right">Opening fees</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->optionPositions as $option)
                            <tr wire:key="option-{{ $option->id }}" @class(['text-zinc-400 line-through dark:text-zinc-500' => $option->isVoided()])>
                                <td class="font-medium">{{ $option->description() }}</td>
                                <td>{{ $option->opened_on->format('M j, Y') }}</td>
                                <td class="num">
                                    {{ $option->contracts }}
                                    @if ($option->multiplier !== 100)
                                        <span class="text-xs text-zinc-500">(× {{ $option->multiplier }})</span>
                                    @endif
                                </td>
                                <td class="num">{{ $option->premium_per_share->format() }}</td>
                                <td class="num">{{ $option->premiumReceived()->format() }}</td>
                                <td class="num">{{ $option->opening_fees->format() }}</td>
                                <td class="py-2 text-right">
                                    @if ($option->isVoided())
                                        <span>Voided</span>
                                    @endif
                                    <a href="{{ route('opening-balances.options.edit', $option) }}" class="link" wire:navigate>{{ $option->isVoided() ? 'View' : 'Edit' }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="form-help">Premium received before {{ $this->asOfDate->format('F j, Y') }} is not counted as {{ $this->asOfDate->format('Y') }} income. It is used to work out the profit or loss when the option closes.</p>
        @endif
    </section>

    {{-- Completion --}}
    <section class="card flex flex-col gap-3">
        <h2 class="section-title">4. Finish</h2>

        @if ($this->status === OpeningStatus::Complete)
            <p class="text-sm">This account's opening balances are marked complete. You can still correct any entry; every change is recorded.</p>
            <div>
                <button type="button" wire:click="markInProgress" class="btn-secondary">Mark as still in progress</button>
            </div>
        @else
            <p class="text-sm">When the cash, stock lots and open options above match your broker's records for {{ $this->asOfDate->format('F j, Y') }}, mark this account complete.</p>
            <div>
                <button type="button" wire:click="markComplete" class="btn-primary">Mark complete</button>
            </div>
            @error('completion') <p class="form-error">{{ $message }}</p> @enderror
        @endif
    </section>

    @if ($this->opening)
        @include('partials.audit-history', [
            'entries' => $this->cashHistory,
            'timezone' => $this->displayTimezone,
            'heading' => 'Starting cash history',
        ])
    @endif
</div>
