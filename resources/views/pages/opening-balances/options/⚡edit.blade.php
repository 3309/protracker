<?php

use App\Enums\PositionOrigin;
use App\Livewire\Forms\OptionPositionForm;
use App\Models\AuditEntry;
use App\Models\OptionPosition;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit opening option')] class extends Component
{
    public OptionPosition $optionPosition;

    public OptionPositionForm $form;

    public function mount(OptionPosition $optionPosition): void
    {
        abort_unless($optionPosition->origin === PositionOrigin::OpeningBalance, 404);

        $this->optionPosition = $optionPosition;
        $this->form->setOptionPosition($optionPosition);
    }

    public function save(): void
    {
        abort_if($this->optionPosition->isVoided(), 403, 'A voided option cannot be changed.');

        $this->form->update();

        $this->optionPosition->refresh();

        unset($this->auditEntries);

        session()->now('message', 'Option saved.');
    }

    public function void(): void
    {
        abort_if($this->optionPosition->isVoided(), 403, 'This option is already voided.');

        $this->validate(
            ['form.reason' => ['required', 'string', 'max:1000']],
            ['form.reason.required' => 'Please explain why you are voiding this entry. It is saved in the audit history.'],
        );

        $this->optionPosition->void($this->form->reason);

        $this->form->reset('reason');

        unset($this->auditEntries);

        session()->now('message', 'Option voided. It is kept in the history but no longer counts.');
    }

    /**
     * @return Collection<int, AuditEntry>
     */
    #[Computed]
    public function auditEntries(): Collection
    {
        return $this->optionPosition->auditEntries()->with('user')->latest('id')->get();
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
            <h1 class="text-2xl font-semibold">
                {{ $optionPosition->description() }}
                @if ($optionPosition->isVoided())
                    <span class="ms-2 rounded bg-zinc-200 px-2 py-0.5 text-sm font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">Voided</span>
                @endif
            </h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $optionPosition->account->displayName() }} · Opening balance</p>
        </div>
        <a href="{{ route('opening-balances.show', $optionPosition->account_id) }}" class="btn-secondary" wire:navigate>Back to opening balances</a>
    </div>

    @if (session('message'))
        <div class="status-message">{{ session('message') }}</div>
    @endif

    @if ($optionPosition->isVoided())
        <div class="card text-sm">This option was voided. It no longer counts in any total and cannot be changed. Its history is shown below.</div>
    @else
        <form wire:submit="save" class="card flex flex-col gap-6">
            @include('partials.option-position-fields', ['feePerContract' => $optionPosition->account->option_fee_per_contract])

            <div>
                <label for="reason" class="form-label">Reason for this change</label>
                <textarea id="reason" rows="2" wire:model="form.reason" class="form-input" placeholder="e.g. Entered the actual fee from the statement"></textarea>
                <p class="form-help">Required for any change, including voiding. It is kept permanently in the history below.</p>
                @error('form.reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary">Save changes</button>
                <button type="button" wire:click="void" wire:confirm="Void this option? It stays in the history but will no longer count." class="btn-danger">Void entry</button>
            </div>
        </form>
    @endif

    @include('partials.audit-history', ['entries' => $this->auditEntries, 'timezone' => $this->displayTimezone])
</div>
