<?php

use App\Livewire\Concerns\ReviewsTradeProposal;
use App\Livewire\Forms\DividendForm;
use App\Models\Account;
use App\Support\TradeAssistant\ReviewChecks;
use App\Support\TradeAssistant\TradeContext;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Reviews a dividend proposed by the AI Trade Assistant, using the normal
 * dividend form, which refuses the same account, ticker and ex-date twice.
 */
new class extends Component
{
    use ReviewsTradeProposal;

    public DividendForm $form;

    public function mount(): void
    {
        $this->fillFromProposal('account_id', 'account');
        $this->fillFromProposal('ticker', 'symbol');
        $this->fillFromProposal('ex_date', 'ex_date');
        $this->fillFromProposal('pay_date', 'trade_date');
        $this->fillFromProposal('amount_per_share', 'price');
    }

    public function save(): void
    {
        $dividend = $this->storeOnce(fn () => $this->form->store());

        if ($dividend === null) {
            return;
        }

        session()->flash('message', "Saved: {$dividend->ticker} dividend. Check the expected amount and confirm what you received.");

        $this->redirectRoute('dividends.edit', $dividend, navigate: true);
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()->active()->orderBy('nickname')->get();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function warnings(): array
    {
        return array_values(array_filter([
            $this->form->ex_date !== '' && $this->form->pay_date !== '' && $this->form->pay_date < $this->form->ex_date
                ? 'The pay date is before the ex-dividend date. Check both dates.'
                : null,
            ReviewChecks::futureDate($this->form->ex_date, TradeContext::current()->today, 'ex-dividend date'),
        ]));
    }

    protected function requiredFields(): array
    {
        return [
            'account_id' => 'Account',
            'ticker' => 'Ticker',
            'ex_date' => 'Ex-dividend date',
            'pay_date' => 'Pay date',
            'amount_per_share' => 'Amount per share',
        ];
    }
};
?>

<form wire:submit="save" x-on:change="$wire.$refresh()" class="card flex flex-col gap-6">
    @include('partials.trade-assistant-findings', [
        'proposal' => $this->proposed(),
        'title' => 'Proposed dividend',
        'fields' => ['account', 'symbol', 'ex_date', 'trade_date', 'price'],
        'labels' => ['trade_date' => 'Pay date', 'price' => 'Amount per share'],
        'totals' => [],
        'warnings' => $this->warnings,
        'missing' => $this->missingFields(),
    ])

    @include('partials.dividend-fields', ['accounts' => $this->accounts])

    <p class="form-help">The expected amount is worked out from the shares held on the ex-dividend date. You confirm the amount received on the next page.</p>

    <div class="flex gap-3">
        <button type="submit" class="btn-primary" wire:loading.attr="disabled" @disabled($this->missingFields() !== [])>Confirm and save</button>
        <button type="button" class="btn-secondary" wire:click="$parent.cancel">Cancel</button>
    </div>
</form>
