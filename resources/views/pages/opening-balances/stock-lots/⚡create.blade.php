<?php

use App\Livewire\Forms\StockLotForm;
use App\Models\Account;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add opening stock lot')] class extends Component
{
    public Account $account;

    public StockLotForm $form;

    public function mount(Account $account): void
    {
        $this->account = $account;
    }

    public function save(): void
    {
        $lot = $this->form->store($this->account);

        session()->flash('message', "Stock lot of {$lot->shares->format()} {$lot->ticker} shares added.");

        $this->redirectRoute('opening-balances.show', $this->account, navigate: true);
    }
};
?>

<div class="flex flex-col gap-6">
    <div>
        <h1 class="page-title">Add opening stock lot</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $account->displayName() }} · Opening balance</p>
    </div>

    <form wire:submit="save" class="card flex flex-col gap-6">
        @include('partials.stock-lot-fields')

        <div class="flex gap-3">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Add stock lot</button>
            <a href="{{ route('opening-balances.show', $account) }}" class="btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
