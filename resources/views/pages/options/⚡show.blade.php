<?php

use App\Enums\OptionEventType;
use App\Enums\OptionType;
use App\Enums\PositionOrigin;
use App\Livewire\Forms\BuyToCloseForm;
use App\Livewire\Forms\OptionLifecycleForm;
use App\Livewire\Forms\RollForm;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Option position')] class extends Component
{
    /** The actions offered for open contracts of a sold option. */
    public const array ACTIONS = [
        'buy_to_close' => 'Buy to close',
        'expired' => 'Expired worthless',
        'assigned' => 'Assigned',
        'roll' => 'Roll',
    ];

    /** The actions offered for open contracts of a bought option. */
    public const array LONG_ACTIONS = [
        'sell_to_close' => 'Sell to close',
        'expired' => 'Expired worthless',
    ];

    public OptionPosition $optionPosition;

    public string $action = 'buy_to_close';

    public BuyToCloseForm $close;

    public OptionLifecycleForm $lifecycle;

    public RollForm $roll;

    public function mount(OptionPosition $optionPosition): void
    {
        $this->optionPosition = $optionPosition;
        $this->action = $this->closeAction();
        $this->close->contracts = (string) $optionPosition->openContracts();
    }

    /**
     * @return array<string, string>
     */
    public function actions(): array
    {
        return $this->optionPosition->isLong() ? self::LONG_ACTIONS : self::ACTIONS;
    }

    public function chooseAction(string $action): void
    {
        abort_unless(array_key_exists($action, $this->actions()), 404);

        $this->action = $action;

        match ($action) {
            'expired' => $this->lifecycle->prepare($this->optionPosition, OptionEventType::Expired),
            'assigned' => $this->lifecycle->prepare($this->optionPosition, OptionEventType::Assigned),
            'roll' => $this->roll->prepare($this->optionPosition),
            default => $this->close->contracts = (string) $this->optionPosition->openContracts(),
        };
    }

    /**
     * Buy back contracts of a sold option, or sell contracts of a bought one.
     */
    public function buyToClose(): void
    {
        $this->ensureCanRecordEvents();

        $event = $this->close->store($this->optionPosition);

        if ($event === null) {
            return;
        }

        $this->afterEvent();

        session()->now('message', ($this->optionPosition->isLong() ? 'Sold to close' : 'Bought to close')." {$event->contracts} ".str('contract')->plural($event->contracts).". Net realized P/L: {$event->netProfit()->format()}.");
    }

    public function recordLifecycleEvent(): void
    {
        $this->ensureCanRecordEvents();

        $event = $this->lifecycle->store($this->optionPosition);

        if ($event === null) {
            return;
        }

        $this->afterEvent();

        $message = $event->event_type === OptionEventType::Assigned
            ? "Assigned {$event->contracts} ".str('contract')->plural($event->contracts).'. The stock '.($this->optionPosition->option_type === OptionType::Put ? 'purchase' : 'sale').' at the strike has been recorded.'
            : "{$event->contracts} ".str('contract')->plural($event->contracts).' expired worthless.';

        session()->now('message', "{$message} Net realized option P/L: {$event->netProfit()->format()}.");
    }

    public function recordRoll(): void
    {
        $this->ensureCanRecordEvents();

        $newLeg = $this->roll->store($this->optionPosition);

        if ($newLeg === null) {
            return;
        }

        session()->flash('message', "Rolled into {$newLeg->contracts} × {$newLeg->description()}. Roll net credit: {$newLeg->rollNetCredit()?->format()}.");

        $this->redirectRoute('options.show', $newLeg, navigate: true);
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
            ->with(['stockTrade', 'rolledInto'])
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

    private function ensureCanRecordEvents(): void
    {
        abort_if($this->optionPosition->isVoided(), 403, 'A voided option cannot be closed.');
    }

    /**
     * Closing at a price: buy to close for a sold option, sell to close for a bought one.
     */
    private function closeAction(): string
    {
        return $this->optionPosition->isLong() ? 'sell_to_close' : 'buy_to_close';
    }

    private function afterEvent(): void
    {
        unset($this->events, $this->realizedNetProfit);

        $this->action = $this->closeAction();
        $this->close->contracts = (string) $this->optionPosition->openContracts();
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">
                {{ $optionPosition->description() }}
                @if ($optionPosition->isVoided())
                    <x-badge tone="gray" class="ms-2 align-middle">Voided</x-badge>
                @elseif (! $optionPosition->isOpen())
                    <x-badge tone="gray" class="ms-2 align-middle">Closed</x-badge>
                @else
                    <x-badge tone="green" class="ms-2 align-middle">Open</x-badge>
                @endif
                <x-badge :tone="$optionPosition->isLong() ? 'blue' : 'gray'" class="ms-1 align-middle">{{ $optionPosition->isLong() ? 'Bought (long)' : 'Sold (short)' }}</x-badge>
            </h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $optionPosition->account->displayName() }} · {{ $optionPosition->origin->label() }} · {{ $optionPosition->side->label() }}</p>
            @if ($optionPosition->rolledFromEvent)
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Opened by rolling
                    <a href="{{ route('options.show', $optionPosition->rolledFromEvent->option_position_id) }}" class="link" wire:navigate>{{ $optionPosition->rolledFromEvent->position->description() }}</a>
                    · roll net credit {{ $optionPosition->rollNetCredit()?->format() }}
                </p>
            @endif
        </div>
        <div class="flex flex-wrap gap-3">
            @if ($optionPosition->isInRollChain())
                <a href="{{ route('options.chain', $optionPosition) }}" class="btn-secondary" wire:navigate>Roll chain</a>
            @endif
            <a href="{{ $this->correctionUrl }}" class="btn-secondary" wire:navigate>{{ $optionPosition->isVoided() ? 'View history' : 'Correct or void' }}</a>
            <a href="{{ route('options.index') }}" class="btn-secondary" wire:navigate>Back to options</a>
        </div>
    </div>

    @if (session('message'))
        <x-alert level="success">{{ session('message') }}</x-alert>
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
                <dt class="text-zinc-500 dark:text-zinc-400">{{ $optionPosition->isLong() ? 'Premium paid' : 'Premium received' }}</dt>
                <dd class="tabular-nums">{{ $optionPosition->premiumReceived()->format() }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Opening fees</dt>
                <dd class="tabular-nums">{{ $optionPosition->opening_fees->format() }}</dd>
            </div>
            @if ($optionPosition->isLong())
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Total opening cost</dt>
                    <dd class="tabular-nums">{{ $optionPosition->premiumReceived()->plus($optionPosition->opening_fees)->format() }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Still open: premium / fees</dt>
                    <dd class="tabular-nums">{{ $optionPosition->openPremium()->format() }} / {{ $optionPosition->openOpeningFees()->format() }}</dd>
                </div>
            @endif
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">Realized net P/L so far</dt>
                <dd class="text-base font-semibold"><x-money :amount="$this->realizedNetProfit" /></dd>
            </div>
        </dl>
        <p class="form-help mt-4">
            @if ($optionPosition->isLong())
                Profit or loss is realized only for contracts that have been sold to close or expired. Buying the option is not a loss by itself.
            @else
                Premium becomes realized profit only for contracts that have closed, expired or been assigned. Open contracts are not counted yet.
            @endif
        </p>
    </section>

    @if ($this->events->isNotEmpty())
        <section class="card flex flex-col gap-4">
            <h2 class="section-title">Events</h2>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Event</th>
                            <th class="text-right">Contracts</th>
                            <th class="text-right">Price</th>
                            <th class="text-right">{{ $optionPosition->isLong() ? 'Premium paid' : 'Premium kept' }}</th>
                            <th class="text-right">{{ $optionPosition->isLong() ? 'Sale proceeds' : 'Closing cost' }}</th>
                            <th class="text-right">Gross P/L</th>
                            <th class="text-right">Fees</th>
                            <th class="text-right">Net P/L</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->events as $event)
                            <tr wire:key="event-{{ $event->id }}" @class(['text-zinc-400 line-through dark:text-zinc-500' => $event->isVoided()])>
                                <td>{{ $event->event_date->format('M j, Y') }}</td>
                                <td>
                                    {{ $event->event_type->label() }}
                                    @if ($event->stockTrade)
                                        <a href="{{ route('stock-trades.edit', $event->stockTrade) }}" class="block text-xs underline underline-offset-4" wire:navigate>
                                            Stock {{ $event->stockTrade->side->label() }} {{ $event->stockTrade->shares->format() }} at {{ $event->stockTrade->price_per_share->format() }}
                                        </a>
                                    @endif
                                    @if ($event->rolledInto)
                                        <a href="{{ route('options.show', $event->rolledInto) }}" class="block text-xs underline underline-offset-4" wire:navigate>
                                            Into {{ $event->rolledInto->description() }}
                                        </a>
                                    @endif
                                </td>
                                <td class="num">{{ $event->contracts }}</td>
                                <td class="num">{{ $event->price_per_share?->format() }}</td>
                                <td class="num">{{ $event->allocated_premium->format() }}</td>
                                <td class="num">{{ $event->closingCost()->format() }}</td>
                                <td class="num">
                                    @if ($event->isVoided())
                                        {{ $event->grossProfit()->format() }}
                                    @else
                                        <x-money :amount="$event->grossProfit()" />
                                    @endif
                                </td>
                                <td class="num">{{ $event->totalFees()->format() }}</td>
                                <td class="num font-semibold">
                                    @if ($event->isVoided())
                                        {{ $event->netProfit()->format() }}
                                    @else
                                        <x-money :amount="$event->netProfit()" />
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    @if ($event->isVoided())
                                        <x-badge>Voided</x-badge>
                                    @endif
                                    <a href="{{ route('options.events.edit', $event) }}" class="link" wire:navigate>{{ $event->isVoided() ? 'View' : 'Edit' }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="form-help">
                Fees are the opening fee for these contracts plus any closing fee. An assignment fee is charged on its stock trade,
                so it counts in stock profit.
            </p>
        </section>
    @endif

    @if (! $optionPosition->isVoided() && $optionPosition->isOpen())
        <section class="card flex flex-col gap-4">
            <div>
                <h2 class="section-title">Record what happened</h2>
                <p class="form-help">
                    @if ($optionPosition->isLong())
                        You bought this option. Sell to close if you sold it, or mark it expired worthless on or after the expiration date.
                    @else
                        You sold this option. Buy to close if you bought it back, roll it into a new option, or record an expiry or assignment.
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2" role="tablist">
                @foreach ($this->actions() as $key => $label)
                    <button
                        type="button"
                        role="tab"
                        wire:click="chooseAction('{{ $key }}')"
                        @class(['btn-primary' => $action === $key, 'btn-secondary' => $action !== $key])
                    >{{ $label }}</button>
                @endforeach
            </div>

            @if ($action === 'buy_to_close' || $action === 'sell_to_close')
                <form wire:submit="buyToClose" class="flex flex-col gap-4">
                    @include('partials.buy-to-close-fields', ['feePerContract' => $optionPosition->account->option_fee_per_contract, 'prefix' => 'close', 'isSale' => $optionPosition->isLong()])
                    <div>
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $optionPosition->isLong() ? 'Record sell to close' : 'Record buy to close' }}</button>
                    </div>
                </form>
            @elseif ($action === 'roll')
                <form wire:submit="recordRoll" class="flex flex-col gap-4">
                    @include('partials.roll-fields', ['optionPosition' => $optionPosition])
                    <div>
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled">Record roll</button>
                    </div>
                </form>
            @else
                <form wire:submit="recordLifecycleEvent" class="flex flex-col gap-4">
                    @include('partials.option-lifecycle-fields', ['optionPosition' => $optionPosition, 'prefix' => 'lifecycle', 'isAssignment' => $action === 'assigned'])
                    <div>
                        <button type="submit" class="btn-primary" wire:loading.attr="disabled">{{ $action === 'assigned' ? 'Record assignment' : 'Record expiry' }}</button>
                    </div>
                </form>
            @endif
        </section>
    @endif
</div>
