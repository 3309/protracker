<?php

use App\Livewire\ReportPage;
use App\Services\AlpacaService;
use App\Support\Alpaca\AlpacaResult;
use App\Support\Alpaca\OptionChainPage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new #[Title('Alpaca option chain')] class extends ReportPage
{
    /** The value of $expiration that asks for every expiration, nearest first, in batches. */
    public const string ALL_EXPIRATIONS = 'all';

    private const string SYMBOL_MESSAGE = 'Enter one symbol of up to 10 letters, digits, dots or dashes, starting with a letter.';

    #[Url]
    public string $symbol = '';

    /** "call" or "put", or blank for both. */
    #[Url]
    public string $type = '';

    /** A date, or ALL_EXPIRATIONS. Blank means none is chosen yet, so no chain is loaded. */
    #[Url]
    public string $expiration = '';

    /** Where "Show later expirations" has reached, or blank for the nearest. */
    #[Url(as: 'expiring-from')]
    public string $fromExpiration = '';

    /**
     * Another symbol's expiration dates do not apply to this one.
     */
    public function updatedSymbol(): void
    {
        $this->symbol = strtoupper(trim($this->symbol));
        $this->expiration = '';
        $this->fromExpiration = '';
    }

    /**
     * Find the symbol's expiration dates, ready to choose one.
     */
    public function lookup(): void
    {
        $this->symbol = strtoupper(trim($this->symbol));
        $this->expiration = '';
        $this->fromExpiration = '';

        $this->validate(
            ['symbol' => ['required', 'regex:'.AlpacaService::SYMBOL_PATTERN]],
            ['symbol.required' => 'Enter a stock symbol.', 'symbol.regex' => self::SYMBOL_MESSAGE],
        );
    }

    /**
     * Load the chain for the expiration and type chosen.
     */
    public function loadChain(): void
    {
        $this->fromExpiration = '';

        $this->validateFilters(['required']);
    }

    /**
     * Fetch what is on the page again, skipping any saved copies.
     */
    public function refresh(): void
    {
        $this->validateFilters(['nullable']);

        $service = app(AlpacaService::class);
        $service->forgetOptionExpirations($this->symbol);

        if ($this->expiration !== '') {
            $service->forgetOptionChain($this->symbol, $this->type, $this->chosenDate(), $this->fromExpiration);
        }
    }

    /**
     * @param  list<string>  $expirationRules
     */
    private function validateFilters(array $expirationRules): void
    {
        $this->symbol = strtoupper(trim($this->symbol));

        $this->validate(
            [
                'symbol' => ['required', 'regex:'.AlpacaService::SYMBOL_PATTERN],
                'type' => ['in:,call,put'],
                'expiration' => [...$expirationRules, 'regex:/^(all|\d{4}-\d{2}-\d{2})$/'],
                'fromExpiration' => ['nullable', 'date_format:Y-m-d'],
            ],
            [
                'symbol.required' => 'Enter a stock symbol.',
                'symbol.regex' => self::SYMBOL_MESSAGE,
                'expiration.required' => 'Choose an expiration.',
                'expiration.regex' => 'Choose an expiration from the list.',
            ],
        );
    }

    /**
     * The one date asked for, or blank when asking across expirations.
     */
    private function chosenDate(): string
    {
        return $this->expiration === self::ALL_EXPIRATIONS ? '' : $this->expiration;
    }

    /**
     * Null until there is a valid symbol to look up.
     */
    #[Computed]
    public function expirationDates(): ?AlpacaResult
    {
        if ($this->symbol === '' || $this->getErrorBag()->has('symbol')) {
            return null;
        }

        return AlpacaResult::attempt(fn () => app(AlpacaService::class)->optionExpirations($this->symbol));
    }

    /**
     * Null until an expiration is chosen, so a whole chain is never loaded by
     * accident. Kept out of the public properties, so a long chain is not sent
     * back and forth with the page.
     */
    #[Computed]
    public function chain(): ?AlpacaResult
    {
        if ($this->symbol === '' || $this->expiration === '' || $this->getErrorBag()->isNotEmpty()) {
            return null;
        }

        return AlpacaResult::attempt(fn () => app(AlpacaService::class)->optionChain($this->symbol, $this->type, $this->chosenDate(), $this->fromExpiration));
    }

    #[Computed]
    public function chainPage(): ?OptionChainPage
    {
        return $this->chain?->isAvailable() ? OptionChainPage::from($this->chain, $this->expiration === self::ALL_EXPIRATIONS) : null;
    }

    /**
     * What the page says it is showing, which never calls a partial chain whole.
     *
     * @return array{level: string, title: string|null, text: string}
     */
    public function chainSummary(): array
    {
        $page = $this->chainPage;
        $count = number_format($page->contractCount).' '.($page->contractCount === 1 ? 'contract' : 'contracts');
        $first = $this->dateLabel($page->firstExpiration);
        $last = $this->dateLabel($page->lastExpiration);
        $range = $first === $last ? $first : "{$first} to {$last}";

        return match (true) {
            $page->isPartial && $this->expiration === self::ALL_EXPIRATIONS => ['level' => 'warning', 'title' => 'Only part of this expiration is shown', 'text' => "Showing {$count} for {$range}. More exist, so some strikes are missing. Choose this date from the list to load it whole, or use Show later expirations to move on."],
            $page->isPartial => ['level' => 'warning', 'title' => 'Only part of this expiration is shown', 'text' => "Showing {$count} for {$range}. More exist, so some strikes are missing. Choose only calls or only puts to narrow the list."],
            $page->nextFrom !== null => ['level' => 'warning', 'title' => 'Only part of the chain is shown', 'text' => "Showing {$count} expiring {$range}. More are available: use Show later expirations, or choose one expiration from the list."],
            ! $page->isComplete => ['level' => 'warning', 'title' => 'This may not be the whole chain', 'text' => "Showing {$count} for {$range}. Alpaca did not say whether more are available."],
            $this->fromExpiration !== '' => ['level' => 'info', 'title' => null, 'text' => "Showing {$count} expiring {$range}. No later expirations are available. Earlier ones are not shown here."],
            default => ['level' => 'info', 'title' => null, 'text' => "Showing all {$count} for {$range}. No more are available for this search."],
        };
    }

    public function dateLabel(?string $date): string
    {
        return App\Support\Alpaca\AlpacaFormat::timestamp($date)?->format('M j, Y') ?? 'an unknown date';
    }
};
?>

