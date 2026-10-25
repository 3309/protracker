<?php

use App\Actions\Options\SaveOptionTrade;
use App\Enums\PositionOrigin;
use App\Exceptions\OptionPositionException;
use App\Livewire\Forms\SellToOpenForm;
use App\Models\AuditEntry;
use App\Models\OptionPosition;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Correct option trade')] class extends Component
{
    public OptionPosition $optionPosition;

    public SellToOpenForm $form;

    public function mount(OptionPosition $optionPosition): void
    {
        abort_unless(in_array($optionPosition->origin, [PositionOrigin::SellToOpen, PositionOrigin::BuyToOpen], true), 404);

        $this->optionPosition = $optionPosition;
        $this->form->setOptionPosition($optionPosition);
    }

    public function save(): void
    {
        abort_if($this->optionPosition->isVoided(), 403, 'A voided position cannot be changed.');

        $saved = $this->form->update();

        $this->optionPosition->refresh();

        if (! $saved) {
            return;
        }

        unset($this->auditEntries);

        session()->now('message', ($this->optionPosition->isLong() ? 'Option purchase' : 'Option sale').' saved. Realized profit of its closes has been recalculated.');
    }

    public function void(SaveOptionTrade $saveOptionTrade): void
    {
        abort_if($this->optionPosition->isVoided(), 403, 'This position is already voided.');

        $this->validate(
            ['form.reason' => ['required', 'string', 'max:1000']],
            ['form.reason.required' => 'Please explain why you are voiding this '.($this->optionPosition->isLong() ? 'purchase' : 'sale').'. It is saved in the audit history.'],
        );

        try {
            $saveOptionTrade->voidPosition($this->optionPosition, $this->form->reason);
        } catch (OptionPositionException $exception) {
            $this->addError('form.reason', $exception->getMessage());

            return;
        }

        $this->form->reset('reason');
        $this->optionPosition->refresh();

        unset($this->auditEntries);

        session()->now('message', ($this->optionPosition->isLong() ? 'Option purchase' : 'Option sale').' voided. It is kept in the history but no longer counts.');
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
            <h1 class="page-title">
                {{ $optionPosition->description() }}
                @if ($optionPosition->isVoided())
                    <x-badge tone="gray" class="ms-2 align-middle">Voided</x-badge>
                @endif
            </h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $optionPosition->account->displayName() }} · {{ $optionPosition->origin->label() }}</p>
        </div>
        <a href="{{ route('options.show', $optionPosition) }}" class="btn-secondary" wire:navigate>Back to position</a>
    </div>

    @if (session('message'))
        <x-alert level="success">{{ session('message') }}</x-alert>
    @endif

    @if ($optionPosition->isVoided())
        <x-alert level="info" title="Voided">This {{ $optionPosition->isLong() ? 'purchase' : 'sale' }} was voided. It no longer counts in any total and cannot be changed. Its history is shown below.</x-alert>
    @else
        <form wire:submit="save" wire:confirm="Save this correction? Realized profit of any closes of this position will be recalculated." class="card flex flex-col gap-6">
            @include('partials.sell-to-open-fields', ['optionPosition' => $optionPosition])

            <div>
                <label for="reason" class="form-label">Reason for this change</label>
                <textarea id="reason" rows="2" wire:model="form.reason" class="form-input" placeholder="e.g. Corrected the premium from the trade confirmation"></textarea>
                <p class="form-help">Required for any change, including voiding. It is kept permanently in the history below.</p>
                @error('form.reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save changes</button>
                <button type="button" wire:click="void" wire:confirm="Void this {{ $optionPosition->isLong() ? 'purchase' : 'sale' }}? It stays in the history but no longer counts." class="btn-danger">Void {{ $optionPosition->isLong() ? 'purchase' : 'sale' }}</button>
            </div>
        </form>
    @endif

    @include('partials.audit-history', ['entries' => $this->auditEntries, 'timezone' => $this->displayTimezone])
</div>
