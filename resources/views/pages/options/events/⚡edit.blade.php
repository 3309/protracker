<?php

use App\Actions\Options\SaveOptionTrade;
use App\Exceptions\InsufficientSharesException;
use App\Exceptions\OptionPositionException;
use App\Livewire\Forms\BuyToCloseForm;
use App\Livewire\Forms\OptionLifecycleForm;
use App\Models\AuditEntry;
use App\Models\OptionEvent;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Correct option event')] class extends Component
{
    public OptionEvent $optionEvent;

    /** Used for buy-backs, including the buy-back of a roll. */
    public BuyToCloseForm $form;

    /** Used for expiries and assignments. */
    public OptionLifecycleForm $lifecycle;

    public function mount(OptionEvent $optionEvent): void
    {
        $this->optionEvent = $optionEvent;

        if ($optionEvent->event_type->isBuyBack()) {
            $this->form->setOptionEvent($optionEvent);
        } else {
            $this->lifecycle->setOptionEvent($optionEvent);
        }
    }

    public function save(): void
    {
        abort_if($this->optionEvent->isVoided(), 403, 'A voided event cannot be changed.');

        $saved = $this->optionEvent->event_type->isBuyBack() ? $this->form->update() : $this->lifecycle->update();

        $this->optionEvent->refresh();

        if (! $saved) {
            return;
        }

        unset($this->auditEntries);

        session()->now('message', 'Event saved. Realized profit has been recalculated.');
    }

    public function void(SaveOptionTrade $saveOptionTrade): void
    {
        abort_if($this->optionEvent->isVoided(), 403, 'This event is already voided.');

        $reasonField = $this->reasonField();

        $this->validate(
            [$reasonField => ['required', 'string', 'max:1000']],
            [$reasonField.'.required' => 'Please explain why you are voiding this event. It is saved in the audit history.'],
        );

        try {
            $saveOptionTrade->voidEvent($this->optionEvent, (string) data_get($this, $reasonField));
        } catch (OptionPositionException|InsufficientSharesException $exception) {
            $this->optionEvent->refresh();
            $this->addError($reasonField, $exception instanceof InsufficientSharesException
                ? 'This assignment cannot be voided because later stock sales use the shares it bought. '.$exception->getMessage()
                : $exception->getMessage());

            return;
        }

        $this->form->reset('reason');
        $this->lifecycle->reset('reason');
        $this->optionEvent->refresh();

        unset($this->auditEntries);

        session()->now('message', 'Event voided. The contracts are open again and realized profit has been recalculated.');
    }

    public function reasonField(): string
    {
        return $this->optionEvent->event_type->isBuyBack() ? 'form.reason' : 'lifecycle.reason';
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
                {{ $optionEvent->account->displayName() }} · Net realized option P/L {{ $optionEvent->netProfit()->format() }}
            </p>
            @if ($optionEvent->stockTrade)
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Linked stock trade:
                    <a href="{{ route('stock-trades.edit', $optionEvent->stockTrade) }}" class="underline underline-offset-4" wire:navigate>
                        {{ $optionEvent->stockTrade->side->label() }} {{ $optionEvent->stockTrade->shares->format() }} {{ $optionEvent->stockTrade->ticker }}
                        at {{ $optionEvent->stockTrade->price_per_share->format() }}
                    </a>
                </p>
            @endif
            @if ($optionEvent->rolledInto)
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Rolled into
                    <a href="{{ route('options.show', $optionEvent->rolledInto) }}" class="underline underline-offset-4" wire:navigate>{{ $optionEvent->rolledInto->description() }}</a>.
                    To undo the roll, void that option.
                </p>
            @endif
        </div>
        <a href="{{ route('options.show', $optionEvent->option_position_id) }}" class="btn-secondary" wire:navigate>Back to position</a>
    </div>

    @if (session('message'))
        <div class="status-message">{{ session('message') }}</div>
    @endif

    @if ($optionEvent->isVoided())
        <div class="card text-sm">This event was voided. It no longer counts in any total and cannot be changed. Its history is shown below.</div>
    @else
        <form wire:submit="save" wire:confirm="Save this correction? Realized profit for this position will be recalculated." class="card flex flex-col gap-6">
            @if ($optionEvent->event_type->isBuyBack())
                @include('partials.buy-to-close-fields', ['feePerContract' => $optionEvent->position->account->option_fee_per_contract, 'prefix' => 'form'])
            @else
                @include('partials.option-lifecycle-fields', [
                    'optionPosition' => $optionEvent->position,
                    'prefix' => 'lifecycle',
                    'isAssignment' => $optionEvent->event_type === App\Enums\OptionEventType::Assigned,
                ])
            @endif

            <div>
                <label for="reason" class="form-label">Reason for this change</label>
                <textarea id="reason" rows="2" wire:model="{{ $this->reasonField() }}" class="form-input" placeholder="e.g. Date from the broker statement"></textarea>
                <p class="form-help">Required for any change, including voiding. It is kept permanently in the history below.</p>
                @error($this->reasonField()) <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary">Save changes</button>
                @unless ($optionEvent->rolledInto && ! $optionEvent->rolledInto->isVoided())
                    <button type="button" wire:click="void" wire:confirm="Void this event? The contracts become open again and realized profit is recalculated." class="btn-danger">Void event</button>
                @endunless
            </div>
        </form>
    @endif

    @include('partials.audit-history', ['entries' => $this->auditEntries, 'timezone' => $this->displayTimezone])
</div>
