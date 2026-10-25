<?php

use App\Livewire\Forms\SellToOpenForm;
use App\Models\Account;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sell to open')] class extends Component
{
    public SellToOpenForm $form;

    public function mount(): void
    {
        if ($this->accounts->count() === 1) {
            $this->form->account_id = (string) $this->accounts->first()?->id;
        }
    }

    public function save(): void
    {
        $position = $this->form->store();

        session()->flash('message', "Sold to open: {$position->contracts} × {$position->description()}.");

        $this->redirectRoute('options.show', $position, navigate: true);
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
        <h1 class="text-2xl font-semibold">Sell to open</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Record a put or call you sold. The premium counts as profit only when the contracts close, expire or are assigned.
        </p>
    </div>

    <form wire:submit="save" class="card flex flex-col gap-6">
        @include('partials.sell-to-open-fields', ['accounts' => $this->accounts])

        <div class="flex gap-3">
            <button type="submit" class="btn-primary">Record sale</button>
            <a href="{{ route('options.index') }}" class="btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
