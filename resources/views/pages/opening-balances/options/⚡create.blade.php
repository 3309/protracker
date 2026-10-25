<?php

use App\Livewire\Forms\OptionPositionForm;
use App\Models\Account;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add opening option')] class extends Component
{
    public Account $account;

    public OptionPositionForm $form;

    public function mount(Account $account): void
    {
        $this->account = $account;
    }

    public function save(): void
    {
        $option = $this->form->store($this->account);

        session()->flash('message', "Option added: {$option->description()}.");

        $this->redirectRoute('opening-balances.show', $this->account, navigate: true);
    }
};
?>

<div class="flex flex-col gap-6">
    <div>
        <h1 class="text-2xl font-semibold">Add opening option</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $account->displayName() }} · Opening balance · Short options only</p>
    </div>

    <form wire:submit="save" class="card flex flex-col gap-6">
        @include('partials.option-position-fields', ['feePerContract' => $account->option_fee_per_contract])

        <div class="flex gap-3">
            <button type="submit" class="btn-primary">Add option</button>
            <a href="{{ route('opening-balances.show', $account) }}" class="btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
