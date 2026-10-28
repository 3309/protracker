<?php

use App\Enums\TradeSide;
use App\Livewire\Concerns\ReviewsTradeProposal;
use App\Livewire\Forms\StockTradeForm;
use App\Models\Account;
use App\Support\Money;
use App\Support\TradeAssistant\ReviewChecks;
use App\Support\TradeAssistant\TradeContext;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Reviews a stock buy or sell proposed by the AI Trade Assistant, using
 * the normal stock trade form and its checks.
 */
new class extends Component
{
    use ReviewsTradeProposal;

    public StockTradeForm $form;

    public function mount(): void
    {
        $this->form->side = $this->proposed()->action === 'sell_stock' ? TradeSide::Sell->value : TradeSide::Buy->value;
        $this->fillFromProposal('account_id', 'account');
        $this->fillFromProposal('ticker', 'symbol');
        $this->fillFromProposal('trade_date', 'trade_date');
        $this->fillFromProposal('shares', 'quantity');
        $this->fillFromProposal('price_per_share', 'price');
        $this->fillFromProposal('fees', 'fees');
    }

    public function save(): void
    {
        $trade = $this->storeOnce(fn () => $this->form->store());

        if ($trade === null) {
            return;
        }

        session()->flash('message', "Saved: {$trade->side->label()} of {$trade->shares->format()} {$trade->ticker} shares on {$trade->trade_date->format('M j, Y')}.");

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
        return ['Price × shares (before fees)' => ReviewChecks::grossAmount($this->form->price_per_share, $this->form->shares)];
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function warnings(): array
    {
        $account = $this->accounts->firstWhere('id', (int) $this->form->account_id);

        return array_values(array_filter([
            ReviewChecks::futureDate($this->form->trade_date, TradeContext::current()->today, 'trade date'),
            $account !== null && trim($this->form->fees) === ''
                ? "No fee was given, so the account's default stock fee of {$account->stock_fee_per_trade->format()} will be used."
                : null,
        ]));
    }

    protected function requiredFields(): array
    {
        return [
            'account_id' => 'Account',
            'side' => 'Buy or sell',
            'ticker' => 'Ticker',
            'trade_date' => 'Trade date',
            'shares' => 'Shares',
            'price_per_share' => 'Price per share',
        ];
    }
};
?>

<form wire:submit="save" x-on:change="$wire.$refresh()" class="card flex flex-col gap-6">
    @include('partials.trade-assistant-findings', [
        'proposal' => $this->proposed(),
        'title' => $form->side === 'sell' ? 'Proposed stock sale' : 'Proposed stock purchase',
        'fields' => ['account', 'symbol', 'trade_date', 'quantity', 'price', 'fees'],
        'totals' => $this->totals,
        'warnings' => $this->warnings,
        'missing' => $this->missingFields(),
    ])

    @include('partials.stock-trade-fields', ['accounts' => $this->accounts])

    @if ($form->side === 'sell')
        <p class="form-help">A sale uses the account's oldest shares of that ticker first, and cannot sell more shares than the account held on the trade date.</p>
    @endif

    <div class="flex gap-3">
        <button type="submit" class="btn-primary" wire:loading.attr="disabled" @disabled($this->missingFields() !== [])>Confirm and save</button>
        <button type="button" class="btn-secondary" wire:click="$parent.cancel">Cancel</button>
    </div>
</form>
