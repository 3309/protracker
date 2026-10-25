<?php

use App\Livewire\Forms\BrokerBalanceForm;
use App\Models\AuditEntry;
use App\Models\BrokerBalance;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit broker balance')] class extends Component
{
    public BrokerBalance $brokerBalance;

    public BrokerBalanceForm $form;

    public function mount(BrokerBalance $brokerBalance): void
    {
        $this->brokerBalance = $brokerBalance;
        $this->form->setBrokerBalance($brokerBalance);
    }

    public function save(): void
    {
        abort_if($this->brokerBalance->isVoided(), 403, 'A voided balance cannot be changed.');

        $this->form->update();

        $this->brokerBalance->refresh();

        unset($this->auditEntries);

        session()->now('message', 'Broker balance saved.');
    }

    public function void(): void
    {
        abort_if($this->brokerBalance->isVoided(), 403, 'This balance is already voided.');

        $this->validate(
            ['form.reason' => ['required', 'string', 'max:1000']],
            ['form.reason.required' => 'Please explain why you are voiding this balance. It is saved in the audit history.'],
        );

        $this->brokerBalance->void($this->form->reason);

        $this->form->reset('reason');

        unset($this->auditEntries);

        session()->now('message', 'Broker balance voided.');
    }

    /**
     * @return Collection<int, AuditEntry>
     */
    #[Computed]
    public function auditEntries(): Collection
    {
        return $this->brokerBalance->auditEntries()->with('user')->latest('id')->get();
    }

    #[Computed]
    public function displayTimezone(): string
    {
        return Setting::current()->display_timezone;
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">
                Broker balance · {{ $brokerBalance->as_of_date->format('M j, Y') }}
                @if ($brokerBalance->isVoided())
                    <x-badge tone="gray" class="ms-2 align-middle">Voided</x-badge>
                @endif
            </h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $brokerBalance->account->displayName() }}</p>
        </div>
        <a href="{{ route('cash.reconciliation') }}" class="btn-secondary" wire:navigate>Back to reconciliation</a>
    </div>

    @if (session('message'))
        <x-alert level="success">{{ session('message') }}</x-alert>
    @endif

    @if ($brokerBalance->isVoided())
        <x-alert level="info" title="Voided">This balance was voided and is no longer compared. Its history is shown below.</x-alert>
    @else
        <form wire:submit="save" class="card flex flex-col gap-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="as_of_date" class="form-label required">Date (end of day)</label>
                    <input id="as_of_date" type="date" wire:model="form.as_of_date" class="form-input">
                    @error('form.as_of_date') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="total_cash" class="form-label required">Cash balance</label>
                    <input id="total_cash" type="text" inputmode="decimal" wire:model="form.total_cash" class="form-input">
                    @error('form.total_cash') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="settled_cash" class="form-label">Settled cash (optional)</label>
                    <input id="settled_cash" type="text" inputmode="decimal" wire:model="form.settled_cash" class="form-input">
                    @error('form.settled_cash') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-3">
                    <label for="reference_note" class="form-label">Reference note (optional)</label>
                    <input id="reference_note" type="text" wire:model="form.reference_note" class="form-input">
                    @error('form.reference_note') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="reason" class="form-label">Reason for this change</label>
                <textarea id="reason" rows="2" wire:model="form.reason" class="form-input"></textarea>
                @error('form.reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save changes</button>
                <button type="button" wire:click="void" wire:confirm="Void this broker balance?" class="btn-danger">Void balance</button>
            </div>
        </form>
    @endif

    @include('partials.audit-history', ['entries' => $this->auditEntries, 'timezone' => $this->displayTimezone])
</div>
