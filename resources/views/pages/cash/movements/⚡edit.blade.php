<?php

use App\Livewire\Forms\CashMovementForm;
use App\Models\AuditEntry;
use App\Models\CashMovement;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit cash movement')] class extends Component
{
    public CashMovement $cashMovement;

    public CashMovementForm $form;

    public function mount(CashMovement $cashMovement): void
    {
        $this->cashMovement = $cashMovement;
        $this->form->setCashMovement($cashMovement);
    }

    public function save(): void
    {
        abort_if($this->cashMovement->isVoided(), 403, 'A voided movement cannot be changed.');

        $this->form->update();

        $this->cashMovement->refresh();

        unset($this->auditEntries);

        session()->now('message', 'Cash movement saved.');
    }

    public function void(): void
    {
        abort_if($this->cashMovement->isVoided(), 403, 'This movement is already voided.');

        $this->validate(
            ['form.reason' => ['required', 'string', 'max:1000']],
            ['form.reason.required' => 'Please explain why you are voiding this movement. It is saved in the audit history.'],
        );

        $this->cashMovement->void($this->form->reason);

        $this->form->reset('reason');

        unset($this->auditEntries);

        session()->now('message', 'Cash movement voided. It is kept in the history but no longer counts.');
    }

    /**
     * @return Collection<int, AuditEntry>
     */
    #[Computed]
    public function auditEntries(): Collection
    {
        return $this->cashMovement->auditEntries()->with('user')->latest('id')->get();
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
        <h1 class="page-title">
            {{ $cashMovement->movement_type->label() }} of {{ $cashMovement->amount->format() }}
            @if ($cashMovement->isVoided())
                <x-badge tone="gray" class="ms-2 align-middle">Voided</x-badge>
            @endif
        </h1>
        <a href="{{ route('cash.index') }}" class="btn-secondary" wire:navigate>Back to cash</a>
    </div>

    @if (session('message'))
        <x-alert level="success">{{ session('message') }}</x-alert>
    @endif

    @if ($cashMovement->isVoided())
        <x-alert level="info" title="Voided">This movement was voided. It no longer counts in any total and cannot be changed. Its history is shown below.</x-alert>
    @else
        <form wire:submit="save" class="card flex flex-col gap-6">
            @include('partials.cash-movement-fields', ['cashMovement' => $cashMovement])

            <div>
                <label for="reason" class="form-label">Reason for this change</label>
                <textarea id="reason" rows="2" wire:model="form.reason" class="form-input"></textarea>
                <p class="form-help">Required for any change, including voiding. It is kept permanently in the history below.</p>
                @error('form.reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save changes</button>
                <button type="button" wire:click="void" wire:confirm="Void this movement? It stays in the history but no longer counts." class="btn-danger">Void movement</button>
            </div>
        </form>
    @endif

    @include('partials.audit-history', ['entries' => $this->auditEntries, 'timezone' => $this->displayTimezone])
</div>
