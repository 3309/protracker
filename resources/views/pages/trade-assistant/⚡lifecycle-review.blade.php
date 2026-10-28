<?php

use App\Enums\OptionEventType;
use App\Enums\OptionType;
use App\Livewire\Concerns\ChoosesOptionPosition;
use App\Livewire\Concerns\ReviewsTradeProposal;
use App\Livewire\Forms\OptionLifecycleForm;
use App\Support\TradeAssistant\ReviewChecks;
use App\Support\TradeAssistant\TradeContext;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Form;

/**
 * Reviews an expiry or an assignment proposed by the AI Trade Assistant,
 * using the normal expiry and assignment form. Only open positions can be
 * chosen, and saving goes through the same checks, so an option that was
 * already closed, expired or assigned cannot be processed again.
 */
new class extends Component
{
    use ChoosesOptionPosition, ReviewsTradeProposal;

    public OptionLifecycleForm $lifecycle;

    /**
     * Whether the contracts were filled in as all open contracts, because the description did not say.
     */
    #[Locked]
    public bool $contractsFilledIn = false;

    /**
     * Whether the date was filled in as the expiration date, because the description did not give one.
     */
    #[Locked]
    public bool $dateFilledIn = false;

    public function mount(): void
    {
        $this->lifecycle->event_type = $this->isAssignment() ? OptionEventType::Assigned->value : OptionEventType::Expired->value;
        $this->fillFromProposal('event_date', 'trade_date');
        $this->fillFromProposal('contracts', 'quantity');
        $this->fillFromProposal('fees', 'fees');
        $this->chooseOnlyCandidate();
        $this->fillInFromPosition();
    }

    public function updatedPositionId(): void
    {
        $this->fillInFromPosition();
    }

    public function save(): void
    {
        $this->resetErrorBag('position_id');
        $position = $this->chosenPosition();

        if ($position === null) {
            $this->addError('position_id', 'Choose the option this applies to.');

            return;
        }

        $event = $this->storeOnce(fn () => $this->lifecycle->store($position));

        if ($event === null) {
            return;
        }

        $message = $event->event_type === OptionEventType::Assigned
            ? "Saved: {$event->contracts} × {$position->description()} assigned. The stock ".($position->option_type === OptionType::Put ? 'purchase' : 'sale').' at the strike has been recorded.'
            : "Saved: {$event->contracts} × {$position->description()} expired worthless.";

        session()->flash('message', "{$message} Net realized option P/L: {$event->netProfit()->format()}.");

        $this->redirectRoute('trade-assistant', navigate: true);
    }

    public function isAssignment(): bool
    {
        return $this->proposed()->action === 'assign';
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function warnings(): array
    {
        $position = $this->chosenPosition();

        return array_values(array_filter([
            ReviewChecks::futureDate($this->lifecycle->event_date, TradeContext::current()->today, $this->isAssignment() ? 'assignment date' : 'expiry date'),
            $this->contractsFilledIn && $position !== null && trim($this->lifecycle->contracts) === (string) $position->openContracts()
                ? "The description did not say how many contracts, so all {$position->openContracts()} open contracts are filled in. Change it if only some ".($this->isAssignment() ? 'were assigned.' : 'expired.')
                : null,
            $this->dateFilledIn && $position !== null && $this->lifecycle->event_date === $position->expires_on->format('Y-m-d')
                ? "The description did not give a date, so the option's expiration date, {$position->expires_on->format('M j, Y')}, is filled in. Use the date your broker shows."
                : null,
            ! $this->isAssignment() && $position !== null && $this->lifecycle->event_date !== '' && $this->lifecycle->event_date < $position->expires_on->format('Y-m-d')
                ? "This option expires on {$position->expires_on->format('M j, Y')}, so it cannot be recorded as expired before then."
                : null,
        ]));
    }

    protected function reviewedForm(): Form
    {
        return $this->lifecycle;
    }

    protected function requiredFields(): array
    {
        return [
            'event_date' => $this->isAssignment() ? 'Assignment date' : 'Expiry date',
            'contracts' => 'Contracts',
        ];
    }

    /**
     * Fill blank contracts and date the way the option's own page does, and
     * remember it so the review says so.
     */
    private function fillInFromPosition(): void
    {
        $position = $this->chosenPosition();

        if ($position === null) {
            return;
        }

        if (trim($this->lifecycle->contracts) === '' || $this->contractsFilledIn) {
            $this->lifecycle->contracts = (string) $position->openContracts();
            $this->contractsFilledIn = true;
        }

        if ($this->lifecycle->event_date === '' || $this->dateFilledIn) {
            $this->lifecycle->event_date = $position->expires_on->format('Y-m-d');
            $this->dateFilledIn = true;
        }
    }
};
?>

<form wire:submit="save" x-on:change="$wire.$refresh()" class="card flex flex-col gap-6">
    @include('partials.trade-assistant-findings', [
        'proposal' => $this->proposed(),
        'title' => $this->isAssignment() ? 'Proposed assignment' : 'Proposed expiry',
        'fields' => $this->isAssignment()
            ? ['account', 'symbol', 'option_type', 'strike', 'expiration', 'trade_date', 'quantity', 'fees']
            : ['account', 'symbol', 'option_type', 'strike', 'expiration', 'trade_date', 'quantity'],
        'labels' => ['trade_date' => $this->isAssignment() ? 'Assignment date' : 'Expiry date', 'quantity' => 'Contracts', 'fees' => 'Assignment fee'],
        'totals' => [],
        'warnings' => $this->warnings,
        'missing' => $this->missing(),
    ])

    @include('partials.trade-assistant-position-picker', ['candidates' => $this->candidates, 'chosen' => $this->chosenPosition()])

    @if ($this->chosenPosition())
        @include('partials.option-lifecycle-fields', ['optionPosition' => $this->chosenPosition(), 'prefix' => 'lifecycle', 'isAssignment' => $this->isAssignment()])
    @endif

    <div class="flex gap-3">
        <button type="submit" class="btn-primary" wire:loading.attr="disabled" @disabled($this->missing() !== [])>Confirm and save</button>
        <button type="button" class="btn-secondary" wire:click="$parent.cancel">Cancel</button>
    </div>
</form>
