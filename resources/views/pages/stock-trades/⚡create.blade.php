<?php

use App\Livewire\Forms\StockTradeForm;
use App\Models\Account;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add stock trade')] class extends Component
{
    public StockTradeForm $form;

    public function mount(): void
    {
        if ($this->accounts->count() === 1) {
            $this->form->account_id = (string) $this->accounts->first()?->id;
        }
    }

    public function save(): void
    {
        $trade = $this->form->store();

        if ($trade === null) {
            return;
        }

        session()->flash('message', "{$trade->side->label()} of {$trade->shares->format()} {$trade->ticker} shares recorded.");

        $this->redirectRoute('stock-trades.index', navigate: true);
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()->active()->orderBy('nickname')->get();
    }
};
?>

<div class="flex flex-col gap-6">
    <h1 class="page-title">Add stock trade</h1>

    <form wire:submit="save" class="card flex flex-col gap-6">
        @include('partials.stock-trade-fields', ['accounts' => $this->accounts])

        <p class="form-help">
            A sale uses the account's oldest shares of that ticker first. Shares already held on the reporting start date
            are entered under Opening balances.
        </p>

        <div class="flex gap-3">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Record trade</button>
            <a href="{{ route('stock-trades.index') }}" class="btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
