<?php

use App\Enums\PositionOrigin;
use App\Livewire\Forms\BuyToCloseForm;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Option position')] class extends Component
{
    public OptionPosition $optionPosition;

    public BuyToCloseForm $close;

    public function mount(OptionPosition $optionPosition): void
    {
        $this->optionPosition = $optionPosition;
        $this->close->contracts = (string) $optionPosition->openContracts();
    }

    public function buyToClose(): void
    {
        abort_if($this->optionPosition->isVoided(), 403, 'A voided option cannot be closed.');

        $event = $this->close->store($this->optionPosition);

        if ($event === null) {
            return;
        }

        unset($this->events, $this->realizedNetProfit);

        $this->close->contracts = (string) $this->optionPosition->openContracts();

        session()->now('message', "Bought to close {$event->contracts} ".str('contract')->plural($event->contracts).". Net realized P/L: {$event->netProfit()->format()}.");
    }

    /**
     * Every event of the position, voided ones included so nothing disappears.
     *
     * @return Collection<int, OptionEvent>
     */
    #[Computed]
    public function events(): Collection
    {
        return $this->optionPosition->events()
            ->orderBy('event_date')
            ->orderBy('id')
            ->get()
            ->each(fn (OptionEvent $event) => $event->setRelation('position', $this->optionPosition));
    }

    #[Computed]
    public function realizedNetProfit(): Money
    {
        return $this->events->reject->isVoided()
            ->reduce(fn (Money $total, OptionEvent $event) => $total->plus($event->netProfit()), Money::zero());
    }

    #[Computed]
    public function correctionUrl(): string
    {
        return $this->optionPosition->origin === PositionOrigin::OpeningBalance
            ? route('opening-balances.options.edit', $this->optionPosition)
            : route('options.edit', $this->optionPosition);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">
                {{ $optionPosition->description() }}
                @if ($optionPosition->isVoided())
                    <span class="ms-2 rounded bg-zinc-200 px-2 py-0.5 text-sm font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">Voided</span>
                @elseif (! $optionPosition->isOpen())
                    <span class="ms-2 rounded bg-zinc-200 px-2 py-0.5 text-sm font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">Closed</span>
                @endif
            </h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $optionPosition->account->displayName() }} · {{ $optionPosition->origin->label() }} · {{ $optionPosition->side->label() }}</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="{{ $this->correctionUrl }}" class="btn-secondary" wire:navigate>{{ $optionPosition->isVoided() ? 'View history' : 'Correct or void' }}</a>
            <a href="{{ route('options.index') }}" class="btn-secondary" wire:navigate>Back to options</a>
        </div>
    </div>

    @if (session('message'))
        <div class="status-message">{{ session('message') }}</div>
    @endif

    <section class="card">
        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Opened</dt>
                <dd>
                    {{ $optionPosition->opened_on->format('M j, Y') }}
                    @if ($optionPosition->opening_settlement_date)
                        <span class="text-zinc-500">· settles {{ $optionPosition->opening_settlement_date->format('M j, Y') }}</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Contracts open / total</dt>
                <dd class="tabular-nums">
                    {{ $optionPosition->openContracts() }} / {{ $optionPosition->contracts }}
                    <span class="text-zinc-500">({{ $optionPosition->multiplier }} shares each)</span>
                </dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Premium per share</dt>
                <dd class="tabular-nums">{{ $optionPosition->premium_per_share->format() }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Premium received</dt>
                <dd class="tabular-nums">{{ $optionPosition->premiumReceived()->format() }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Opening fees</dt>
                <dd class="tabular-nums">{{ $optionPosition->opening_fees->format() }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Realized net P/L so far</dt>
                <dd class="tabular-nums">{{ $this->realizedNetProfit->format() }}</dd>
            </div>
        </dl>
        <p class="form-help mt-4">Premium becomes realized profit only for contracts that have closed. Open contracts are not counted yet.</p>
    </section>

    @if ($this->events->isNotEmpty())
        <section class="card flex flex-col gap-4">
            <h2 class="text-lg font-semibold">Closes</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        <tr>
                            <th class="py-2 pe-4 font-medium">Date</th>
                            <th class="py-2 pe-4 font-medium">Event</th>
                            <th class="py-2 pe-4 text-right font-medium">Contracts</th>
                            <th class="py-2 pe-4 text-right font-medium">Price</th>
                            <th class="py-2 pe-4 text-right font-medium">Premium kept</th>
                            <th class="py-2 pe-4 text-right font-medium">Closing cost</th>
                            <th class="py-2 pe-4 text-right font-medium">Gross P/L</th>
                            <th class="py-2 pe-4 text-right font-medium">Fees</th>
                            <th class="py-2 pe-4 text-right font-medium">Net P/L</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->events as $event)
                            <tr wire:key="event-{{ $event->id }}" @class(['text-zinc-400 line-through dark:text-zinc-500' => $event->isVoided()])>
                                <td class="py-2 pe-4">{{ $event->event_date->format('M j, Y') }}</td>
                                <td class="py-2 pe-4">{{ $event->event_type->label() }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $event->contracts }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $event->price_per_share?->format() }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $event->allocated_premium->format() }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $event->closingCost()->format() }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $event->grossProfit()->format() }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $event->totalFees()->format() }}</td>
                                <td class="py-2 pe-4 text-right tabular-nums">{{ $event->netProfit()->format() }}</td>
                                <td class="py-2 text-right">
                                    @if ($event->isVoided())
                                        <span>Voided</span>
                                    @endif
                                    <a href="{{ route('options.events.edit', $event) }}" class="underline underline-offset-4" wire:navigate>{{ $event->isVoided() ? 'View' : 'Edit' }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="form-help">Fees are the opening fee for these contracts plus the closing fee.</p>
        </section>
    @endif

    @if (! $optionPosition->isVoided() && $optionPosition->isOpen())
        <form wire:submit="buyToClose" class="card flex flex-col gap-4">
            <h2 class="text-lg font-semibold">Buy to close</h2>

            @include('partials.buy-to-close-fields', ['feePerContract' => $optionPosition->account->option_fee_per_contract, 'prefix' => 'close'])

            <div>
                <button type="submit" class="btn-primary">Record buy to close</button>
            </div>
        </form>
    @endif
</div>
