<?php

use App\Enums\OptionSide;
use App\Enums\OptionType;
use App\Livewire\ReportPage;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Options')] class extends ReportPage
{
    /** "open", "closed" or "all" (which also shows voided positions). */
    #[Url]
    public string $status = 'open';

    /** "put", "call" or blank for both. */
    #[Url]
    public string $type = '';

    /** "short" (sold), "long" (bought) or blank for both. */
    #[Url]
    public string $side = '';

    /** An expiration month such as "2026-03", or blank for all. */
    #[Url]
    public string $expiry = '';

    /**
     * @return Collection<int, OptionPosition>
     */
    #[Computed]
    public function positions(): Collection
    {
        $query = OptionPosition::query()->with('account')->withClosedContracts();

        $this->applyAccountFilter($query);
        $this->applyTickerFilter($query, 'underlying');

        if (OptionType::tryFrom($this->type) !== null) {
            $query->where('option_type', $this->type);
        }

        if (OptionSide::tryFrom($this->side) !== null) {
            $query->where('side', $this->side);
        }

        if (preg_match('/^\d{4}-\d{2}$/', $this->expiry) === 1) {
            $query->where('expires_on', 'like', $this->expiry.'-%');
        }

        if ($this->status === 'closed') {
            $query->notVoided()->closed();
        } elseif ($this->status !== 'all') {
            $query->notVoided()->open();
        }

        return $query->orderBy('expires_on')->orderBy('underlying')->orderBy('id')->get();
    }

    /**
     * The months in which recorded options expire, for the expiration filter.
     *
     * @return list<string>
     */
    #[Computed]
    public function expiryMonths(): array
    {
        return OptionPosition::query()
            ->pluck('expires_on')
            ->map(fn ($date) => substr((string) $date, 0, 7))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The positions shown, one row each, as a CSV file.
     */
    public function export(): StreamedResponse
    {
        return CsvExport::download('option-positions', [
            'option', 'account', 'underlying', 'side', 'type', 'strike', 'expires_on', 'opened_on', 'origin', 'contracts',
            'open_contracts', 'multiplier', 'premium_per_share', 'premium', 'opening_fees', 'voided', 'note',
        ], $this->positions->map(fn (OptionPosition $position) => [
            $position->description(),
            $position->account->displayName(),
            $position->underlying,
            $position->side,
            $position->option_type,
            $position->strike,
            $position->expires_on,
            $position->opened_on,
            $position->origin->label(),
            $position->contracts,
            $position->openContracts(),
            $position->multiplier,
            $position->premium_per_share,
            $position->premiumReceived(),
            $position->opening_fees,
            $position->isVoided(),
            $position->reference_note,
        ])->all());
    }

    /**
     * Every event (buy or sell to close, expiry, assignment, roll) of the positions
     * shown, as a CSV file.
     */
    public function exportEvents(): StreamedResponse
    {
        $events = OptionEvent::query()
            ->with(['position', 'account'])
            ->whereIn('option_position_id', $this->positions->modelKeys())
            ->orderBy('event_date')
            ->orderBy('id')
            ->get();

        return CsvExport::download('option-events', [
            'event_date', 'settlement_date', 'account', 'option', 'event', 'contracts', 'price_per_share', 'fees',
            'opening_premium', 'closing_amount', 'gross_pl', 'net_pl', 'voided', 'note',
        ], $events->map(fn (OptionEvent $event) => [
            $event->event_date,
            $event->settlement_date,
            $event->account->displayName(),
            $event->position->description(),
            $event->event_type->label(),
            $event->contracts,
            $event->price_per_share,
            $event->fees,
            $event->allocated_premium,
            $event->closingCost(),
            $event->grossProfit(),
            $event->netProfit(),
            $event->isVoided(),
            $event->reference_note,
        ])->all());
    }

    public function isPastExpiration(OptionPosition $position): bool
    {
        return $position->isPastExpiration($this->today());
    }

    #[Computed]
    public function pastExpirationCount(): int
    {
        return $this->positions->filter(fn (OptionPosition $position) => $this->isPastExpiration($position))->count();
    }

    /**
     * Calendar days from today until expiration; negative once expired.
     */
    public function daysToExpiry(OptionPosition $position): int
    {
        return (int) $this->today()->diffInDays($position->expires_on, false);
    }
};
?>

<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">Options</h1>
        <div class="flex flex-wrap gap-3">
            <button type="button" wire:click="export" class="btn-secondary">Download positions CSV</button>
            <button type="button" wire:click="exportEvents" class="btn-secondary">Download events CSV</button>
            <a href="{{ route('options.create') }}" class="btn-primary" wire:navigate>Sell to open</a>
            <details class="relative">
                <summary class="btn-primary cursor-pointer list-none">Buy option</summary>
                <div class="absolute right-0 z-10 mt-2 flex w-40 flex-col rounded-md border border-zinc-200 bg-white py-1 text-sm shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                    <a href="{{ route('options.buy', ['type' => 'call']) }}" class="px-4 py-2 hover:bg-zinc-100 dark:hover:bg-zinc-800" wire:navigate>Buy call</a>
                    <a href="{{ route('options.buy', ['type' => 'put']) }}" class="px-4 py-2 hover:bg-zinc-100 dark:hover:bg-zinc-800" wire:navigate>Buy put</a>
                </div>
            </details>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <div class="grow">
            @include('partials.report-filters', ['showPeriod' => false])
        </div>
        <div class="card">
            <label for="status" class="form-label">Show</label>
            <select id="status" wire:model.live="status" class="form-input">
                <option value="open">Open</option>
                <option value="closed">Closed</option>
                <option value="all">All, including voided</option>
            </select>
        </div>
        <div class="card flex flex-wrap gap-4">
            <div>
                <label for="filter_type" class="form-label">Call or put</label>
                <select id="filter_type" wire:model.live="type" class="form-input">
                    <option value="">Both</option>
                    <option value="call">Calls</option>
                    <option value="put">Puts</option>
                </select>
            </div>
            <div>
                <label for="filter_side" class="form-label">Sold or bought</label>
                <select id="filter_side" wire:model.live="side" class="form-input">
                    <option value="">Both</option>
                    <option value="short">Sold (short)</option>
                    <option value="long">Bought (long)</option>
                </select>
            </div>
            <div>
                <label for="filter_expiry" class="form-label">Expiration month</label>
                <select id="filter_expiry" wire:model.live="expiry" class="form-input">
                    <option value="">All</option>
                    @foreach ($this->expiryMonths as $month)
                        <option value="{{ $month }}" wire:key="expiry-{{ $month }}">{{ Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)?->format('M Y') }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    @if ($this->pastExpirationCount > 0)
        <div class="card border-amber-300 text-sm text-amber-800 dark:border-amber-700 dark:text-amber-300">
            {{ $this->pastExpirationCount }} {{ Str::plural('option', $this->pastExpirationCount) }} still open after the expiration date.
            Open each one and record whether it expired worthless, was assigned or (for a bought option) was sold. Nothing is closed automatically.
        </div>
    @endif

    @if ($this->positions->isEmpty())
        <div class="card">No option positions to show.</div>
    @else
        <div class="card overflow-x-auto p-0">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">Option</th>
                        <th class="px-4 py-3 font-medium">Side</th>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Opened</th>
                        <th class="px-4 py-3 text-right font-medium">Open / total</th>
                        <th class="px-4 py-3 text-right font-medium">Premium received / paid</th>
                        <th class="px-4 py-3 text-right font-medium">Opening fees</th>
                        <th class="px-4 py-3 text-right font-medium">Days to expiry</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->positions as $position)
                        <tr wire:key="option-{{ $position->id }}" @class(['text-zinc-400 line-through dark:text-zinc-500' => $position->isVoided()])>
                            <td class="px-4 py-3 font-medium">{{ $position->description() }}</td>
                            <td class="px-4 py-3">{{ $position->isLong() ? 'Long '.strtolower($position->option_type->label()) : 'Short '.strtolower($position->option_type->label()) }}</td>
                            <td class="px-4 py-3">{{ $position->account->displayName() }}</td>
                            <td class="px-4 py-3">
                                {{ $position->opened_on->format('M j, Y') }}
                                <span class="text-xs text-zinc-500">{{ $position->origin->label() }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $position->openContracts() }} / {{ $position->contracts }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $position->premiumReceived()->format() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $position->opening_fees->format() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                @if ($position->isVoided())
                                    Voided
                                @elseif ($this->isPastExpiration($position))
                                    <span class="font-medium text-amber-700 dark:text-amber-400">Past expiration — record {{ $position->isLong() ? 'the expiry' : 'expiry or assignment' }}</span>
                                @elseif ($position->isOpen())
                                    {{ $this->daysToExpiry($position) }}
                                @else
                                    Closed
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('options.show', $position) }}" class="underline underline-offset-4" wire:navigate>View</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
