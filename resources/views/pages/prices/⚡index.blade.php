<?php

use App\Enums\OptionType;
use App\Enums\PriceInstrument;
use App\Livewire\Forms\PriceForm;
use App\Livewire\ReportPage;
use App\Models\OptionPosition;
use App\Models\Price;
use App\Support\OpenPositionsReport;
use App\Support\PriceBook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('Prices')] class extends ReportPage
{
    public PriceForm $form;

    public function mount(): void
    {
        $this->form->as_of_date = $this->today()->format('Y-m-d');
    }

    public function enterStockPrice(string $ticker): void
    {
        $this->form->prepareFor(PriceInstrument::Stock, $ticker);
    }

    public function enterOptionPrice(int $optionPositionId): void
    {
        $position = OptionPosition::findOrFail($optionPositionId);

        $this->form->prepareFor(PriceInstrument::Option, $position->underlying, $position->option_type, $position->strike, $position->expires_on->format('Y-m-d'));
    }

    public function save(): void
    {
        $price = $this->form->store();

        unset($this->prices, $this->openPositions, $this->recentPrices);

        session()->now('message', "Price for {$price->description()} saved: {$price->price->format()}.");
    }

    #[Computed]
    public function prices(): PriceBook
    {
        return PriceBook::load($this->today());
    }

    #[Computed]
    public function openPositions(): OpenPositionsReport
    {
        return OpenPositionsReport::build(null, null, $this->prices, $this->today());
    }

    /**
     * Every ticker held in any account, once each: stock prices are shared by all accounts.
     *
     * @return BaseCollection<int, string>
     */
    #[Computed]
    public function heldTickers(): BaseCollection
    {
        return $this->openPositions->stocks->pluck('ticker')->unique()->sort()->values();
    }

    /**
     * Every open option contract, once each.
     *
     * @return BaseCollection<int, OptionPosition>
     */
    #[Computed]
    public function openContracts(): BaseCollection
    {
        return $this->openPositions->options
            ->map(fn (array $row) => $row['position'])
            ->unique(fn (OptionPosition $position) => Price::identityKey(PriceInstrument::Option, $position->underlying, $position->option_type, $position->strike, $position->expires_on->format('Y-m-d')))
            ->values();
    }

    /**
     * @return Collection<int, Price>
     */
    #[Computed]
    public function recentPrices(): Collection
    {
        return Price::query()->latest('as_of')->latest('id')->limit(50)->get();
    }
};
?>

