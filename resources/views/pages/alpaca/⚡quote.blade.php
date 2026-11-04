<?php

use App\Livewire\ReportPage;
use App\Services\AlpacaService;
use App\Support\Alpaca\AlpacaResult;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Alpaca stock quote')] class extends ReportPage
{
    #[Url]
    public string $symbol = '';

    public function lookup(): void
    {
        $this->symbol = strtoupper(trim($this->symbol));

        $this->validate(
            ['symbol' => ['required', 'regex:'.AlpacaService::SYMBOL_PATTERN]],
            ['symbol.required' => 'Enter a stock symbol.', 'symbol.regex' => 'Enter one symbol of up to 10 letters, digits, dots or dashes, starting with a letter.'],
        );
    }

    /**
     * Look up the symbol again, skipping any saved copy.
     */
    public function refresh(): void
    {
        $this->lookup();

        app(AlpacaService::class)->forgetQuote($this->symbol);
    }

    /**
     * Null until there is a valid symbol to look up. Kept out of the public
     * properties, so it is fetched on the server for each page update.
     */
    #[Computed]
    public function quote(): ?AlpacaResult
    {
        if ($this->symbol === '' || $this->getErrorBag()->isNotEmpty()) {
            return null;
        }

        return AlpacaResult::attempt(fn () => app(AlpacaService::class)->quote($this->symbol));
    }
};
?>

@use('App\Support\Alpaca\AlpacaFormat')

<div class="flex flex-col gap-6">
    <div>
        <h1 class="page-title">Alpaca stock quote</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">Look up the current price of one stock. Quotes are not saved to your price history.</p>
    </div>

    <form wire:submit="lookup" class="card flex flex-wrap items-end gap-4 p-4">
        <div>
            <label for="symbol" class="form-label">Symbol</label>
            <input id="symbol" type="text" wire:model="symbol" class="form-input uppercase" maxlength="10" autocomplete="off">
            @error('symbol') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn-primary" wire:loading.attr="disabled">Look up</button>
        @if ($this->quote)
            <button type="button" wire:click="refresh" wire:loading.attr="disabled" class="btn-secondary">Refresh</button>
        @endif
    </form>

    @if ($this->quote && ! $this->quote->isAvailable())
        <x-alert level="warning" title="No quote for {{ $this->symbol }}">{{ $this->quote->unavailableReason }}</x-alert>
    @elseif ($this->quote)
        @php
            $data = $this->quote->data;
            $feed = $this->quote->meta['feed'] ?? [];
            $asOf = AlpacaFormat::timestamp($data['asOf'] ?? null);
        @endphp

        <section class="flex flex-col gap-3" aria-labelledby="alpaca-quote">
            <div class="flex flex-wrap items-center gap-2">
                <h2 id="alpaca-quote" class="font-semibold">{{ $data['symbol'] }}</h2>
                <x-badge :tone="match ($data['dataStatus']) { 'real-time' => 'green', 'stale', 'delayed' => 'amber', default => 'gray' }">{{ ucfirst($data['dataStatus']) }}</x-badge>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-stat-card label="Last price" icon="tag">{{ AlpacaFormat::price($data['price'] ?? null)?->format() ?? '—' }}</x-stat-card>
                <x-stat-card label="Bid">{{ AlpacaFormat::price($data['bid'] ?? null)?->format() ?? '—' }}</x-stat-card>
                <x-stat-card label="Ask">{{ AlpacaFormat::price($data['ask'] ?? null)?->format() ?? '—' }}</x-stat-card>
                <x-stat-card label="Previous close">{{ AlpacaFormat::price($data['previousClose'] ?? null)?->format() ?? '—' }}</x-stat-card>
            </div>
            @if ($asOf)
                <p class="text-sm text-zinc-600 dark:text-zinc-400">Price as of {{ $asOf->timezone($this->displayTimezone)->format('M j, Y g:i:s A T') }}.</p>
            @endif
            @include('partials.alpaca-source', [
                'result' => $this->quote,
                'source' => 'Alpaca, '.($feed['label'] ?? 'unknown').' feed'.(isset($feed['coverage']) ? ' ('.$feed['coverage'].')' : ''),
                'timezone' => $this->displayTimezone,
            ])
        </section>
    @endif
</div>
