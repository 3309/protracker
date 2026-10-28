<?php

use App\Livewire\Concerns\ChoosesOptionPosition;
use App\Livewire\Concerns\ReviewsTradeProposal;
use App\Livewire\Forms\BuyToCloseForm;
use App\Support\Money;
use App\Support\TradeAssistant\ReviewChecks;
use App\Support\TradeAssistant\TradeContext;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Form;

/**
 * Reviews a buy to close (of a sold option) or a sell to close (of a
 * bought one) proposed by the AI Trade Assistant, for some or all of the
 * open contracts, using the normal closing form and its checks.
 */
new class extends Component
{
    use ChoosesOptionPosition, ReviewsTradeProposal;

    public BuyToCloseForm $close;

    public function mount(): void
    {
        $this->fillFromProposal('event_date', 'trade_date');
        $this->fillFromProposal('contracts', 'quantity');
        $this->fillFromProposal('price_per_share', 'price');
        $this->fillFromProposal('fees', 'fees');
        $this->chooseOnlyCandidate();
    }

    public function save(): void
    {
        $this->resetErrorBag('position_id');
        $position = $this->chosenPosition();

        if ($position === null) {
            $this->addError('position_id', 'Choose the option this applies to.');

            return;
        }

        $event = $this->storeOnce(fn () => $this->close->store($position));

        if ($event === null) {
            return;
        }

        session()->flash('message', ($this->isSale() ? 'Saved: sold to close' : 'Saved: bought to close')." {$event->contracts} × {$position->description()}. Net realized P/L: {$event->netProfit()->format()}.");

        $this->redirectRoute('trade-assistant', navigate: true);
    }

    public function isSale(): bool
    {
        return $this->proposed()->action === 'sell_to_close';
    }

    /**
     * @return array<string, Money|null>
     */
    #[Computed]
    public function totals(): array
    {
        $position = $this->chosenPosition();
        $amount = $position !== null ? ReviewChecks::grossAmount($this->close->price_per_share, $this->close->contracts, (string) $position->multiplier) : null;

        return [($this->isSale() ? 'Received from the sale' : 'Paid to buy back').' (before fees)' => $amount];
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function warnings(): array
    {
        $position = $this->chosenPosition();
        $contracts = preg_match('/^\d{1,6}$/', trim($this->close->contracts)) === 1 ? (int) $this->close->contracts : null;

        return array_values(array_filter([
            ReviewChecks::futureDate($this->close->event_date, TradeContext::current()->today, 'closing date'),
            $position !== null && $contracts !== null && $contracts > $position->openContracts()
                ? "Only {$position->openContracts()} of these contracts are open, so {$contracts} cannot be closed."
                : null,
            $position !== null && $contracts !== null && $contracts < $position->openContracts()
                ? 'This closes '.$contracts.' of the '.$position->openContracts().' open contracts; the rest stay open.'
                : null,
            $position !== null && trim($this->close->fees) === '' && $contracts !== null
                ? "No fee was given, so the account's default of {$position->account->option_fee_per_contract->format()} per contract will be used."
                : null,
        ]));
    }

    protected function reviewedForm(): Form
    {
        return $this->close;
    }

    protected function requiredFields(): array
    {
        return [
            'event_date' => 'Closing date',
            'contracts' => 'Contracts closed',
            'price_per_share' => 'Closing price per share',
        ];
    }
};
?>

<form wire:submit="save" x-on:change="$wire.$refresh()" class="card flex flex-col gap-6">
    @include('partials.trade-assistant-findings', [
        'proposal' => $this->proposed(),
        'title' => $this->isSale() ? 'Proposed sale to close' : 'Proposed buy to close',
        'fields' => ['account', 'symbol', 'option_type', 'strike', 'expiration', 'trade_date', 'quantity', 'price', 'fees'],
        'labels' => ['trade_date' => 'Closing date', 'quantity' => 'Contracts closed', 'price' => 'Closing price per share'],
        'totals' => $this->totals,
        'warnings' => $this->warnings,
        'missing' => $this->missing(),
    ])

    @include('partials.trade-assistant-position-picker', ['candidates' => $this->candidates, 'chosen' => $this->chosenPosition()])

    @include('partials.buy-to-close-fields', [
        'prefix' => 'close',
        'feePerContract' => $this->chosenPosition()?->account->option_fee_per_contract ?? App\Support\Money::zero(),
        'isSale' => $this->isSale(),
    ])

    <div class="flex gap-3">
        <button type="submit" class="btn-primary" wire:loading.attr="disabled" @disabled($this->missing() !== [])>Confirm and save</button>
        <button type="button" class="btn-secondary" wire:click="$parent.cancel">Cancel</button>
    </div>
</form>
