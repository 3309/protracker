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
        abort_if($this->stockTrade->isFromAssignment(), 403, 'A trade created by an assignment is changed through the assignment.');

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
        abort_if($this->stockTrade->isFromAssignment(), 403, 'A trade created by an assignment is voided through the assignment.');

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
            <h1 class="page-title">
                {{ $stockTrade->side->label() }} {{ $stockTrade->shares->format() }} {{ $stockTrade->ticker }}
                @if ($stockTrade->isVoided())
                    <x-badge tone="gray" class="ms-2 align-middle">Voided</x-badge>
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
        <x-alert level="success">{{ session('message') }}</x-alert>
    @endif

    @if ($stockTrade->isVoided())
        <x-alert level="info" title="Voided">This trade was voided. It no longer counts in any total and cannot be changed. Its history is shown below.</x-alert>
    @elseif ($stockTrade->isFromAssignment())
        <x-alert level="info" title="Created by an assignment">
            This trade was created by an option assignment at the strike. To correct or void it, change
            <a href="{{ route('options.events.edit', $stockTrade->option_event_id) }}" class="link" wire:navigate>the assignment</a>.
        </x-alert>
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
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save changes</button>
                <button type="button" wire:click="void" wire:confirm="Void this trade? It stays in the history but no longer counts, and realized profit for {{ $stockTrade->ticker }} in this account will be recalculated." class="btn-danger">Void trade</button>
            </div>
        </form>
    @endif

    @if (! $stockTrade->isBuy() && $this->allocations->isNotEmpty())
        <section class="card flex flex-col gap-4">
            <div>
                <h2 class="section-title">Shares used (oldest first)</h2>
                <p class="form-help">Fees are this sale's fee and the matching part of each lot's purchase fee.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Lot bought on</th>
                            <th class="text-right">Shares</th>
                            <th class="text-right">Cost / share</th>
                            <th class="text-right">Proceeds</th>
                            <th class="text-right">Cost</th>
                            <th class="text-right">Gross P/L</th>
                            <th class="text-right">Fees</th>
                            <th class="text-right">Net P/L</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->allocations as $piece)
                            <tr wire:key="piece-{{ $piece->id }}">
                                <td>{{ $piece->lot->acquired_on->format('M j, Y') }} <span class="text-xs text-zinc-500">{{ $piece->lot->origin->label() }}</span></td>
                                <td class="num">{{ $piece->shares->format() }}</td>
                                <td class="num">{{ $piece->lot->cost_per_share->format() }}</td>
                                <td class="num">{{ $piece->proceeds->format() }}</td>
                                <td class="num">{{ $piece->cost->format() }}</td>
                                <td class="num"><x-money :amount="$piece->grossProfit()" /></td>
                                <td class="num">{{ $piece->fees()->format() }}</td>
                                <td class="num"><x-money :amount="$piece->netProfit()" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="7" class="font-medium">Net realized profit on this sale</td>
                            <td class="num"><x-money :amount="$this->totalNetProfit" /></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    @endif

    @include('partials.audit-history', ['entries' => $this->auditEntries, 'timezone' => $this->displayTimezone])
</div>
