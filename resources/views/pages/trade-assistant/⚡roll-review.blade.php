<?php

use App\Livewire\Concerns\ChoosesOptionPosition;
use App\Livewire\Concerns\ReviewsTradeProposal;
use App\Livewire\Forms\RollForm;
use App\Support\Money;
use App\Support\TradeAssistant\ReviewChecks;
use App\Support\TradeAssistant\TradeContext;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Form;

/**
 * Reviews a roll proposed by the AI Trade Assistant: buying back a sold
 * option and selling a new one on the same day. Both legs are shown
 * separately, and the normal roll form saves them together or not at all.
 */
new class extends Component
{
    use ChoosesOptionPosition, ReviewsTradeProposal;

    public RollForm $roll;

    public function mount(): void
    {
        $this->fillFromProposal('event_date', 'trade_date');
        $this->fillFromProposal('contracts', 'quantity');
        $this->fillFromProposal('price_per_share', 'price');
        $this->fillFromProposal('closing_fees', 'fees');
        $this->fillFromProposal('new_strike', 'new_strike');
        $this->fillFromProposal('new_expires_on', 'new_expiration');
        $this->fillFromProposal('new_contracts', 'new_quantity');
        $this->fillFromProposal('new_premium_per_share', 'new_price');
        $this->chooseOnlyCandidate();
    }

    public function save(): void
    {
        $this->resetErrorBag('position_id');
        $position = $this->chosenPosition();

        if ($position === null) {
            $this->addError('position_id', 'Choose the option being rolled.');

            return;
        }

        $newLeg = $this->storeOnce(fn () => $this->roll->store($position));

        if ($newLeg === null) {
            return;
        }

        session()->flash('message', "Saved: rolled {$position->description()} into {$newLeg->contracts} × {$newLeg->description()}. Roll net credit: {$newLeg->rollNetCredit()?->format()}.");

        $this->redirectRoute('trade-assistant', navigate: true);
    }

    /**
     * Both legs and the net before fees: positive is a credit, negative a debit.
     *
     * @return array<string, Money|null>
     */
    #[Computed]
    public function totals(): array
    {
        $multiplier = (string) ($this->chosenPosition()?->multiplier ?? '');
        $paid = $multiplier !== '' ? ReviewChecks::grossAmount($this->roll->price_per_share, $this->roll->contracts, $multiplier) : null;
        $received = $multiplier !== '' ? ReviewChecks::grossAmount($this->roll->new_premium_per_share, $this->roll->new_contracts, $multiplier) : null;

        return [
            'Leg 1: paid to buy back' => $paid,
            'Leg 2: received for the new option' => $received,
            'Net credit before fees (negative is a debit)' => $paid !== null && $received !== null ? $received->minus($paid) : null,
        ];
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function warnings(): array
    {
        $position = $this->chosenPosition();
        $contracts = preg_match('/^\d{1,6}$/', trim($this->roll->contracts)) === 1 ? (int) $this->roll->contracts : null;

        return array_values(array_filter([
            ReviewChecks::futureDate($this->roll->event_date, TradeContext::current()->today, 'roll date'),
            ReviewChecks::expirationNotTradingDay($this->roll->new_expires_on),
            $position !== null && $contracts !== null && $contracts > $position->openContracts()
                ? "Only {$position->openContracts()} of these contracts are open, so {$contracts} cannot be bought back."
                : null,
            $position !== null && (trim($this->roll->closing_fees) === '' || trim($this->roll->new_opening_fees) === '')
                ? "Blank fees use the account's default of {$position->account->option_fee_per_contract->format()} per contract on each leg."
                : null,
        ]));
    }

    protected function reviewedForm(): Form
    {
        return $this->roll;
    }

    protected function requiredFields(): array
    {
        return [
            'event_date' => 'Roll date',
            'contracts' => 'Contracts bought back',
            'price_per_share' => 'Buy-back price per share',
            'new_strike' => 'New strike',
            'new_expires_on' => 'New expiration date',
            'new_contracts' => 'New contracts',
            'new_premium_per_share' => 'New premium per share',
        ];
    }
};
?>

<form wire:submit="save" x-on:change="$wire.$refresh()" class="card flex flex-col gap-6">
    @include('partials.trade-assistant-findings', [
        'proposal' => $this->proposed(),
        'title' => 'Proposed roll',
        'fields' => ['account', 'symbol', 'option_type', 'strike', 'expiration', 'trade_date', 'quantity', 'price', 'fees', 'new_strike', 'new_expiration', 'new_quantity', 'new_price'],
        'labels' => [
            'strike' => 'Strike bought back', 'expiration' => 'Expiration bought back', 'trade_date' => 'Roll date',
            'quantity' => 'Contracts bought back', 'price' => 'Buy-back price per share', 'fees' => 'Closing fee',
        ],
        'totals' => $this->totals,
        'warnings' => $this->warnings,
        'missing' => $this->missing(),
    ])

    @include('partials.trade-assistant-position-picker', ['candidates' => $this->candidates, 'chosen' => $this->chosenPosition()])

    @if ($this->chosenPosition())
        @include('partials.roll-fields', ['optionPosition' => $this->chosenPosition()])
    @endif

    <div class="flex gap-3">
        <button type="submit" class="btn-primary" wire:loading.attr="disabled" @disabled($this->missing() !== [])>Confirm and save</button>
        <button type="button" class="btn-secondary" wire:click="$parent.cancel">Cancel</button>
    </div>
</form>
