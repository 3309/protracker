<?php

use App\Livewire\ReportPage;
use App\Models\StockLot;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Positions')] class extends ReportPage
{
    /** Show holdings at the end of this date; blank means today. */
    #[Url(as: 'as-of')]
    public string $asOf = '';

    #[Computed]
    public function asOfDate(): CarbonImmutable
    {
        $date = $this->asOf !== '' && CarbonImmutable::canBeCreatedFromFormat($this->asOf, 'Y-m-d')
            ? CarbonImmutable::createFromFormat('!Y-m-d', $this->asOf)
            : null;

        return $date instanceof CarbonImmutable ? $date : $this->today();
    }

    /**
     * Lots with shares left on the as-of date, grouped by account and ticker.
     *
     * @return Collection<string, Collection<int, StockLot>>
     */
    #[Computed]
    public function positions(): Collection
    {
        $query = StockLot::query()
            ->with('account')
            ->notVoided()
            ->where('acquired_on', '<=', $this->asOfDate->format('Y-m-d'))
            ->withSoldShares($this->asOfDate);

        $this->applyAccountFilter($query);

        return $query->get()
            ->filter(fn (StockLot $lot) => $lot->remainingShares()->units > 0)
            ->sortBy([
                fn (StockLot $a, StockLot $b) => $a->account->nickname <=> $b->account->nickname,
                fn (StockLot $a, StockLot $b) => $a->ticker <=> $b->ticker,
                fn (StockLot $a, StockLot $b) => [$a->acquired_on->format('Y-m-d'), $a->id] <=> [$b->acquired_on->format('Y-m-d'), $b->id],
            ])
            ->groupBy(fn (StockLot $lot) => $lot->account_id.'|'.$lot->ticker)
            ->toBase();
    }

    /**
     * @param  Collection<int, StockLot>  $lots
     */
    public function totalShares(Collection $lots): Quantity
    {
        return Quantity::fromUnits($lots->sum(fn (StockLot $lot) => $lot->remainingShares()->units));
    }

    /**
     * @param  Collection<int, StockLot>  $lots
     */
    public function totalCostBasis(Collection $lots): Money
    {
        return $lots->reduce(fn (Money $total, StockLot $lot) => $total->plus($lot->remainingCostBasis()), Money::zero());
    }
};
?>

<div class="flex flex-col gap-6">
    <div>
        <h1 class="text-2xl font-semibold">Positions</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Stock lots with shares still held at the end of {{ $this->asOfDate->format('F j, Y') }}, oldest first.
            Cost is the actual purchase price (Method B); option premium is not included.
        </p>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <div class="grow">
            @include('partials.report-filters', ['showPeriod' => false])
        </div>
        <div class="card">
            <label for="as_of" class="form-label">Holdings as of</label>
            <input id="as_of" type="date" wire:model.live="asOf" class="form-input">
        </div>
    </div>

    @if ($this->positions->isEmpty())
        <div class="card">No shares held on this date.</div>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Ticker</th>
                        <th class="px-4 py-3 font-medium">Bought on</th>
                        <th class="px-4 py-3 text-right font-medium">Shares held</th>
                        <th class="px-4 py-3 text-right font-medium">Cost / share</th>
                        <th class="px-4 py-3 text-right font-medium">Cost basis</th>
                    </tr>
                </thead>
                @foreach ($this->positions as $key => $lots)
                    <tbody wire:key="position-{{ $key }}" class="border-b border-zinc-200 dark:border-zinc-700">
                        @foreach ($lots as $lot)
                            <tr wire:key="position-lot-{{ $lot->id }}">
                                <td class="px-4 py-2">{{ $lot->account->displayName() }}</td>
                                <td class="px-4 py-2">{{ $lot->ticker }}</td>
                                <td class="px-4 py-2">
                                    {{ $lot->acquired_on->format('M j, Y') }}
                                    <span class="text-xs text-zinc-500">{{ $lot->origin->label() }}</span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $lot->remainingShares()->format() }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $lot->cost_per_share->format() }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $lot->remainingCostBasis()->format() }}</td>
                            </tr>
                        @endforeach
                        <tr class="bg-zinc-50 font-medium dark:bg-zinc-800/50">
                            <td class="px-4 py-2">{{ $lots->first()->account->displayName() }}</td>
                            <td class="px-4 py-2">{{ $lots->first()->ticker }} total</td>
                            <td class="px-4 py-2"></td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $this->totalShares($lots)->format() }}</td>
                            <td class="px-4 py-2"></td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $this->totalCostBasis($lots)->format() }}</td>
                        </tr>
                    </tbody>
                @endforeach
            </table>
        </div>
    @endif
</div>
