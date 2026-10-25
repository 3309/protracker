<?php

use App\Actions\Options\SaveOptionTrade;
use App\Livewire\Forms\BuyToCloseForm;
use App\Models\AuditEntry;
use App\Models\OptionEvent;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Correct option close')] class extends Component
{
    public OptionEvent $optionEvent;

    public BuyToCloseForm $form;

    public function mount(OptionEvent $optionEvent): void
    {
        $this->optionEvent = $optionEvent;
        $this->form->setOptionEvent($optionEvent);
    }

    public function save(): void
    {
        abort_if($this->optionEvent->isVoided(), 403, 'A voided close cannot be changed.');

        $saved = $this->form->update();

        $this->optionEvent->refresh();

        if (! $saved) {
            return;
        }

        unset($this->auditEntries);

        session()->now('message', 'Close saved. Realized profit has been recalculated.');
    }

    public function void(SaveOptionTrade $saveOptionTrade): void
    {
        abort_if($this->optionEvent->isVoided(), 403, 'This close is already voided.');

        $this->validate(
            ['form.reason' => ['required', 'string', 'max:1000']],
            ['form.reason.required' => 'Please explain why you are voiding this close. It is saved in the audit history.'],
        );

        $saveOptionTrade->voidEvent($this->optionEvent, $this->form->reason);

        $this->form->reset('reason');
        $this->optionEvent->refresh();

        unset($this->auditEntries);

        session()->now('message', 'Close voided. The contracts are open again and realized profit has been recalculated.');
    }

    /**
     * @return Collection<int, AuditEntry>
     */
    #[Computed]
    public function auditEntries(): Collection
    {
        return $this->optionEvent->auditEntries()->with('user')->latest('id')->get();
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
                {{ $optionEvent->event_type->label() }}: {{ $optionEvent->position->description() }}
                @if ($optionEvent->isVoided())
                    <span class="ms-2 rounded bg-zinc-200 px-2 py-0.5 text-sm font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">Voided</span>
                @endif
            </h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                {{ $optionEvent->account->displayName() }} · Net realized P/L {{ $optionEvent->netProfit()->format() }}
            </p>
        </div>
        <a href="{{ route('options.show', $optionEvent->option_position_id) }}" class="btn-secondary" wire:navigate>Back to position</a>
    </div>

    @if (session('message'))
        <div class="status-message">{{ session('message') }}</div>
    @endif

    @if ($optionEvent->isVoided())
        <div class="card text-sm">This close was voided. It no longer counts in any total and cannot be changed. Its history is shown below.</div>
    @else
        <form wire:submit="save" wire:confirm="Save this correction? Realized profit for this position will be recalculated." class="card flex flex-col gap-6">
            @include('partials.buy-to-close-fields', ['feePerContract' => $optionEvent->position->account->option_fee_per_contract, 'prefix' => 'form'])

            <div>
                <label for="reason" class="form-label">Reason for this change</label>
                <textarea id="reason" rows="2" wire:model="form.reason" class="form-input" placeholder="e.g. Corrected the buyback price"></textarea>
                <p class="form-help">Required for any change, including voiding. It is kept permanently in the history below.</p>
                @error('form.reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary">Save changes</button>
                <button type="button" wire:click="void" wire:confirm="Void this close? The contracts become open again and realized profit is recalculated." class="btn-danger">Void close</button>
            </div>
        </form>
    @endif

    @include('partials.audit-history', ['entries' => $this->auditEntries, 'timezone' => $this->displayTimezone])
</div>
