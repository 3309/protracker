<?php

use App\Livewire\Concerns\ReviewsTradeProposal;
use App\Livewire\Forms\BuyToOpenForm;
use App\Models\Account;
use App\Support\Money;
use App\Support\TradeAssistant\ReviewChecks;
use App\Support\TradeAssistant\TradeContext;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Reviews a put or call bought to open, proposed by the AI Trade
 * Assistant, using the normal buy-to-open form.
 */
new class extends Component
{
    use ReviewsTradeProposal;

    public BuyToOpenForm $form;

    public function mount(): void
    {
        $this->fillFromProposal('account_id', 'account');
        $this->fillFromProposal('underlying', 'symbol');
        $this->fillFromProposal('option_type', 'option_type');
        $this->fillFromProposal('strike', 'strike');
        $this->fillFromProposal('expires_on', 'expiration');
        $this->fillFromProposal('opened_on', 'trade_date');
        $this->fillFromProposal('contracts', 'quantity');
        $this->fillFromProposal('premium_per_share', 'price');
        $this->fillFromProposal('opening_fees', 'fees');
        $this->form->multiplier = $this->proposed()->value('multiplier') !== '' ? $this->proposed()->value('multiplier') : '100';
    }

    public function save(): void
    {
        $position = $this->storeOnce(fn () => $this->form->store());

        if ($position === null) {
            return;
        }

        session()->flash('message', "Saved: bought to open {$position->contracts} × {$position->description()}.");

        $this->redirectRoute('trade-assistant', navigate: true);
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
     * @return array<string, Money|null>
     */
    #[Computed]
    public function totals(): array
    {
        $summary = $this->form->summary();

        return [
            'Premium paid' => $summary['premium'] ?? null,
            'Opening fees' => $summary['fees'] ?? null,
            'Total opening cost' => $summary['total'] ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function warnings(): array
    {
        return ReviewChecks::forOption($this->form, $this->proposed()->status('multiplier'), $this->accounts->firstWhere('id', (int) $this->form->account_id), TradeContext::current()->today);
    }

    protected function requiredFields(): array
    {
        return [
            'account_id' => 'Account',
            'underlying' => 'Ticker',
            'option_type' => 'Put or call',
            'strike' => 'Strike',
            'expires_on' => 'Expiration date',
            'opened_on' => 'Trade date',
            'contracts' => 'Contracts',
            'premium_per_share' => 'Premium per share',
            'multiplier' => 'Shares per contract',
        ];
    }
};
?>

<form wire:submit="save" x-on:change="$wire.$refresh()" class="card flex flex-col gap-6">
    @include('partials.trade-assistant-findings', [
        'proposal' => $this->proposed(),
        'title' => match ($form->option_type) { 'put' => 'Proposed put bought to open', 'call' => 'Proposed call bought to open', default => 'Proposed option bought to open' },
        'fields' => ['account', 'symbol', 'option_type', 'strike', 'expiration', 'trade_date', 'quantity', 'price', 'multiplier', 'fees'],
        'totals' => $this->totals,
        'warnings' => $this->warnings,
        'missing' => $this->missingFields(),
    ])

    @include('partials.sell-to-open-fields', ['accounts' => $this->accounts, 'isPurchase' => true])

    <div class="flex gap-3">
        <button type="submit" class="btn-primary" wire:loading.attr="disabled" @disabled($this->missingFields() !== [])>Confirm and save</button>
        <button type="button" class="btn-secondary" wire:click="$parent.cancel">Cancel</button>
    </div>
</form>
