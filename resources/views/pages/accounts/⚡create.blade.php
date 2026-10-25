<?php

use App\Livewire\Forms\AccountForm;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add account')] class extends Component
{
    public AccountForm $form;

    public function mount(): void
    {
        $this->form->setAccount();
    }

    public function save(): void
    {
        $account = $this->form->store();

        session()->flash('message', "Account \"{$account->nickname}\" added.");

        $this->redirectRoute('accounts.index', navigate: true);
    }
};
?>

<div class="flex flex-col gap-6">
    <h1 class="text-2xl font-semibold">Add account</h1>

    <form wire:submit="save" class="card flex flex-col gap-6">
        @include('partials.account-form-fields')

        <div class="flex gap-3">
            <button type="submit" class="btn-primary">Add account</button>
            <a href="{{ route('accounts.index') }}" class="btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