<div class="flex flex-col gap-6">
    <div>
        <h1 class="page-title">Prices</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Prices you enter by hand, and stock prices fetched with "Refresh Stock Prices" on the dashboard, used to estimate unrealized profit. They never change realized profit.
            A price older than {{ $this->prices->staleAfterDays() }} days is marked stale (you can change this in Settings).
        </p>
    </div>

    @if (session('message'))
        <x-alert level="success">{{ session('message') }}</x-alert>
    @endif

    <form wire:submit="save" class="card flex flex-col gap-4">
        <h2 class="section-title">Enter a price</h2>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="instrument" class="form-label required">For</label>
                <select id="instrument" wire:model.live="form.instrument" class="form-input">
                    @foreach (PriceInstrument::cases() as $instrument)
                        <option value="{{ $instrument->value }}">{{ $instrument->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="price_ticker" class="form-label required">Ticker</label>
                <input id="price_ticker" type="text" wire:model="form.ticker" class="form-input uppercase">
                @error('form.ticker') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="price_price" class="form-label required">{{ $form->isOption() ? 'Option price per share' : 'Price per share' }}</label>
                <input id="price_price" type="text" inputmode="decimal" wire:model="form.price" class="form-input" placeholder="e.g. 52.10">
                @error('form.price') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            @if ($form->isOption())
                <div>
                    <label for="price_option_type" class="form-label">Put or call</label>
                    <select id="price_option_type" wire:model="form.option_type" class="form-input">
                        <option value="">Choose…</option>
                        @foreach (OptionType::cases() as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    @error('form.option_type') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="price_strike" class="form-label">Strike</label>
                    <input id="price_strike" type="text" inputmode="decimal" wire:model="form.strike" class="form-input">
                    @error('form.strike') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="price_expires_on" class="form-label">Expiration date</label>
                    <input id="price_expires_on" type="date" wire:model="form.expires_on" class="form-input">
                    @error('form.expires_on') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <div>
                <label for="price_as_of_date" class="form-label required">As of date</label>
                <input id="price_as_of_date" type="date" wire:model="form.as_of_date" class="form-input">
                @error('form.as_of_date') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="price_as_of_time" class="form-label">Time (optional)</label>
                <input id="price_as_of_time" type="time" wire:model="form.as_of_time" class="form-input">
                <p class="form-help">Blank means the 4:00 PM market close, {{ $this->prices->displayTimezone() }}.</p>
                @error('form.as_of_time') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="price_note" class="form-label">Note (optional)</label>
                <input id="price_note" type="text" wire:model="form.reference_note" class="form-input" placeholder="e.g. Fidelity quote">
            </div>
        </div>

        <div>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save price</button>
        </div>
    </form>

    <section class="card flex flex-col gap-3">
        <h2 class="section-title">Stocks you hold</h2>
        @if ($this->heldTickers->isEmpty())
            <p class="text-sm text-zinc-500">No shares held.</p>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Ticker</th>
                        <th class="text-right">Latest price</th>
                        <th>As of</th>
                        <th>Status</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->heldTickers as $heldTicker)
                        @php $latest = $this->prices->forStock($heldTicker); @endphp
                        <tr wire:key="stock-price-{{ $heldTicker }}">
                            <td class="font-medium">{{ $heldTicker }}</td>
                            <td class="num">{{ $latest?->price->format() ?? '—' }}</td>
                            <td>{{ $latest?->as_of->timezone($this->prices->displayTimezone())->format('M j, Y g:i A') ?? '—' }}</td>
                            <td>@include('partials.price-status', ['latest' => $latest])</td>
                            <td class="py-2 text-right"><button type="button" wire:click="enterStockPrice('{{ $heldTicker }}')" class="link">Enter price</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="card flex flex-col gap-3">
        <h2 class="section-title">Open option contracts</h2>
        @if ($this->openContracts->isEmpty())
            <p class="text-sm text-zinc-500">No open options.</p>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Contract</th>
                        <th class="text-right">Latest price</th>
                        <th>As of</th>
                        <th>Status</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->openContracts as $contract)
                        @php $latest = $this->prices->forOption($contract); @endphp
                        <tr wire:key="option-price-{{ $contract->id }}">
                            <td class="font-medium">{{ $contract->description() }}</td>
                            <td class="num">{{ $latest?->price->format() ?? '—' }}</td>
                            <td>{{ $latest?->as_of->timezone($this->prices->displayTimezone())->format('M j, Y g:i A') ?? '—' }}</td>
                            <td>@include('partials.price-status', ['latest' => $latest])</td>
                            <td class="py-2 text-right"><button type="button" wire:click="enterOptionPrice({{ $contract->id }})" class="link">Enter price</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="card flex flex-col gap-3">
        <h2 class="section-title">Price history</h2>
        @if ($this->recentPrices->isEmpty())
            <p class="text-sm text-zinc-500">No prices entered yet.</p>
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>As of</th>
                        <th>For</th>
                        <th class="text-right">Price</th>
                        <th>Source</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->recentPrices as $entry)
                        <tr wire:key="price-{{ $entry->id }}" @class(['text-zinc-400 line-through dark:text-zinc-500' => $entry->isVoided()])>
                            <td>{{ $entry->as_of->timezone($this->prices->displayTimezone())->format('M j, Y g:i A') }}</td>
                            <td>{{ $entry->description() }}</td>
                            <td class="num">{{ $entry->price->format() }}</td>
                            <td>{{ $entry->source->label() }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('prices.edit', $entry) }}" class="link" wire:navigate>{{ $entry->isVoided() ? 'Voided' : 'Void' }}</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
