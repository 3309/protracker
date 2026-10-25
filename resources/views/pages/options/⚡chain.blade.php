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
            <h1 class="text-2xl font-semibold">Roll chain: {{ $optionPosition->underlying }} {{ $optionPosition->option_type->label() }}</h1>
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
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-3 font-medium">Leg</th>
                    <th class="px-4 py-3 font-medium">Opened</th>
                    <th class="px-4 py-3 text-right font-medium">Contracts</th>
                    <th class="px-4 py-3 text-right font-medium">Premium received</th>
                    <th class="px-4 py-3 text-right font-medium">Roll net credit</th>
                    <th class="px-4 py-3 text-right font-medium">Leg realized P/L</th>
                    <th class="px-4 py-3 font-medium">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->legs as $leg)
                    <tr wire:key="leg-{{ $leg->id }}">
                        <td class="px-4 py-2 font-medium">
                            <a href="{{ route('options.show', $leg) }}" class="underline underline-offset-4" wire:navigate>{{ $leg->description() }}</a>
                        </td>
                        <td class="px-4 py-2">{{ $leg->opened_on->format('M j, Y') }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $leg->contracts }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $leg->premiumReceived()->format() }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $leg->rollNetCredit()?->format() ?? '—' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $leg->realizedNetProfit()->format() }}</td>
                        <td class="px-4 py-2">{{ $leg->isOpen() ? 'Open ('.$leg->openContracts().')' : 'Closed' }}</td>
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
