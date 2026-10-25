<?php

use App\Livewire\Forms\DividendForm;
use App\Models\AuditEntry;
use App\Models\Dividend;
use App\Models\Setting;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dividend')] class extends Component
{
    public Dividend $dividend;

    public DividendForm $form;

    public function mount(Dividend $dividend): void
    {
        $this->dividend = $dividend;
        $this->form->setDividend($dividend);
    }

    public function acceptExpected(): void
    {
        $this->ensureNotVoided();

        if ($this->dividend->isConfirmed()) {
            $this->validate(
                ['form.reason' => ['required', 'string', 'max:1000']],
                ['form.reason.required' => 'Please explain why you are changing the confirmed amount.'],
            );
        }

        $this->form->confirm($this->currentExpectedAmount);

        $this->afterChange('Expected amount accepted as received.');
    }

    public function confirmActual(): void
    {
        $this->ensureNotVoided();

        $this->form->confirm();

        $this->afterChange('Actual amount confirmed.');
    }

    public function save(): void
    {
        $this->ensureNotVoided();

        $this->form->update();

        $this->afterChange('Dividend saved.');
    }

    public function void(): void
    {
        $this->ensureNotVoided();

        $this->validate(
            ['form.reason' => ['required', 'string', 'max:1000']],
            ['form.reason.required' => 'Please explain why you are voiding this dividend. It is saved in the audit history.'],
        );

        $this->dividend->void($this->form->reason);

        $this->form->reset('reason');

        $this->afterChange('Dividend voided. It is kept in the history but no longer counts.');
    }

    #[Computed]
    public function currentEligibleShares(): Quantity
    {
        return $this->dividend->currentEligibleShares();
    }

    #[Computed]
    public function currentExpectedAmount(): Money
    {
        return $this->dividend->currentExpectedAmount();
    }

    /**
     * @return Collection<int, AuditEntry>
     */
    #[Computed]
    public function auditEntries(): Collection
    {
        return $this->dividend->auditEntries()->with('user')->latest('id')->get();
    }

    #[Computed]
    public function displayTimezone(): string
    {
        return Setting::current()->display_timezone;
    }

    private function ensureNotVoided(): void
    {
        abort_if($this->dividend->isVoided(), 403, 'A voided dividend cannot be changed.');
    }

    private function afterChange(string $message): void
    {
        $this->dividend->refresh();

        unset($this->currentEligibleShares, $this->currentExpectedAmount, $this->auditEntries);

        session()->now('message', $message);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">
                {{ $dividend->ticker }} dividend · ex-date {{ $dividend->ex_date->format('M j, Y') }}
                @if ($dividend->isVoided())
                    <x-badge tone="gray" class="ms-2 align-middle">Voided</x-badge>
                @endif
            </h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                {{ $dividend->account->displayName() }} · {{ $dividend->amount_per_share->format() }} per share · paid {{ $dividend->pay_date->format('M j, Y') }}
            </p>
        </div>
        <a href="{{ route('dividends.index') }}" class="btn-secondary" wire:navigate>Back to dividends</a>
    </div>

    @if (session('message'))
        <x-alert level="success">{{ session('message') }}</x-alert>
    @endif

    <section class="card flex flex-col gap-4">
        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Shares held on the ex-date</dt>
                <dd class="tabular-nums">
                    @if ($this->currentEligibleShares->isZero())
                        <strong>No shares on ex-date</strong>
                    @else
                        {{ $this->currentEligibleShares->format() }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Expected dividend</dt>
                <dd class="tabular-nums">{{ $this->currentExpectedAmount->format() }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Actual received</dt>
                <dd class="tabular-nums">{{ $dividend->actual_amount?->format() ?? 'Not confirmed yet' }}</dd>
            </div>
        </dl>

        @if ($dividend->isConfirmed() && $dividend->hasDifference())
            <p class="rounded-md border border-amber-300 px-3 py-2 text-sm text-amber-800 dark:border-amber-700 dark:text-amber-300">
                The actual amount differs from the expected {{ $dividend->expected_amount?->format() }} by {{ $dividend->difference()?->format() }}.
                Both are kept. Check the broker statement, for example for withholding or a share count difference.
            </p>
        @endif

        @if ($dividend->isConfirmed() && $dividend->eligibleSharesChangedSinceConfirmation())
            <p class="rounded-md border border-amber-300 px-3 py-2 text-sm text-amber-800 dark:border-amber-700 dark:text-amber-300">
                Your shares on the ex-date have changed since this dividend was confirmed
                ({{ $dividend->eligible_shares?->format() }} then, {{ $this->currentEligibleShares->format() }} now). Confirm again to update the expected amount.
            </p>
        @endif

        @unless ($dividend->isVoided())
            <div class="flex flex-col gap-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <h2 class="section-title">{{ $dividend->isConfirmed() ? 'Change the amount received' : 'Confirm what you received' }}</h2>

                <div class="flex flex-wrap items-end gap-4">
                    <button type="button" wire:click="acceptExpected" class="btn-secondary">Accept expected {{ $this->currentExpectedAmount->format() }}</button>

                    <div>
                        <label for="actual_amount" class="form-label required">Actual amount from the broker</label>
                        <input id="actual_amount" type="text" inputmode="decimal" wire:model="form.actual_amount" class="form-input" placeholder="e.g. 42.50">
                        @error('form.actual_amount') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <button type="button" wire:click="confirmActual" class="btn-primary">Confirm actual</button>
                </div>
            </div>
        @endunless
    </section>

    @if ($dividend->isVoided())
        <x-alert level="info" title="Voided">This dividend was voided. It no longer counts in any total and cannot be changed. Its history is shown below.</x-alert>
    @else
        <form wire:submit="save" class="card flex flex-col gap-6">
            <h2 class="section-title">Details</h2>

            @include('partials.dividend-fields', ['dividend' => $dividend])

            <div>
                <label for="reason" class="form-label">Reason for this change</label>
                <textarea id="reason" rows="2" wire:model="form.reason" class="form-input" placeholder="e.g. Corrected the ex-date"></textarea>
                <p class="form-help">Required to change the details or a confirmed amount, and to void. It is kept permanently in the history below.</p>
                @error('form.reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save changes</button>
                <button type="button" wire:click="void" wire:confirm="Void this dividend? It stays in the history but no longer counts." class="btn-danger">Void dividend</button>
            </div>
        </form>
    @endif

    @include('partials.audit-history', ['entries' => $this->auditEntries, 'timezone' => $this->displayTimezone])
</div>
