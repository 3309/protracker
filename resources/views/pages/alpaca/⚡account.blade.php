<?php

use App\Livewire\ReportPage;
use App\Services\AlpacaService;
use App\Support\Alpaca\AlpacaResult;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Alpaca paper account')] class extends ReportPage
{
    /**
     * Whether the local service answers. When it does not, nothing else is
     * requested: every other request would fail the same way.
     */
    #[Computed]
    public function connection(): AlpacaResult
    {
        return AlpacaResult::attempt(fn () => app(AlpacaService::class)->health());
    }

    #[Computed]
    public function paperAccount(): AlpacaResult
    {
        return $this->whenConnected(fn () => app(AlpacaService::class)->account());
    }

    #[Computed]
    public function positions(): AlpacaResult
    {
        return $this->whenConnected(fn () => app(AlpacaService::class)->positions());
    }

    #[Computed]
    public function orders(): AlpacaResult
    {
        return $this->whenConnected(fn () => app(AlpacaService::class)->openOrders());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function positionsOfClass(string $assetClass): array
    {
        return array_values(array_filter($this->positions->data, fn (array $position): bool => ($position['asset_class'] ?? null) === $assetClass));
    }

    /**
     * Positions that are neither stocks nor options, such as crypto, which
     * these tables do not show.
     */
    public function otherPositionCount(): int
    {
        return count($this->positions->data) - count($this->positionsOfClass('us_equity')) - count($this->positionsOfClass('us_option'));
    }

    private function whenConnected(Closure $fetch): AlpacaResult
    {
        if (! $this->connection->isAvailable()) {
            return AlpacaResult::unavailable((string) $this->connection->unavailableReason);
        }

        return AlpacaResult::attempt($fetch);
    }
};
?>

@use('App\Support\Alpaca\AlpacaFormat')

@php
    $source = 'Alpaca paper account, through the local Node.js service';
    $timezone = $this->displayTimezone;
@endphp

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="page-title">Alpaca paper account</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Read-only. This is live data from a paper account: it is not saved, and it is kept apart from your Fidelity and Robinhood records.
            </p>
        </div>
        <button type="button" wire:click="$refresh" wire:loading.attr="disabled" class="btn-secondary">Refresh</button>
    </div>

    <section class="card flex flex-col gap-3 p-5" aria-labelledby="alpaca-connection">
        <h2 id="alpaca-connection" class="font-semibold">Connection status</h2>
        <dl class="flex flex-wrap gap-x-8 gap-y-2 text-sm">
            <div class="flex items-center gap-2">
                <dt class="text-zinc-500 dark:text-zinc-400">Node.js service</dt>
                <dd>
                    @if ($this->connection->isAvailable())
                        <x-badge tone="green">Connected · paper · read-only</x-badge>
                    @else
                        <x-badge tone="red">Unavailable</x-badge>
                    @endif
                </dd>
            </div>
            <div class="flex items-center gap-2">
                <dt class="text-zinc-500 dark:text-zinc-400">Alpaca</dt>
                <dd>
                    @if ($this->paperAccount->isAvailable())
                        <x-badge tone="green">Connected · {{ $this->paperAccount->data['status'] ?? 'status unknown' }}</x-badge>
                    @elseif ($this->connection->isAvailable())
                        <x-badge tone="red">Unavailable</x-badge>
                    @else
                        <x-badge>Not checked</x-badge>
                    @endif
                </dd>
            </div>
        </dl>
        @if ($this->connection->isAvailable())
            @include('partials.alpaca-source', ['result' => $this->connection])
        @endif
    </section>

    @if (! $this->connection->isAvailable())
        <x-alert level="critical" title="Alpaca data is unavailable">{{ $this->connection->unavailableReason }}</x-alert>
    @else
        <section class="flex flex-col gap-3" aria-labelledby="alpaca-account">
            <h2 id="alpaca-account" class="font-semibold">Account summary</h2>
            @if ($this->paperAccount->isAvailable())
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-stat-card label="Cash" icon="cash"><x-money :amount="AlpacaFormat::money($this->paperAccount->data['cash'])" tone="plain" /></x-stat-card>
                    <x-stat-card label="Buying power" icon="dollar"><x-money :amount="AlpacaFormat::money($this->paperAccount->data['buyingPower'])" tone="plain" /></x-stat-card>
                    <x-stat-card label="Options buying power" icon="options"><x-money :amount="AlpacaFormat::money($this->paperAccount->data['optionsBuyingPower'] ?? null)" tone="plain" /></x-stat-card>
                    <x-stat-card label="Equity" icon="portfolio"><x-money :amount="AlpacaFormat::money($this->paperAccount->data['equity'])" tone="plain" /></x-stat-card>
                </div>
                @include('partials.alpaca-source', ['result' => $this->paperAccount])
            @else
                <x-alert level="warning" title="Account summary is unavailable">{{ $this->paperAccount->unavailableReason }}</x-alert>
            @endif
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="alpaca-stocks">
            <h2 id="alpaca-stocks" class="font-semibold">Stock positions</h2>
            @if (! $this->positions->isAvailable())
                <x-alert level="warning" title="Stock positions are unavailable">{{ $this->positions->unavailableReason }}</x-alert>
            @elseif ($this->positionsOfClass('us_equity') === [])
                <x-empty-state>Alpaca reports no stock positions.</x-empty-state>
            @else
                <ul class="flex flex-col gap-3 md:hidden" data-cards>
                    @foreach ($this->positionsOfClass('us_equity') as $position)
                        <li class="card flex flex-col gap-3 p-4" wire:key="stock-card-{{ $position['symbol'] }}">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="font-semibold">{{ $position['symbol'] }}</span>
                                <x-money :amount="AlpacaFormat::money($position['unrealized_pl'] ?? null)" />
                            </div>
                            @include('partials.alpaca-figures', ['figures' => [
                                'Side' => ucfirst($position['side'] ?? '—'),
                                'Shares' => AlpacaFormat::decimal($position['qty'] ?? null),
                                'Average cost' => AlpacaFormat::price($position['avg_entry_price'] ?? null)?->format(),
                                'Current price' => AlpacaFormat::price($position['current_price'] ?? null)?->format(),
                                'Market value' => AlpacaFormat::money($position['market_value'] ?? null)?->format(),
                            ]])
                        </li>
                    @endforeach
                </ul>
                <div class="card hidden overflow-x-auto p-0 md:block">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Symbol</th>
                                <th>Side</th>
                                <th class="text-right">Shares</th>
                                <th class="text-right">Average cost</th>
                                <th class="text-right">Current price</th>
                                <th class="text-right">Market value</th>
                                <th class="text-right">Unrealized P/L</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->positionsOfClass('us_equity') as $position)
                                <tr wire:key="stock-{{ $position['symbol'] }}">
                                    <td class="font-semibold">{{ $position['symbol'] }}</td>
                                    <td>{{ ucfirst($position['side'] ?? '—') }}</td>
                                    <td class="num">{{ AlpacaFormat::decimal($position['qty'] ?? null) ?? '—' }}</td>
                                    <td class="num">{{ AlpacaFormat::price($position['avg_entry_price'] ?? null)?->format() ?? '—' }}</td>
                                    <td class="num">{{ AlpacaFormat::price($position['current_price'] ?? null)?->format() ?? '—' }}</td>
                                    <td class="num"><x-money :amount="AlpacaFormat::money($position['market_value'] ?? null)" tone="plain" /></td>
                                    <td class="num"><x-money :amount="AlpacaFormat::money($position['unrealized_pl'] ?? null)" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="alpaca-options">
            <h2 id="alpaca-options" class="font-semibold">Options positions</h2>
            @if (! $this->positions->isAvailable())
                <x-alert level="warning" title="Options positions are unavailable">{{ $this->positions->unavailableReason }}</x-alert>
            @elseif ($this->positionsOfClass('us_option') === [])
                <x-empty-state>Alpaca reports no options positions.</x-empty-state>
            @else
                <ul class="flex flex-col gap-3 md:hidden" data-cards>
                    @foreach ($this->positionsOfClass('us_option') as $position)
                        <li class="card flex flex-col gap-3 p-4" wire:key="option-card-{{ $position['symbol'] }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    @include('partials.alpaca-contract-name', ['symbol' => $position['symbol'], 'side' => $position['side'] ?? null, 'quantity' => $position['qty'] ?? null])
                                </div>
                                <x-money :amount="AlpacaFormat::money($position['unrealized_pl'] ?? null)" />
                            </div>
                            @include('partials.alpaca-figures', ['figures' => [
                                'Average price' => AlpacaFormat::price($position['avg_entry_price'] ?? null)?->format(),
                                'Current price' => AlpacaFormat::price($position['current_price'] ?? null)?->format(),
                                'Market value' => AlpacaFormat::money($position['market_value'] ?? null)?->format(),
                            ]])
                        </li>
                    @endforeach
                </ul>
                <div class="card hidden overflow-x-auto p-0 md:block">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Contract</th>
                                <th>Side</th>
                                <th class="text-right">Contracts</th>
                                <th class="text-right">Average price</th>
                                <th class="text-right">Current price</th>
                                <th class="text-right">Market value</th>
                                <th class="text-right">Unrealized P/L</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->positionsOfClass('us_option') as $position)
                                <tr wire:key="option-{{ $position['symbol'] }}">
                                    <td>@include('partials.alpaca-contract-name', ['symbol' => $position['symbol'], 'side' => $position['side'] ?? null, 'quantity' => $position['qty'] ?? null])</td>
                                    <td>{{ ucfirst($position['side'] ?? '—') }}</td>
                                    <td class="num">{{ AlpacaFormat::decimal($position['qty'] ?? null) ?? '—' }}</td>
                                    <td class="num">{{ AlpacaFormat::price($position['avg_entry_price'] ?? null)?->format() ?? '—' }}</td>
                                    <td class="num">{{ AlpacaFormat::price($position['current_price'] ?? null)?->format() ?? '—' }}</td>
                                    <td class="num"><x-money :amount="AlpacaFormat::money($position['market_value'] ?? null)" tone="plain" /></td>
                                    <td class="num"><x-money :amount="AlpacaFormat::money($position['unrealized_pl'] ?? null)" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($this->positions->isAvailable())
                @if ($this->otherPositionCount() > 0)
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $this->otherPositionCount() }} other position(s), such as crypto, are not shown here.</p>
                @endif
                @include('partials.alpaca-source', ['result' => $this->positions])
            @endif
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="alpaca-orders">
            <h2 id="alpaca-orders" class="font-semibold">Open orders</h2>
            @if (! $this->orders->isAvailable())
                <x-alert level="warning" title="Open orders are unavailable">{{ $this->orders->unavailableReason }}</x-alert>
            @elseif ($this->orders->isEmpty())
                <x-empty-state>Alpaca reports no open orders.</x-empty-state>
            @else
                <ul class="flex flex-col gap-3 md:hidden" data-cards>
                    @foreach ($this->orders->data as $order)
                        <li class="card flex flex-col gap-3 p-4" wire:key="order-card-{{ $order['id'] }}">
                            <div>
                                @include('partials.alpaca-contract-name', ['symbol' => $order['symbol'] ?? '', 'side' => $order['side'] ?? null, 'quantity' => $order['qty'] ?? null, 'legs' => $order['legs'] ?? []])
                            </div>
                            @include('partials.alpaca-figures', ['figures' => [
                                'Side' => ucfirst($order['side'] ?? '—'),
                                'Quantity' => AlpacaFormat::decimal($order['qty'] ?? null),
                                'Type' => ucfirst(str_replace('_', ' ', $order['type'] ?? '—')),
                                'Status' => ucfirst(str_replace('_', ' ', $order['status'] ?? '—')),
                                'Limit' => AlpacaFormat::price($order['limit_price'] ?? null)?->format(),
                                'Stop' => AlpacaFormat::price($order['stop_price'] ?? null)?->format(),
                                'Submitted' => AlpacaFormat::timestamp($order['submitted_at'] ?? null)?->timezone($timezone)->format('M j, Y g:i A'),
                            ]])
                        </li>
                    @endforeach
                </ul>
                <div class="card hidden overflow-x-auto p-0 md:block">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Symbol</th>
                                <th>Side</th>
                                <th>Type</th>
                                <th class="text-right">Quantity</th>
                                <th class="text-right">Limit</th>
                                <th class="text-right">Stop</th>
                                <th>Status</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->orders->data as $order)
                                <tr wire:key="order-{{ $order['id'] }}">
                                    <td>@include('partials.alpaca-contract-name', ['symbol' => $order['symbol'] ?? '', 'side' => $order['side'] ?? null, 'quantity' => $order['qty'] ?? null, 'legs' => $order['legs'] ?? []])</td>
                                    <td>{{ ucfirst($order['side'] ?? '—') }}</td>
                                    <td>{{ ucfirst(str_replace('_', ' ', $order['type'] ?? '—')) }}</td>
                                    <td class="num">{{ AlpacaFormat::decimal($order['qty'] ?? null) ?? '—' }}</td>
                                    <td class="num">{{ AlpacaFormat::price($order['limit_price'] ?? null)?->format() ?? '—' }}</td>
                                    <td class="num">{{ AlpacaFormat::price($order['stop_price'] ?? null)?->format() ?? '—' }}</td>
                                    <td>{{ ucfirst(str_replace('_', ' ', $order['status'] ?? '—')) }}</td>
                                    <td>{{ AlpacaFormat::timestamp($order['submitted_at'] ?? null)?->timezone($timezone)->format('M j, Y g:i A') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($this->orders->isAvailable())
                @if (count($this->orders->data) >= App\Services\AlpacaService::MAX_ORDERS)
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">Showing the first {{ App\Services\AlpacaService::MAX_ORDERS }} orders. There may be more.</p>
                @endif
                @include('partials.alpaca-source', ['result' => $this->orders])
            @endif
        </section>
    @endif
</div>
