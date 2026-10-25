<?php

use App\Livewire\Forms\CashMovementForm;
use App\Models\Account;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add cash movement')] class extends Component
{
    public CashMovementForm $form;

    public function mount(): void
    {
        if ($this->accounts->count() === 1) {
            $this->form->account_id = (string) $this->accounts->first()?->id;
        }
    }

    public function save(): void
    {
        $movement = $this->form->store();

        session()->flash('message', "{$movement->movement_type->label()} of {$movement->amount->format()} recorded.");

        $this->redirectRoute('cash.index', navigate: true);
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
    <h1 class="text-2xl font-semibold">Add deposit, withdrawal or other cash movement</h1>

    <form wire:submit="save" class="card flex flex-col gap-6">
        @include('partials.cash-movement-fields', ['accounts' => $this->accounts])

        <div class="flex gap-3">
            <button type="submit" class="btn-primary">Record</button>
            <a href="{{ route('cash.index') }}" class="btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
