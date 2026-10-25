<?php

use App\Enums\OptionType;
use App\Livewire\Forms\BuyToOpenForm;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Buy to open')] class extends Component
{
    public BuyToOpenForm $form;

    /**
     * @param  string|null  $type  "call" or "put" from the Buy call / Buy put choice
     */
    public function mount(?string $type = null): void
    {
        $this->form->option_type = OptionType::tryFrom((string) ($type ?? request()->query('type')))?->value ?? '';

        if ($this->accounts->count() === 1) {
            $this->form->account_id = (string) $this->accounts->first()?->id;
        }
    }

    public function save(): void
    {
        $position = $this->form->store();

        session()->flash('message', "Bought to open: {$position->contracts} × {$position->description()}.");

        $this->redirectRoute('options.show', $position, navigate: true);
    }

    /**
     * @return array{premium: Money, fees: Money|null, total: Money|null}|null
     */
    #[Computed]
    public function summary(): ?array
    {
        return $this->form->summary();
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
        <h1 class="page-title">
            {{ match ($form->option_type) { 'call' => 'Buy call', 'put' => 'Buy put', default => 'Buy to open' } }}
        </h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Record a call or put you bought. Nothing is counted as profit or loss until you sell it to close or it expires.
        </p>
    </div>

    <form wire:submit="save" class="card flex flex-col gap-6">
        @include('partials.sell-to-open-fields', ['accounts' => $this->accounts, 'isPurchase' => true])

        <div class="rounded-md border border-zinc-200 bg-zinc-50 p-4 text-sm dark:border-zinc-700 dark:bg-zinc-800/50" aria-live="polite">
            <h2 class="font-medium">Cost summary</h2>
            @if ($this->summary === null)
                <p class="mt-1 text-zinc-500 dark:text-zinc-400">Enter the contracts, premium per share and shares per contract to see the cost.</p>
            @else
                <dl class="mt-2 grid gap-1 sm:grid-cols-3">
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Premium paid</dt>
                        <dd class="tabular-nums">{{ $this->summary['premium']->format() }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Opening fees</dt>
                        <dd class="tabular-nums">{{ $this->summary['fees']?->format() ?? 'Choose an account for the default fee' }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">Total opening cost</dt>
                        <dd class="font-medium tabular-nums">{{ $this->summary['total']?->format() ?? '—' }}</dd>
                    </div>
                </dl>
                <p class="form-help">Premium paid = contracts × premium per share × shares per contract.</p>
            @endif
        </div>

        <div class="flex gap-3">
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Record purchase</button>
            <a href="{{ route('options.index') }}" class="btn-secondary" wire:navigate>Cancel</a>
        </div>
    </form>
</div>
