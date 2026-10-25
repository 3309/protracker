<?php

use App\Livewire\Forms\DividendForm;
use App\Models\Account;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add dividend')] class extends Component
{
    public DividendForm $form;

    public function mount(): void
    {
        if ($this->accounts->count() === 1) {
            $this->form->account_id = (string) $this->accounts->first()?->id;
        }
    }

    public function save(): void
    {
        $dividend = $this->form->store();

        session()->flash('message', "{$dividend->ticker} dividend added. Check the expected amount and confirm what you received.");

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
};
?>

<div class="flex flex-col gap-6">
    <div>
        <h1 class="text-2xl font-semibold">Add dividend</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">The app works out the shares you held on the ex-date and the amount you should receive.</p>
    </div>

    <form wire:submit="save" class="card flex flex-col gap-6">
        @include('partials.dividend-fields', ['accounts' => $this->accounts])

        <div class="flex gap-3">
            <button type="submit" class="btn-primary">Add dividend</button>
            <a href="{{ route('dividends.index') }}" class="btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
