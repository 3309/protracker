<?php

use App\Models\OptionPosition;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Roll chain')] class extends Component
{
    public OptionPosition $optionPosition;

    public function mount(OptionPosition $optionPosition): void
    {
        abort_unless($optionPosition->isInRollChain(), 404);

        $this->optionPosition = $optionPosition;
    }

    /**
     * Every leg of the chain that has not been voided, oldest first.
     *
     * @return Collection<int, OptionPosition>
     */
    #[Computed]
    public function legs(): Collection
    {
        return OptionPosition::query()
            ->where('roll_chain_id', $this->optionPosition->roll_chain_id)
            ->notVoided()
            ->with(['account', 'rolledFromEvent.position'])
            ->orderBy('opened_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * The realized net profit of every closed, expired, assigned or rolled
     * contract across all legs. Open contracts are not included.
     */
    #[Computed]
    public function chainRealizedNetProfit(): Money
    {
        return $this->legs->reduce(fn (Money $total, OptionPosition $leg) => $total->plus($leg->realizedNetProfit()), Money::zero());
    }

    #[Computed]
    public function isOpen(): bool
    {
        return $this->legs->contains(fn (OptionPosition $leg) => $leg->isOpen());
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">Roll chain: {{ $optionPosition->underlying }} {{ $optionPosition->option_type->label() }}</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $optionPosition->account->displayName() }} · {{ $this->legs->count() }} legs</p>
        </div>
        <a href="{{ route('options.show', $optionPosition) }}" class="btn-secondary" wire:navigate>Back to position</a>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="card">
            <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $this->isOpen ? 'Chain result to date (realized)' : 'Chain cumulative result' }}</div>
            <div class="text-xl font-semibold tabular-nums">{{ $this->chainRealizedNetProfit->format() }}</div>
            <div class="text-xs text-zinc-500">After all opening, closing and roll fees.</div>
        </div>
        @if ($this->isOpen)
            <div class="card text-sm text-zinc-600 dark:text-zinc-400">
                The chain still has open contracts. Their premium is not counted until they close, expire or are assigned,
                so this is not a forecast of the final result.
            </div>
        @endif
    </div>

    <div class="card overflow-x-auto p-0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Leg</th>
                    <th>Opened</th>
                    <th class="text-right">Contracts</th>
                    <th class="text-right">Premium received</th>
                    <th class="text-right">Roll net credit</th>
                    <th class="text-right">Leg realized P/L</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->legs as $leg)
                    <tr wire:key="leg-{{ $leg->id }}">
                        <td class="font-medium">
                            <a href="{{ route('options.show', $leg) }}" class="link" wire:navigate>{{ $leg->description() }}</a>
                        </td>
                        <td>{{ $leg->opened_on->format('M j, Y') }}</td>
                        <td class="num">{{ $leg->contracts }}</td>
                        <td class="num">{{ $leg->premiumReceived()->format() }}</td>
                        <td class="num">{{ $leg->rollNetCredit()?->format() ?? '—' }}</td>
                        <td class="num">{{ $leg->realizedNetProfit()->format() }}</td>
                        <td>{{ $leg->isOpen() ? 'Open ('.$leg->openContracts().')' : 'Closed' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="form-help">
        Leg realized P/L is that leg's premium kept minus buy-back costs and all fees. Roll net credit is the new leg's premium
        minus the buy-back cost of the leg it replaced, the closing fee and the new opening fee. A roll is not a completed
        cycle by itself.
    </p>
</div>
