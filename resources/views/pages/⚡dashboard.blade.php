<?php

use App\Actions\Cash\BuildCashLedger;
use App\Actions\Wheel\BuildWheelCycles;
use App\Enums\OpeningStatus;
use App\Enums\PositionOrigin;
use App\Livewire\ReportPage;
use App\Models\Account;
use App\Models\BrokerBalance;
use App\Models\OptionPosition;
use App\Models\Setting;
use App\Support\CsvExport;
use App\Support\DataQualityAlerts;
use App\Support\FeeReport;
use App\Support\Money;
use App\Support\OpenPositionsReport;
use App\Support\PriceBook;
use App\Support\RealizedProfit;
use App\Support\WheelCycle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Dashboard')] class extends ReportPage
{
    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function activeAccounts(): Collection
    {
        return Account::query()->active()->withOpeningProgress()->get();
    }

    #[Computed]
    public function completedOpeningCount(): int
    {
        return $this->activeAccounts->filter(fn (Account $account) => $account->openingStatus() === OpeningStatus::Complete)->count();
    }

    #[Computed]
    public function settings(): Setting
    {
        return Setting::current();
    }

    #[Computed]
    public function todayDate(): CarbonImmutable
    {
        return $this->today();
    }

    #[Computed]
    public function prices(): PriceBook
    {
        return PriceBook::load($this->today());
    }

    #[Computed]
    public function open(): OpenPositionsReport
    {
        return OpenPositionsReport::build($this->filteredAccountIds(), $this->tickerFilter(), $this->prices, $this->today());
    }

    #[Computed]
    public function realized(): ?RealizedProfit
    {
        return $this->reportingPeriod === null ? null : RealizedProfit::for($this->reportingPeriod, $this->filteredAccountIds(), $this->tickerFilter());
    }

    /**
     * Sell-to-open premium by trade month, beside realized option P/L by
     * close month.
     *
     * @return BaseCollection<string, array{premium: Money, realized: Money}>
     */
    #[Computed]
    public function premiumByMonth(): BaseCollection
    {
        if ($this->reportingPeriod === null || $this->realized === null) {
            return collect();
        }

        $sales = OptionPosition::query()->notVoided()->where('origin', '!=', PositionOrigin::OpeningBalance);
        $this->applyAccountFilter($sales);
        $this->applyTickerFilter($sales, 'underlying');
        $this->reportingPeriod->applyTo($sales, 'opened_on');

        $months = [];

        foreach ($sales->get() as $sale) {
            $month = $sale->opened_on->format('Y-m');
            $months[$month] ??= ['premium' => Money::zero(), 'realized' => Money::zero()];
            $months[$month]['premium'] = $months[$month]['premium']->plus($sale->premiumReceived());
        }

        foreach ($this->realized->optionCloses as $close) {
            $month = $close->event_date->format('Y-m');
            $months[$month] ??= ['premium' => Money::zero(), 'realized' => Money::zero()];
            $months[$month]['realized'] = $months[$month]['realized']->plus($close->netProfit());
        }

        ksort($months);

        return collect($months);
    }

    #[Computed]
    public function fees(): ?FeeReport
    {
        return $this->reportingPeriod === null ? null : FeeReport::for($this->reportingPeriod, $this->filteredAccountIds(), $this->tickerFilter());
    }

    /**
     * Every fee in the period, one row each, as a CSV file.
     */
    public function exportFees(): ?StreamedResponse
    {
        if ($this->fees === null) {
            return null;
        }

        return CsvExport::download('fees', ['date', 'account', 'ticker', 'type', 'amount'], [
            ...$this->fees->items->sortBy('date')->map(fn (array $item) => [$item['date'], $item['accountName'], $item['ticker'], $item['type'], $item['amount']])->all(),
            ['Total', null, null, null, $this->fees->total()],
        ]);
    }

    /**
     * Wheel cycles that overlap the selected period, newest first.
     *
     * @return BaseCollection<int, WheelCycle>
     */
    #[Computed]
    public function cycles(): BaseCollection
    {
        $period = $this->reportingPeriod;

        return app(BuildWheelCycles::class)->handle($this->filteredAccountIds(), $this->tickerFilter())
            ->filter(fn (WheelCycle $cycle) => $period === null
                || ($cycle->start->format('Y-m-d') <= $period->end->format('Y-m-d')
                    && ($cycle->end === null || $cycle->end->format('Y-m-d') >= $period->start->format('Y-m-d'))))
            ->reverse()
            ->values();
    }

    /**
     * Cash today and the latest broker balance for each account.
     *
     * @return BaseCollection<int, array{account: Account, total: Money, settled: Money, broker: BrokerBalance|null, difference: Money|null}>
     */
    #[Computed]
    public function cash(): BaseCollection
    {
        $accounts = Account::query()->active()->orderBy('nickname');
        $this->applyAccountFilter($accounts, 'id');

        return $accounts->get()->map(function (Account $account) {
            $ledger = app(BuildCashLedger::class)->handle($account->id);
            $broker = BrokerBalance::query()->where('account_id', $account->id)->notVoided()
                ->where('as_of_date', '<=', $this->today()->format('Y-m-d'))
                ->orderByDesc('as_of_date')->orderByDesc('id')->first();

            return [
                'account' => $account,
                'total' => $ledger->totalOn($this->today()),
                'settled' => $ledger->settledOn($this->today()),
                'broker' => $broker,
                'difference' => $broker?->total_cash->minus($ledger->totalOn($broker->as_of_date)),
            ];
        })->toBase();
    }

    /**
     * @return list<array{message: string, url: string}>
     */
    #[Computed]
    public function alerts(): array
    {
        return DataQualityAlerts::collect($this->open, $this->today(), $this->filteredAccountIds());
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function accountNames(): array
    {
        return $this->filterAccounts->mapWithKeys(fn (Account $account) => [$account->id => $account->displayName()])->all();
    }
};
?>

<div class="flex flex-col gap-6">
    <h1 class="text-2xl font-semibold">Dashboard</h1>

    <div class="card flex flex-col gap-2">
        <p>
            You have <strong>{{ $this->activeAccounts->count() }}</strong> active {{ Str::plural('account', $this->activeAccounts->count()) }}.
            Reporting starts on <strong>{{ $this->settings->reporting_start_date->format('F j, Y') }}</strong>.
        </p>

        @if ($this->activeAccounts->isEmpty())
            <p>
                Start by <a href="{{ route('accounts.create') }}" class="underline underline-offset-4">adding your first brokerage account</a>.
            </p>
        @else
            <p>
                Opening balances: <strong>{{ $this->completedOpeningCount }} of {{ $this->activeAccounts->count() }}</strong>
                {{ Str::plural('account', $this->activeAccounts->count()) }} complete.
                @if ($this->completedOpeningCount < $this->activeAccounts->count())
                    <a href="{{ route('opening-balances.index') }}" class="underline underline-offset-4" wire:navigate>Continue setting up opening balances</a>.
                @endif
            </p>
        @endif
    </div>

    @include('partials.report-filters')

    {{-- Data-quality alerts --}}
    <section class="card flex flex-col gap-2">
        <h2 class="text-lg font-semibold">Needs attention</h2>
        @if ($this->alerts === [])
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Nothing needs attention.</p>
        @else
            <ul class="flex flex-col gap-1 text-sm">
                @foreach ($this->alerts as $alert)
                    <li wire:key="alert-{{ $loop->index }}">
                        <a href="{{ $alert['url'] }}" class="text-amber-800 underline underline-offset-4 dark:text-amber-300" wire:navigate>{{ $alert['message'] }}</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($this->realized !== null)
        {{-- Realized P/L --}}
        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">Realized P/L · {{ $this->reportingPeriod?->label() }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="card">
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Stocks · net</div>
                    <div class="text-xl font-semibold tabular-nums">{{ $this->realized->stockTotals()['net']->format() }}</div>
                </div>
                <div class="card">
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Options · net of fees</div>
                    <div class="text-xl font-semibold tabular-nums">{{ $this->realized->optionTotals()['net']->format() }}</div>
                </div>
                <div class="card">
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Dividends received</div>
                    <div class="text-xl font-semibold tabular-nums">{{ $this->realized->dividendTotal()->format() }}</div>
                </div>
                <div class="card">
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">Combined net</div>
                    <div class="text-xl font-semibold tabular-nums">{{ $this->realized->combinedNet()->format() }}</div>
                </div>
            </div>
        </section>
    @endif

    {{-- Open positions and unrealized P/L --}}
    <section class="flex flex-col gap-3">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-lg font-semibold">Open positions · unrealized P/L</h2>
            <a href="{{ route('prices.index') }}" class="text-sm underline underline-offset-4" wire:navigate>Enter prices</a>
        </div>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            From manually entered prices, not live quotes. Positions without a price are left out of the totals.
            Stocks: unrealized {{ $this->open->stockUnrealizedTotal()->format() }}{{ $this->open->stocksNeedingPrices() > 0 ? ' ('.$this->open->stocksNeedingPrices().' need a price)' : '' }}.
            Options (net of opening fees, before any closing fee): unrealized {{ $this->open->optionUnrealizedTotal()->format() }}{{ $this->open->optionsNeedingPrices() > 0 ? ' ('.$this->open->optionsNeedingPrices().' need a price)' : '' }}.
        </p>

        @if ($this->open->stocks->isNotEmpty())
            <div class="card overflow-x-auto p-0">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3 font-medium">Account</th>
                            <th class="px-4 py-3 font-medium">Ticker</th>
                            <th class="px-4 py-3 text-right font-medium">Shares</th>
                            <th class="px-4 py-3 text-right font-medium">Cost basis</th>
                            <th class="px-4 py-3 text-right font-medium">Price</th>
                            <th class="px-4 py-3 text-right font-medium">Market value</th>
                            <th class="px-4 py-3 text-right font-medium">Unrealized</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->open->stocks as $stock)
                            <tr wire:key="open-stock-{{ $stock['accountId'] }}-{{ $stock['ticker'] }}">
                                <td class="px-4 py-2">{{ $stock['accountName'] }}</td>
                                <td class="px-4 py-2 font-medium">{{ $stock['ticker'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $stock['shares']->format() }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $stock['cost']->format() }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    @if ($stock['price'])
                                        {{ $stock['price']->price->format() }}
                                        <span class="block text-xs {{ $stock['stale'] ? 'text-amber-700 dark:text-amber-400' : 'text-zinc-500' }}">
                                            {{ $stock['stale'] ? 'Stale · ' : '' }}{{ $stock['price']->as_of->timezone($this->prices->displayTimezone())->format('M j, g:i A') }}
                                        </span>
                                    @else
                                        <span class="text-amber-700 dark:text-amber-400">Price needed</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $stock['marketValue']?->format() ?? '—' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $stock['unrealized']?->format() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($this->open->options->isNotEmpty())
            <div class="card overflow-x-auto p-0">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3 font-medium">Option (upcoming expirations first)</th>
                            <th class="px-4 py-3 text-right font-medium">Days left</th>
                            <th class="px-4 py-3 text-right font-medium">Open</th>
                            <th class="px-4 py-3 text-right font-medium">Premium</th>
                            <th class="px-4 py-3 font-medium">Underlying</th>
                            <th class="px-4 py-3 text-right font-medium">Option price</th>
                            <th class="px-4 py-3 text-right font-medium">Unrealized</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->open->options as $option)
                            <tr wire:key="open-option-{{ $option['position']->id }}">
                                <td class="px-4 py-2">
                                    <a href="{{ route('options.show', $option['position']) }}" class="font-medium underline underline-offset-4" wire:navigate>{{ $option['position']->description() }}</a>
                                    <span class="block text-xs text-zinc-500">{{ $this->accountNames[$option['position']->account_id] ?? '' }}</span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    @if ($option['daysToExpiry'] < 0)
                                        <span class="text-amber-700 dark:text-amber-400">Past expiration</span>
                                    @else
                                        {{ $option['daysToExpiry'] }}
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $option['openContracts'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $option['openPremium']->format() }}</td>
                                <td class="px-4 py-2">
                                    @if ($option['underlyingPrice'])
                                        {{ $option['underlyingPrice']->price->format() }} · {{ $option['moneyness'] }}{{ $option['underlyingStale'] ? ' (stale price)' : '' }}
                                    @else
                                        <span class="text-zinc-500">No underlying price</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    @if ($option['price'])
                                        {{ $option['price']->price->format() }}{{ $option['stale'] ? ' (stale)' : '' }}
                                    @else
                                        <span class="text-amber-700 dark:text-amber-400">Price needed</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $option['unrealized']?->format() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($this->open->stocks->isEmpty() && $this->open->options->isEmpty())
            <div class="card text-sm">No open positions.</div>
        @endif
    </section>

    {{-- Wheel cycles --}}
    <section class="flex flex-col gap-3">
        <h2 class="text-lg font-semibold">Wheel cycles</h2>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            A cycle starts when a put is sold with nothing held (or a call is sold on shares already held) and ends when nothing is held again.
            Capital at risk is a simplified figure: the most tied up in puts and shares during the cycle. Annualized return is an estimate,
            not a forecast or an account return. Open cycles show results to date.
        </p>

        @if ($this->cycles->isEmpty())
            <div class="card text-sm">No wheel cycles in this period.</div>
        @else
            <div class="card overflow-x-auto p-0">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3 font-medium">Ticker</th>
                            <th class="px-4 py-3 font-medium">Account</th>
                            <th class="px-4 py-3 font-medium">Dates</th>
                            <th class="px-4 py-3 text-right font-medium">Net profit</th>
                            <th class="px-4 py-3 text-right font-medium">Capital at risk</th>
                            <th class="px-4 py-3 text-right font-medium">Raw return</th>
                            <th class="px-4 py-3 text-right font-medium">Annualized (estimate)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->cycles as $cycle)
                            <tr wire:key="cycle-{{ $cycle->accountId }}-{{ $cycle->ticker }}-{{ $cycle->start->format('Ymd') }}">
                                <td class="px-4 py-2 font-medium">
                                    {{ $cycle->ticker }}
                                    <span class="block text-xs text-zinc-500">{{ $cycle->kind->label() }} · {{ $cycle->optionLegs }} option {{ Str::plural('leg', $cycle->optionLegs) }}</span>
                                </td>
                                <td class="px-4 py-2">{{ $this->accountNames[$cycle->accountId] ?? '' }}</td>
                                <td class="px-4 py-2">
                                    {{ $cycle->start->format('M j, Y') }} – {{ $cycle->end?->format('M j, Y') ?? 'open' }}
                                    <span class="block text-xs text-zinc-500">{{ $cycle->days($this->todayDate) }} days</span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ $cycle->netProfit()->format() }}
                                    @if ($cycle->isOpen())
                                        <span class="block text-xs text-zinc-500">to date</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $cycle->capitalAtRisk->format() }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ WheelCycle::formatPercent($cycle->rawReturnPercent(), 2) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ WheelCycle::formatPercent($cycle->annualizedReturnPercent($this->todayDate), 1) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($this->realized !== null)
        {{-- Premium per month --}}
        <section class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">Option premium by month</h2>
            @if ($this->premiumByMonth->isEmpty())
                <div class="card text-sm">No option activity in this period.</div>
            @else
                <div class="card overflow-x-auto p-0">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                            <tr>
                                <th class="px-4 py-3 font-medium">Month</th>
                                <th class="px-4 py-3 text-right font-medium">Premium collected (sold, gross)</th>
                                <th class="px-4 py-3 text-right font-medium">Realized option P/L (closed, net)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->premiumByMonth as $month => $amounts)
                                <tr wire:key="premium-{{ $month }}">
                                    <td class="px-4 py-2">{{ CarbonImmutable::createFromFormat('!Y-m', $month)?->format('F Y') }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $amounts['premium']->format() }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $amounts['realized']->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    @if ($this->fees !== null)
        {{-- Fees --}}
        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-lg font-semibold">Fees paid · {{ $this->fees->total()->format() }}</h2>
                <button type="button" wire:click="exportFees" class="btn-secondary">Download CSV</button>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach (['By month' => $this->fees->byMonth(), 'By type' => $this->fees->byType(), 'By ticker' => $this->fees->byTicker(), 'By account' => $this->fees->byAccount()] as $heading => $groups)
                    <div class="card flex flex-col gap-2 text-sm" wire:key="fees-{{ $heading }}">
                        <h3 class="font-medium">{{ $heading }}</h3>
                        @forelse ($groups as $label => $amount)
                            <div class="flex justify-between gap-4">
                                <span>{{ $heading === 'By month' ? CarbonImmutable::createFromFormat('!Y-m', $label)?->format('M Y') : $label }}</span>
                                <span class="tabular-nums">{{ $amount->format() }}</span>
                            </div>
                        @empty
                            <p class="text-zinc-500">No fees.</p>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Exposure --}}
    <section class="flex flex-col gap-3">
        <h2 class="text-lg font-semibold">Open exposure</h2>
        @if ($this->open->exposure()->isEmpty())
            <div class="card text-sm">Nothing held or open.</div>
        @else
            <div class="card overflow-x-auto p-0">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3 font-medium">Account</th>
                            <th class="px-4 py-3 font-medium">Ticker</th>
                            <th class="px-4 py-3 text-right font-medium">Shares</th>
                            <th class="px-4 py-3 text-right font-medium">Market value</th>
                            <th class="px-4 py-3 text-right font-medium">Open puts</th>
                            <th class="px-4 py-3 text-right font-medium">Open calls</th>
                            <th class="px-4 py-3 text-right font-medium">Cash needed if puts assigned</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->open->exposure() as $key => $row)
                            <tr wire:key="exposure-{{ $key }}">
                                <td class="px-4 py-2">{{ $row['accountName'] }}</td>
                                <td class="px-4 py-2 font-medium">{{ $row['ticker'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['shares']->format() }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['shares']->isZero() ? '—' : ($row['marketValue']?->format() ?? 'Price needed') }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['puts'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['calls'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['putObligation']->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Cash --}}
    <section class="flex flex-col gap-3">
        <h2 class="text-lg font-semibold">Cash</h2>
        @if ($this->cash->isEmpty())
            <div class="card text-sm">No accounts.</div>
        @else
            <div class="card overflow-x-auto p-0">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-3 font-medium">Account</th>
                            <th class="px-4 py-3 text-right font-medium">Cash today</th>
                            <th class="px-4 py-3 text-right font-medium">Settled</th>
                            <th class="px-4 py-3 font-medium">Latest broker balance</th>
                            <th class="px-4 py-3 text-right font-medium">Difference</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->cash as $row)
                            <tr wire:key="cash-{{ $row['account']->id }}">
                                <td class="px-4 py-2">{{ $row['account']->displayName() }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['total']->format() }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $row['settled']->format() }}</td>
                                <td class="px-4 py-2">
                                    @if ($row['broker'])
                                        {{ $row['broker']->total_cash->format() }} on {{ $row['broker']->as_of_date->format('M j, Y') }}
                                    @else
                                        <a href="{{ route('cash.reconciliation', ['account' => $row['account']->id]) }}" class="underline underline-offset-4" wire:navigate>None entered</a>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    @if ($row['difference'] === null)
                                        —
                                    @elseif ($row['difference']->isZero())
                                        Matches
                                    @else
                                        <span class="text-amber-700 dark:text-amber-400">{{ $row['difference']->format() }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="card text-sm text-zinc-600 dark:text-zinc-400">
        This is a tracking tool, not tax software or investment advice. Prices are entered by hand and are not live quotes.
    </div>
</div>
