<?php

use App\Livewire\ReportPage;
use App\Models\StockLot;
use App\Support\CsvExport;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $this->applyTickerFilter($query);

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
     * The lots shown, one row each, as a CSV file.
     */
    public function export(): StreamedResponse
    {
        return CsvExport::download('stock-lots', [
            'as_of', 'account', 'ticker', 'acquired_on', 'origin', 'shares_held', 'cost_per_share', 'cost_basis',
        ], $this->positions->flatten()->map(fn (StockLot $lot) => [
            $this->asOfDate,
            $lot->account->displayName(),
            $lot->ticker,
            $lot->acquired_on,
            $lot->origin->label(),
            $lot->remainingShares(),
            $lot->cost_per_share,
            $lot->remainingCostBasis(),
        ])->all());
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
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">Positions</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Stock lots with shares still held at the end of {{ $this->asOfDate->format('F j, Y') }}, oldest first.
                Cost is the actual purchase price (Method B); option premium is not included.
            </p>
        </div>
        <button type="button" wire:click="export" class="btn-secondary"><x-icon name="download" class="size-4" />Download CSV</button>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <div class="grow">
            @include('partials.report-filters', ['showPeriod' => false])
        </div>
        <div class="card p-4">
            <label for="as_of" class="form-label">Holdings as of</label>
            <input id="as_of" type="date" wire:model.live="asOf" class="form-input">
        </div>
    </div>

    @if ($this->positions->isEmpty())
        <x-empty-state>No shares held on this date.</x-empty-state>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Ticker</th>
                        <th>Bought on</th>
                        <th class="text-right">Shares held</th>
                        <th class="text-right">Cost / share</th>
                        <th class="text-right">Cost basis</th>
                        <th><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                @foreach ($this->positions as $key => $lots)
                    <tbody wire:key="position-{{ $key }}" class="border-b-2 border-zinc-200 dark:border-zinc-700">
                        @foreach ($lots as $lot)
                            <tr wire:key="position-lot-{{ $lot->id }}">
                                <td>{{ $lot->account->displayName() }}</td>
                                <td class="font-semibold">{{ $lot->ticker }}</td>
                                <td>
                                    {{ $lot->acquired_on->format('M j, Y') }}
                                    <span class="text-xs text-zinc-500">{{ $lot->origin->label() }}</span>
                                </td>
                                <td class="num">{{ $lot->remainingShares()->format() }}</td>
                                <td class="num">{{ $lot->cost_per_share->format() }}</td>
                                <td class="num">{{ $lot->remainingCostBasis()->format() }}</td>
                                <td class="text-right">
                                    @if ($lot->origin === App\Enums\PositionOrigin::OpeningBalance)
                                        <a href="{{ route('opening-balances.stock-lots.edit', $lot) }}" class="link" wire:navigate>Edit</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        <tr class="bg-zinc-50 font-semibold dark:bg-zinc-800/50">
                            <td>{{ $lots->first()->account->displayName() }}</td>
                            <td>{{ $lots->first()->ticker }} total</td>
                            <td></td>
                            <td class="num">{{ $this->totalShares($lots)->format() }}</td>
                            <td></td>
                            <td class="num">{{ $this->totalCostBasis($lots)->format() }}</td>
                            <td></td>
                        </tr>
                    </tbody>
                @endforeach
            </table>
        </div>
    @endif
</div>