@use('App\Support\Alpaca\AlpacaFormat')

@php
    $sides = match ($this->type) {
        'call' => ['call' => 'Call'],
        'put' => ['put' => 'Put'],
        default => ['call' => 'Call', 'put' => 'Put'],
    };
    $dates = $this->expirationDates?->isAvailable() ? $this->expirationDates->data : [];

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->expiration) === 1 && ! in_array($this->expiration, $dates, true)) {
        $dates[] = $this->expiration;
        sort($dates);
    }
@endphp

<div class="flex flex-col gap-6">
    <div>
        <h1 class="page-title">Alpaca option chain</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">Enter a symbol to see its expiration dates, then choose one to load its contracts.</p>
    </div>

    <form wire:submit="lookup" class="card flex flex-wrap items-end gap-4 p-4">
        <div>
            <label for="symbol" class="form-label">Symbol</label>
            <input id="symbol" type="text" wire:model="symbol" class="form-input uppercase" maxlength="10" autocomplete="off">
            @error('symbol') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn-primary" wire:loading.attr="disabled">Find expirations</button>
        @if ($this->expirationDates)
            <button type="button" wire:click="refresh" wire:loading.attr="disabled" class="btn-secondary">Refresh</button>
        @endif
    </form>

    @if ($this->expirationDates && ! $this->expirationDates->isAvailable())
        <x-alert level="warning" title="No expiration dates for {{ $this->symbol }}">{{ $this->expirationDates->unavailableReason }}</x-alert>
    @elseif ($this->expirationDates)
        @if (($this->expirationDates->meta['truncated'] ?? null) !== false)
            <x-alert level="warning" title="This list of dates may be incomplete">Later expiration dates may be missing, because this chain was too long to read whole. All expirations, nearest first, can still reach them.</x-alert>
        @endif

        @if ($this->expirationDates->isEmpty())
            <x-empty-state>Alpaca lists no option expirations for {{ $this->symbol }}.</x-empty-state>
        @else
            <form wire:submit="loadChain" class="card flex flex-wrap items-end gap-4 p-4">
                <div>
                    <label for="expiration" class="form-label">Expiration</label>
                    <select id="expiration" wire:model="expiration" class="form-input">
                        <option value="">Choose an expiration</option>
                        @foreach ($dates as $date)
                            <option value="{{ $date }}" wire:key="date-{{ $date }}">{{ $this->dateLabel($date) }}</option>
                        @endforeach
                        <option value="all">All expirations, nearest first</option>
                    </select>
                    @error('expiration') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="type" class="form-label">Show</label>
                    <select id="type" wire:model="type" class="form-input">
                        <option value="">Calls and puts</option>
                        <option value="call">Calls</option>
                        <option value="put">Puts</option>
                    </select>
                    @error('type') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn-primary" wire:loading.attr="disabled">Load chain</button>
            </form>
            @include('partials.alpaca-source', [
                'result' => $this->expirationDates,
                'source' => count($this->expirationDates->data).' expiration dates from Alpaca',
                'timezone' => $this->displayTimezone,
            ])
        @endif
    @endif

    @error('fromExpiration') <x-alert level="warning" title="This page's address is not valid">{{ $message }}</x-alert> @enderror

    @if ($this->chain && ! $this->chain->isAvailable())
        <x-alert level="warning" title="No option chain for {{ $this->symbol }}">{{ $this->chain->unavailableReason }}</x-alert>
    @elseif ($this->chain)
        @php
            $feed = $this->chain->meta['feed'] ?? [];
            $chainPage = $this->chainPage;
            $summary = $this->chainSummary();
            $columns = count($sides) === 2 ? ['Bid', 'Ask', 'Last', 'IV', 'Delta'] : ['Bid', 'Ask', 'Last', 'IV', 'Delta', 'Gamma', 'Theta', 'Vega', 'Rho'];
        @endphp

        @if (($feed['feed'] ?? null) === 'indicative')
            <x-alert level="info" title="These are estimates">Indicative prices are estimates, not actual OPRA quotes, and trades are delayed.</x-alert>
        @endif

        @if ($this->chain->isEmpty())
            <x-empty-state>Alpaca returned no contracts for this search.</x-empty-state>
        @else
            <x-alert :level="$summary['level']" :title="$summary['title']">{{ $summary['text'] }}</x-alert>

            @if ($chainPage->unclassifiedCount > 0)
                <x-alert level="warning" title="Some contracts are not shown">{{ $chainPage->unclassifiedCount }} contract(s) did not say whether they are a call or a put, so they are left out.</x-alert>
            @endif

            @foreach ($chainPage->groups as $group)
                <section class="flex flex-col gap-3" wire:key="expiration-{{ $group['expiration'] ?? 'unknown' }}" aria-label="{{ $group['expiration'] ? $this->dateLabel($group['expiration']) : 'Expiration not given' }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-semibold">{{ $group['expiration'] ? $this->dateLabel($group['expiration']) : 'Expiration not given' }}</h2>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ number_format($group['contractCount']) }} {{ $group['contractCount'] === 1 ? 'contract' : 'contracts' }}</span>
                        @foreach ($group['statuses'] as $status)
                            <x-badge :tone="match ($status) { 'real-time' => 'green', 'stale', 'delayed' => 'amber', default => 'gray' }">{{ ucfirst($status) }}</x-badge>
                        @endforeach
                    </div>

                    <div class="card hidden overflow-x-auto p-0 xl:block" data-chain-table>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    @foreach ($sides as $label)
                                        @if ($label === 'Put')
                                            <th rowspan="{{ count($sides) === 2 ? 2 : 1 }}" class="bg-zinc-50 text-center dark:bg-zinc-800/50">Strike</th>
                                        @endif
                                        @if (count($sides) === 2)
                                            <th colspan="{{ count($columns) }}" class="text-center">{{ $label }}s</th>
                                        @endif
                                        @if (count($sides) === 1 && $label === 'Call')
                                            <th class="bg-zinc-50 text-center dark:bg-zinc-800/50">Strike</th>
                                        @endif
                                        @if (count($sides) === 1)
                                            @foreach ($columns as $column)
                                                <th class="text-right">{{ $column }}</th>
                                            @endforeach
                                            <th>Contract</th>
                                        @endif
                                    @endforeach
                                </tr>
                                @if (count($sides) === 2)
                                    <tr>
                                        @foreach ($sides as $side => $label)
                                            @foreach ($columns as $column)
                                                <th class="text-right">{{ $column }}</th>
                                            @endforeach
                                        @endforeach
                                    </tr>
                                @endif
                            </thead>
                            <tbody>
                                @foreach ($group['rows'] as $row)
                                    <tr wire:key="row-{{ $group['expiration'] }}-{{ $loop->index }}">
                                        @if (count($sides) === 1)
                                            <td class="num bg-zinc-50 text-center font-semibold dark:bg-zinc-800/50">{{ $row['strike']?->format() ?? '—' }}</td>
                                        @endif
                                        @foreach ($sides as $side => $label)
                                            @if (count($sides) === 2 && $side === 'put')
                                                <td class="num bg-zinc-50 text-center font-semibold dark:bg-zinc-800/50">{{ $row['strike']?->format() ?? '—' }}</td>
                                            @endif
                                            @php($figures = $row[$side] ? AlpacaFormat::contractFigures($row[$side]) : [])
                                            @foreach ($columns as $column)
                                                <td class="num" @if ($loop->first && $row[$side]) title="{{ $row[$side]['symbol'] ?? '' }}" @endif>{{ $figures[$column] ?? '—' }}</td>
                                            @endforeach
                                            @if (count($sides) === 1)
                                                <td class="text-xs text-zinc-500 dark:text-zinc-400">{{ $row[$side]['symbol'] ?? '—' }}</td>
                                            @endif
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <ul class="flex flex-col gap-3 xl:hidden" data-chain-cards>
                        @foreach ($group['rows'] as $row)
                            <li class="card flex flex-col gap-3 p-4" wire:key="card-{{ $group['expiration'] }}-{{ $loop->index }}">
                                <div class="flex items-baseline justify-between gap-2">
                                    <span class="text-lg font-semibold tabular-nums">{{ $row['strike']?->format() ?? 'Strike not given' }}</span>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $group['expiration'] ? $this->dateLabel($group['expiration']) : 'Expiration not given' }}</span>
                                </div>
                                <div @class(['grid gap-4', 'grid-cols-2' => count($sides) === 2])>
                                    @foreach ($sides as $side => $label)
                                        @include('partials.alpaca-chain-side', ['contract' => $row[$side], 'label' => $label])
                                    @endforeach
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        @endif

        @if ($chainPage->nextFrom || $this->fromExpiration !== '')
            <div class="flex flex-wrap gap-3">
                @if ($this->fromExpiration !== '')
                    <button type="button" wire:click="$set('fromExpiration', '')" wire:loading.attr="disabled" class="btn-secondary">Back to the nearest expirations</button>
                @endif
                @if ($chainPage->nextFrom)
                    <button type="button" wire:click="$set('fromExpiration', '{{ $chainPage->nextFrom }}')" wire:loading.attr="disabled" class="btn-secondary">Show later expirations</button>
                @endif
            </div>
        @endif

        @include('partials.alpaca-source', [
            'result' => $this->chain,
            'source' => 'Alpaca, '.($feed['label'] ?? 'unknown').' feed',
            'timezone' => $this->displayTimezone,
        ])
    @endif
</div>
