<?php

use App\Actions\Stocks\SaveStockTrade;
use App\Exceptions\InsufficientSharesException;
use App\Livewire\Forms\StockTradeForm;
use App\Models\AuditEntry;
use App\Models\Setting;
use App\Models\StockSaleAllocation;
use App\Models\StockTrade;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit stock trade')] class extends Component
{
    public StockTrade $stockTrade;

    public StockTradeForm $form;

    public function mount(StockTrade $stockTrade): void
    {
        $this->stockTrade = $stockTrade;
        $this->form->setStockTrade($stockTrade);
    }

    public function save(): void
    {
        abort_if($this->stockTrade->isVoided(), 403, 'A voided trade cannot be changed.');

        $saved = $this->form->update();

        $this->stockTrade->refresh();

        if (! $saved) {
            return;
        }

        $this->refreshDetails();

        session()->now('message', 'Trade saved. Realized profit for this ticker has been recalculated.');
    }

    public function void(SaveStockTrade $saveStockTrade): void
    {
        abort_if($this->stockTrade->isVoided(), 403, 'This trade is already voided.');

        $this->validate(
            ['form.reason' => ['required', 'string', 'max:1000']],
            ['form.reason.required' => 'Please explain why you are voiding this trade. It is saved in the audit history.'],
        );

        try {
            $saveStockTrade->void($this->stockTrade, $this->form->reason);
        } catch (InsufficientSharesException $exception) {
            $this->stockTrade->refresh();
            $this->addError('form.reason', 'This buy cannot be voided because later sales use its shares. '.$exception->getMessage());

            return;
        }

        $this->form->reset('reason');
        $this->stockTrade->refresh();
        $this->refreshDetails();

        session()->now('message', 'Trade voided. It is kept in the history but no longer counts, and realized profit has been recalculated.');
    }

    /**
     * For a sale, the lot pieces it used with their profit.
     *
     * @return Collection<int, StockSaleAllocation>
     */
    #[Computed]
    public function allocations(): Collection
    {
        return $this->stockTrade->saleAllocations()->with('lot')->orderBy('id')->get();
    }

    #[Computed]
    public function totalNetProfit(): Money
    {
        return $this->allocations->reduce(fn (Money $total, StockSaleAllocation $piece) => $total->plus($piece->netProfit()), Money::zero());
    }

    /**
     * @return Collection<int, AuditEntry>
     */
    #[Computed]
    public function auditEntries(): Collection
    {
        return $this->stockTrade->auditEntries()->with('user')->latest('id')->get();
    }

    #[Computed]
    public function displayTimezone(): string
    {
        return Setting::current()->display_timezone;
    }

    private function refreshDetails(): void
    {
        unset($this->allocations, $this->totalNetProfit, $this->auditEntries);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">
                {{ $stockTrade->side->label() }} {{ $stockTrade->shares->format() }} {{ $stockTrade->ticker }}
                @if ($stockTrade->isVoided())
                    <span class="ms-2 rounded bg-zinc-200 px-2 py-0.5 text-sm font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">Voided</span>
                @endif
            </h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                {{ $stockTrade->account->displayName() }} · {{ $stockTrade->trade_date->format('M j, Y') }}
                · Settles {{ $stockTrade->settlement_date->format('M j, Y') }}
            </p>
        </div>
        <a href="{{ route('stock-trades.index') }}" class="btn-secondary" wire:navigate>Back to stock trades</a>
    </div>

    @if (session('message'))
        <div class="status-message">{{ session('message') }}</div>
    @endif

    @if ($stockTrade->isVoided())
        <div class="card text-sm">This trade was voided. It no longer counts in any total and cannot be changed. Its history is shown below.</div>
    @else
        <form wire:submit="save" wire:confirm="Save this correction? Realized profit for {{ $stockTrade->ticker }} in this account will be recalculated." class="card flex flex-col gap-6">
            @include('partials.stock-trade-fields', ['stockTrade' => $stockTrade])

            <div>
                <label for="reason" class="form-label">Reason for this change</label>
                <textarea id="reason" rows="2" wire:model="form.reason" class="form-input" placeholder="e.g. Corrected the price from the trade confirmation"></textarea>
                <p class="form-help">Required for any change, including voiding. It is kept permanently in the history below.</p>
                @error('form.reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary">Save changes</button>
                <button type="button" wire:click="void" wire:confirm="Void this trade? It stays in the history but no longer counts, and realized profit for {{ $stockTrade->ticker }} in this account will be recalculated." class="btn-danger">Void trade</button>
            </div>
        </form>
    @endif

    @if (! $stockTrade->isBuy() && $this->allocations->isNotEmpty())
        <section class="card flex flex-col gap-4">
            <div>
                <h2 class="text-lg font-semibold">Shares used (oldest first)</h2>
                <p class="form-help">Fees are this sale's fee and the matching part of each lot's purchase fee.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        <tr>
                            <th class="py-2 pe-4 font-medium">Lot bought on</th>
                            <th class="py-2 pe-4 text-right font-medium">Shares</th>
                            <th class="py-2 pe-4 text-right font-medium">Cost / share</th>
                            <th class="py-2 pe-4 text-right font-medium">Proceeds</th>
                            <th class="py-2 pe-4 text-right font-medium">Cost</th>
                            <th class="py-2 pe-4 text-right font-medium">Gross P/L</th>
                            <th class="py-2 pe-4 text-right font-medium">Fees</th>
                            <th class="py-2 text-right font-medium">Net P/L</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->allocations as $piece)
                            <tr wire:key="piece-{{ $piece->id }}">
                                <td class="py-2 pe-4">{{ $piece->lot->acquired_on->format('M j, Y') }} <span class="text-xs text-zinc-500">{{ $piece->lot->origin->label() }}</span></td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $piece->shares->format() }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $piece->lot->cost_per_share->format() }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $piece->proceeds->format() }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $piece->cost->format() }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $piece->grossProfit()->format() }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $piece->fees()->format() }}</td>
                                <td class="py-2 text-right tabular-nums">{{ $piece->netProfit()->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="border-t border-zinc-200 dark:border-zinc-700">
                        <tr>
                            <td colspan="7" class="py-2 pe-4 font-medium">Net realized profit on this sale</td>
                            <td class="py-2 text-right font-medium tabular-nums">{{ $this->totalNetProfit->format() }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    @endif

    @include('partials.audit-history', ['entries' => $this->auditEntries, 'timezone' => $this->displayTimezone])
</div>
