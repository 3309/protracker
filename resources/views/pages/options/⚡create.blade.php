<?php

use App\Livewire\Forms\SellToOpenForm;
use App\Models\Account;
use App\Support\CoveredCallCapacity;
use Carbon\CarbonImmutable;
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

        if ($position === null) {
            return;
        }

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

    /**
     * @return array{date: CarbonImmutable, multiplier: int, choices: list<CoveredCallCapacity>, selected: CoveredCallCapacity|null}|null
     */
    #[Computed]
    public function coveredCall(): ?array
    {
        return $this->form->coveredCallAvailability();
    }
};
?>

<div class="flex flex-col gap-6">
    <div>
        <h1 class="page-title">Sell to open</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Record a put or call you sold. The premium counts as profit only when the contracts close, expire or are assigned.
        </p>
    </div>

    <form wire:submit="save" class="card flex flex-col gap-6">
        @include('partials.sell-to-open-fields', ['accounts' => $this->accounts, 'coveredCall' => $this->coveredCall, 'coveredCallCheck' => true])

        <div class="flex gap-3">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" @disabled($this->coveredCall !== null && $this->coveredCall['choices'] === [])>Record sale</button>
            <a href="{{ route('options.index') }}" class="btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
