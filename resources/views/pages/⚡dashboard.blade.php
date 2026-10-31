<?php

use App\Actions\Cash\BuildCashLedger;
use App\Actions\Prices\RefreshStockPrices;
use App\Exceptions\MarketDataException;
use App\Actions\Wheel\BuildWheelCycles;
use App\Enums\Broker;
use App\Enums\OpeningStatus;
use App\Enums\PositionOrigin;
use App\Livewire\ReportPage;
use App\Models\Account;
use App\Models\BrokerBalance;
use App\Models\OptionPosition;
use App\Models\Setting;
use App\Support\CsvExport;
use App\Support\DashboardCharts;
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

    /**
     * Fetch the latest price of every stock held. Only prices are saved;
     * no trade, lot or cash record changes.
     */
    public function refreshStockPrices(RefreshStockPrices $refreshStockPrices): void
    {
        try {
            $result = $refreshStockPrices->handle($this->today());

            session()->now('stock-price-refresh', ['level' => $result->level(), 'message' => $result->message()]);
        } catch (MarketDataException $exception) {
            session()->now('stock-price-refresh', ['level' => 'critical', 'message' => $exception->getMessage()]);
        }

        unset($this->prices, $this->open, $this->alerts);
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

        $sales = OptionPosition::query()->notVoided()->short()->where('origin', '!=', PositionOrigin::OpeningBalance);
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
     * Cash today for all the accounts shown, and per broker (only brokers
     * that have an account). These are the per-account ledger totals added up.
     *
     * @return array{total: Money, brokers: array<string, array{label: string, cash: Money}>}
     */
    #[Computed]
    public function cashTotals(): array
    {
        $brokers = [];

        foreach (collect($this->cash)->sortBy(fn (array $row) => array_search($row['account']->broker, Broker::cases(), true)) as $row) {
            $broker = $row['account']->broker;
            $brokers[$broker->value] ??= ['label' => $broker->label(), 'cash' => Money::zero()];
            $brokers[$broker->value]['cash'] = $brokers[$broker->value]['cash']->plus($row['total']);
        }

        return [
            'total' => $this->cash->reduce(fn (Money $total, array $row) => $total->plus($row['total']), Money::zero()),
            'brokers' => $brokers,
        ];
    }

    /**
     * @return list<array{month: CarbonImmutable, stocks: Money, options: Money, dividends: Money, total: Money, cumulative: Money}>
     */
    #[Computed]
    public function realizedChart(): array
    {
        return $this->realized === null || $this->reportingPeriod === null
            ? []
            : DashboardCharts::realizedByMonth($this->realized, $this->reportingPeriod, $this->today());
    }

    /**
     * @return array{dates: list<CarbonImmutable>, brokers: array<string, array{label: string, values: list<Money>}>, total: list<Money>}|null
     */
    #[Computed]
    public function cashChart(): ?array
    {
        return $this->reportingPeriod === null
            ? null
            : DashboardCharts::cashByMonth($this->cash->map(fn (array $row) => $row['account']), $this->reportingPeriod, $this->today());
    }

    /**
     * @return list<array{date: CarbonImmutable, daysToExpiry: int, shortPuts: int, shortCalls: int, long: int}>
     */
    #[Computed]
    public function expirationChart(): array
    {
        return DashboardCharts::openContractsByExpiration($this->open);
    }

    /**
     * The account, broker and ticker filters, carried into the pages the charts link to.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function chartLinkParams(): array
    {
        return array_filter(['account' => $this->account, 'broker' => $this->broker, 'ticker' => $this->ticker], fn (string $value) => $value !== '');
    }

    /**
     * @return list<array{level: string, title: string, message: string, url: string}>  level: critical, warning or info
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

<div class="flex flex-col gap-8">
    <div class="flex flex-col gap-1">
        <h1 class="page-title">Dashboard</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            You have <strong>{{ $this->activeAccounts->count() }}</strong> active {{ Str::plural('account', $this->activeAccounts->count()) }}.
            Reporting starts on <strong>{{ $this->settings->reporting_start_date->format('F j, Y') }}</strong>.
            @if ($this->activeAccounts->isNotEmpty())
                Opening balances: <strong>{{ $this->completedOpeningCount }} of {{ $this->activeAccounts->count() }}</strong>
                {{ Str::plural('account', $this->activeAccounts->count()) }} complete.
                @if ($this->completedOpeningCount < $this->activeAccounts->count())
                    <a href="{{ route('opening-balances.index') }}" class="link" wire:navigate>Continue setting up opening balances</a>.
                @endif
            @endif
        </p>
    </div>

    @if ($this->activeAccounts->isEmpty())
        <x-alert level="info" title="Welcome">
            Start by <a href="{{ route('accounts.create') }}" class="link" wire:navigate>adding your first brokerage account</a>.
        </x-alert>
    @endif

    @include('partials.report-filters')

    {{-- Alerts: critical first. Hiding a warning only hides it in this browser tab. --}}
    <section class="flex flex-col gap-3" aria-labelledby="alerts-heading">
        <div class="flex flex-wrap items-center gap-2">
            <h2 id="alerts-heading" class="section-title">Needs attention</h2>
            @php($alertCounts = collect($this->alerts)->countBy('level'))
            @if ($alertCounts->get('critical'))
                <x-badge tone="red">{{ $alertCounts->get('critical') }} critical</x-badge>
            @endif
            @if ($alertCounts->get('warning'))
                <x-badge tone="amber">{{ $alertCounts->get('warning') }} {{ Str::plural('warning', $alertCounts->get('warning')) }}</x-badge>
            @endif
            @if ($alertCounts->get('info'))
                <x-badge tone="blue">{{ $alertCounts->get('info') }} to fill in</x-badge>
            @endif
        </div>
        @if ($this->alerts === [])
            <x-alert level="success">Nothing needs attention.</x-alert>
        @else
            <div class="flex flex-col gap-2">
                @foreach ($this->alerts as $alert)
                    <x-alert
                        :level="$alert['level']"
                        :title="$alert['title']"
                        :dismiss-key="$alert['level'] === 'critical' ? null : md5($alert['level'].$alert['message'])"
                        wire:key="alert-{{ md5($alert['message']) }}"
                    >
                        <a href="{{ $alert['url'] }}" class="underline-offset-4 hover:underline" wire:navigate>{{ $alert['message'] }}</a>
                    </x-alert>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Cash today --}}
    <section class="flex flex-col gap-3">
        <h2 class="section-title">Cash today</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Total cash" icon="cash" :href="route('cash.index')" hint="All accounts shown, from the cash ledger"
                :warning="$this->cashTotals['total']->isNegative() ? 'Negative cash balance' : null">
                <x-money :amount="$this->cashTotals['total']" tone="balance" />
            </x-stat-card>
            @foreach ($this->cashTotals['brokers'] as $brokerValue => $brokerCash)
                <x-stat-card :label="$brokerCash['label'].' cash'" icon="cash" :href="route('cash.index', ['broker' => $brokerValue])"
                    :warning="$brokerCash['cash']->isNegative() ? 'Negative cash balance' : null" wire:key="broker-cash-{{ $brokerValue }}">
                    <x-money :amount="$brokerCash['cash']" tone="balance" />
                </x-stat-card>
            @endforeach
        </div>
    </section>

    {{-- Open positions --}}
    <section class="flex flex-col gap-3">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="section-title">
                Open positions
                <x-tip text="Unrealized P/L is the gain or loss on positions you still hold, if you closed them at the latest prices. It is not money received yet." />
            </h2>
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('prices.index') }}" class="link text-sm" wire:navigate>Enter prices</a>
                <button type="button" wire:click="refreshStockPrices" wire:loading.attr="disabled" wire:target="refreshStockPrices" class="btn-secondary">
                    <span wire:loading.remove wire:target="refreshStockPrices">Refresh Stock Prices</span>
                    <span wire:loading wire:target="refreshStockPrices">Refreshing…</span>
                </button>
            </div>
        </div>
        @if (session('stock-price-refresh'))
            <x-alert :level="session('stock-price-refresh')['level']">{{ session('stock-price-refresh')['message'] }}</x-alert>
        @endif
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Stock market value" icon="portfolio" :href="route('positions')"
                :hint="$this->open->stocksNeedingPrices() > 0 ? $this->open->stocksNeedingPrices().' '.Str::plural('stock', $this->open->stocksNeedingPrices()).' need a price and are left out' : 'From the latest prices'">
                <x-money :amount="$this->open->stockMarketValueTotal()" tone="plain" />
            </x-stat-card>
            <x-stat-card label="Stock unrealized P/L" icon="portfolio" :href="route('positions')"
                :hint="$this->open->stocksNeedingPrices() > 0 ? 'Stocks without a price are left out' : 'Market value minus cost basis'">
                <x-money :amount="$this->open->stockUnrealizedTotal()" />
            </x-stat-card>
            <x-stat-card label="Options unrealized P/L" icon="options" :href="route('options.index')"
                :hint="$this->open->optionsNeedingPrices() > 0 ? $this->open->optionsNeedingPrices().' '.Str::plural('option', $this->open->optionsNeedingPrices()).' need a price and are left out' : 'Net of opening fees, before closing fees'">
                <x-money :amount="$this->open->optionUnrealizedTotal()" />
            </x-stat-card>
            @php($expiringSoon = $this->open->options->filter(fn (array $option) => $option['daysToExpiry'] <= App\Support\DataQualityAlerts::EXPIRING_SOON_DAYS)->count())
            <x-stat-card label="Open option positions" icon="options" :href="route('options.index')"
                :hint="$this->open->options->sum('openContracts').' '.Str::plural('contract', $this->open->options->sum('openContracts')).' open'.($expiringSoon > 0 ? ' · '.$expiringSoon.' expiring within 7 days or past' : '')">
                {{ $this->open->options->count() }}
            </x-stat-card>
        </div>
    </section>

    @if ($this->realized !== null)
        {{-- Realized P/L --}}
        <section class="flex flex-col gap-3">
            <h2 class="section-title">
                Realized P/L · {{ $this->reportingPeriod?->label() }}
                <x-tip text="Realized P/L is the gain or loss on trades that are closed: sold shares, and options bought back, sold, expired or assigned. Fees are taken off." />
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-stat-card label="Stocks realized P/L" icon="portfolio" :href="route('realized.index')" hint="Net of fees">
                    <x-money :amount="$this->realized->stockTotals()['net']" />
                </x-stat-card>
                <x-stat-card label="Options realized P/L" icon="options" :href="route('realized.index')" hint="Net of fees">
                    <x-money :amount="$this->realized->optionTotals()['net']" />
                </x-stat-card>
                <x-stat-card label="Dividend income" icon="cash" :href="route('dividends.index')" hint="Confirmed dividends">
                    <x-money :amount="$this->realized->dividendTotal()" />
                </x-stat-card>
                <x-stat-card label="Total realized P/L" icon="report" :href="route('realized.index')" hint="Stocks + options + dividends">
                    <x-money :amount="$this->realized->combinedNet()" />
                </x-stat-card>
            </div>
        </section>
    @endif

    {{-- Charts --}}
    <section class="flex flex-col gap-3">
        <h2 class="section-title">Charts</h2>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            They follow the filters above. Hover over a bar or point for exact amounts; click a bar to see the records behind it.
        </p>

        <div class="grid gap-4 xl:grid-cols-2">
            <div class="card flex flex-col gap-3 p-5 xl:col-span-2">
                <div>
                    <h3 class="font-semibold">
                        Realized P/L by month{{ $this->reportingPeriod ? ' · '.$this->reportingPeriod->label() : '' }}
                        <x-tip text="Stocks sold, options closed, expired or assigned, and confirmed dividends, net of fees, by the month they happened. Open positions are not included." />
                    </h3>
                    <p class="muted">Bars: each month's result. Line: the running total from the start of the period, not your account value.</p>
                </div>
                @if ($this->realizedChart === [])
                    <x-empty-state icon="report">No months to show for this period yet.</x-empty-state>
                @elseif (collect($this->realizedChart)->every(fn (array $row) => $row['total']->isZero() && $row['cumulative']->isZero()))
                    <x-empty-state icon="report">Nothing realized in this period yet. Results appear when shares are sold, options close or dividends are confirmed.</x-empty-state>
                @else
                    <x-chart.realized-by-month :rows="$this->realizedChart" :link-params="$this->chartLinkParams" />
                @endif
            </div>

            <div class="card flex flex-col gap-3 p-5">
                <div>
                    <h3 class="font-semibold">
                        Cash balance by broker
                        <x-tip text="Total cash from the cash ledger, including trades that have not settled yet. The ticker filter does not apply to cash." />
                    </h3>
                    <p class="muted">On the first day of the period and at the end of each month; the last point is today.</p>
                </div>
                @if ($this->cashChart === null || $this->cashChart['dates'] === [] || $this->cashChart['brokers'] === [])
                    <x-empty-state icon="cash">No cash to show yet. Add an account and its opening cash.</x-empty-state>
                @else
                    <x-chart.cash-by-month :chart="$this->cashChart" />
                @endif
            </div>

            <div class="card flex flex-col gap-3 p-5">
                <div>
                    <h3 class="font-semibold">
                        Open option contracts by expiration
                        <x-tip text="Contracts still open on each expiration date. Partly closed positions count only their open contracts." />
                    </h3>
                    <p class="muted">Soonest first. Click a bar to open those options.</p>
                </div>
                @if ($this->expirationChart === [])
                    <x-empty-state icon="options">No open option contracts.</x-empty-state>
                @else
                    <x-chart.expirations :rows="$this->expirationChart" :link-params="$this->chartLinkParams" />
                @endif
            </div>
        </div>
    </section>

    {{-- Open positions in detail --}}
    @if ($this->open->stocks->isNotEmpty())
        <section class="flex flex-col gap-3">
            <h2 class="section-title">Stock holdings</h2>
            <div class="card overflow-x-auto p-0">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Ticker</th>
                            <th class="text-right">Shares</th>
                            <th class="text-right">Cost basis <x-tip text="What you paid for the shares still held, including purchase fees." /></th>
                            <th class="text-right">Cost / share</th>
                            <th class="text-right">Price</th>
                            <th class="text-right">Market value</th>
                            <th class="text-right">Unrealized</th>
                            <th class="text-right">Unrealized %</th>
                            <th>Last quote update</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->open->stocks as $stock)
                            @php($percent = $stock['unrealizedPercent'])
                            <tr wire:key="open-stock-{{ $stock['accountId'] }}-{{ $stock['ticker'] }}">
                                <td>{{ $stock['accountName'] }}</td>
                                <td class="font-semibold">{{ $stock['ticker'] }}</td>
                                <td class="num">{{ $stock['shares']->format() }}</td>
                                <td class="num"><x-money :amount="$stock['cost']" tone="plain" /></td>
                                <td class="num">{{ $stock['costPerShare']->format() }}</td>
                                <td class="num">
                                    @if ($stock['price'])
                                        {{ $stock['price']->price->format() }}
                                        @if ($stock['stale'])
                                            <span class="block text-xs font-medium text-amber-700 dark:text-amber-400">Stale price</span>
                                        @endif
                                    @else
                                        <x-badge tone="amber">Price unavailable</x-badge>
                                    @endif
                                </td>
                                <td class="num"><x-money :amount="$stock['marketValue']" tone="plain" /></td>
                                <td class="num"><x-money :amount="$stock['unrealized']" /></td>
                                <td @class([
                                    'num whitespace-nowrap',
                                    'text-zinc-500 dark:text-zinc-400' => $percent === null || $stock['unrealized']->isZero(),
                                    'text-green-700 dark:text-green-400' => $percent !== null && ! $stock['unrealized']->isZero() && ! $stock['unrealized']->isNegative(),
                                    'text-red-700 dark:text-red-400' => $percent !== null && $stock['unrealized']->isNegative(),
                                ])>
                                    @if ($percent === null)
                                        —
                                    @else
                                        {{ ! $stock['unrealized']->isNegative() && ! $stock['unrealized']->isZero() ? '+' : '' }}{{ $percent }}%
                                    @endif
                                </td>
                                <td class="text-xs whitespace-nowrap">
                                    @if ($stock['price'])
                                        <span @class(['text-amber-700 dark:text-amber-400' => $stock['stale']])>
                                            {{ $stock['price']->as_of->timezone($this->prices->displayTimezone())->format('M j, g:i A') }}
                                        </span>
                                        <span class="block text-zinc-500 dark:text-zinc-400">
                                            {{ $stock['price']->source->label() }}@if ($stock['price']->retrieved_at) · fetched {{ $stock['price']->retrieved_at->timezone($this->prices->displayTimezone())->format('M j, g:i A') }}@endif
                                        </span>
                                    @else
                                        <span class="text-zinc-500 dark:text-zinc-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($this->open->options->isNotEmpty())
        <section class="flex flex-col gap-3">
            <h2 class="section-title">Open options <span class="text-sm font-normal text-zinc-500">· upcoming expirations first</span></h2>
            <div class="card overflow-x-auto p-0">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Option</th>
                            <th class="text-right">Days left</th>
                            <th class="text-right">Open</th>
                            <th class="text-right">Premium</th>
                            <th>Underlying</th>
                            <th class="text-right">Option price</th>
                            <th class="text-right">Unrealized</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->open->options as $option)
                            <tr wire:key="open-option-{{ $option['position']->id }}">
                                <td>
                                    <a href="{{ route('options.show', $option['position']) }}" class="link" wire:navigate>{{ $option['position']->description() }}</a>
                                    <span class="block text-xs text-zinc-500">{{ $this->accountNames[$option['position']->account_id] ?? '' }}</span>
                                </td>
                                <td class="num">
                                    @if ($option['daysToExpiry'] < 0)
                                        <x-badge tone="red">Past expiration</x-badge>
                                    @elseif ($option['daysToExpiry'] === 0)
                                        <x-badge tone="red">Today</x-badge>
                                    @elseif ($option['daysToExpiry'] <= App\Support\DataQualityAlerts::EXPIRING_SOON_DAYS)
                                        <x-badge tone="amber">{{ $option['daysToExpiry'] }}</x-badge>
                                    @else
                                        {{ $option['daysToExpiry'] }}
                                    @endif
                                </td>
                                <td class="num">{{ $option['openContracts'] }}</td>
                                <td class="num">
                                    <x-money :amount="$option['openPremium']" tone="plain" />
                                    <span class="block text-xs text-zinc-500">{{ $option['position']->isLong() ? 'paid' : 'received' }}</span>
                                </td>
                                <td>
                                    @if ($option['underlyingPrice'])
                                        {{ $option['underlyingPrice']->price->format() }} · {{ $option['moneyness'] }}{{ $option['underlyingStale'] ? ' (stale price)' : '' }}
                                    @else
                                        <span class="text-zinc-500">No underlying price</span>
                                    @endif
                                </td>
                                <td class="num">
                                    @if ($option['price'])
                                        {{ $option['price']->price->format() }}{{ $option['stale'] ? ' (stale)' : '' }}
                                    @else
                                        <x-badge tone="amber">Price needed</x-badge>
                                    @endif
                                </td>
                                <td class="num"><x-money :amount="$option['unrealized']" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($this->open->stocks->isEmpty() && $this->open->options->isEmpty())
        <x-empty-state>No open positions.</x-empty-state>
    @endif

    {{-- Wheel cycles --}}
    <section class="flex flex-col gap-3">
        <h2 class="section-title">
            Wheel cycles
            <x-tip text="A cycle starts when a put is sold with nothing held (or a call is sold on shares already held) and ends when nothing is held again." />
        </h2>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Capital at risk is a simplified figure: the most tied up in puts and shares during the cycle. Annualized return is an estimate,
            not a forecast or an account return. Open cycles show results to date.
        </p>

        @if ($this->cycles->isEmpty())
            <x-empty-state>No wheel cycles in this period.</x-empty-state>
        @else
            <div class="card overflow-x-auto p-0">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Ticker</th>
                            <th>Account</th>
                            <th>Dates</th>
                            <th class="text-right">Net profit</th>
                            <th class="text-right">Capital at risk</th>
                            <th class="text-right">Raw return</th>
                            <th class="text-right">Annualized (estimate)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->cycles as $cycle)
                            <tr wire:key="cycle-{{ $cycle->accountId }}-{{ $cycle->ticker }}-{{ $cycle->start->format('Ymd') }}">
                                <td class="font-semibold">
                                    {{ $cycle->ticker }}
                                    <span class="block text-xs font-normal text-zinc-500">{{ $cycle->kind->label() }} · {{ $cycle->optionLegs }} option {{ Str::plural('leg', $cycle->optionLegs) }}</span>
                                </td>
                                <td>{{ $this->accountNames[$cycle->accountId] ?? '' }}</td>
                                <td>
                                    {{ $cycle->start->format('M j, Y') }} – {{ $cycle->end?->format('M j, Y') ?? 'open' }}
                                    <span class="block text-xs text-zinc-500">{{ $cycle->days($this->todayDate) }} days</span>
                                </td>
                                <td class="num">
                                    <x-money :amount="$cycle->netProfit()" />
                                    @if ($cycle->isOpen())
                                        <span class="block text-xs text-zinc-500">to date</span>
                                    @endif
                                </td>
                                <td class="num"><x-money :amount="$cycle->capitalAtRisk" tone="plain" /></td>
                                <td class="num">{{ WheelCycle::formatPercent($cycle->rawReturnPercent(), 2) }}</td>
                                <td class="num">{{ WheelCycle::formatPercent($cycle->annualizedReturnPercent($this->todayDate), 1) }}</td>
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
            <h2 class="section-title">Option premium by month</h2>
            @if ($this->premiumByMonth->isEmpty())
                <x-empty-state>No option activity in this period.</x-empty-state>
            @else
                <div class="card overflow-x-auto p-0">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th class="text-right">Premium collected (sold, gross)</th>
                                <th class="text-right">Realized option P/L (closed, net)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->premiumByMonth as $month => $amounts)
                                <tr wire:key="premium-{{ $month }}">
                                    <td>{{ CarbonImmutable::createFromFormat('!Y-m', $month)?->format('F Y') }}</td>
                                    <td class="num"><x-money :amount="$amounts['premium']" tone="plain" /></td>
                                    <td class="num"><x-money :amount="$amounts['realized']" /></td>
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
                <h2 class="section-title">Fees paid · {{ $this->fees->total()->format() }}</h2>
                <button type="button" wire:click="exportFees" class="btn-secondary">
                    <x-icon name="download" class="size-4" />
                    Download CSV
                </button>
            </div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach (['By month' => $this->fees->byMonth(), 'By type' => $this->fees->byType(), 'By ticker' => $this->fees->byTicker(), 'By account' => $this->fees->byAccount()] as $heading => $groups)
                    <div class="card flex flex-col gap-2 p-5 text-sm" wire:key="fees-{{ $heading }}">
                        <h3 class="font-semibold">{{ $heading }}</h3>
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
        <h2 class="section-title">
            Open exposure
            <x-tip text="What you hold and have open per ticker. Cash needed if puts assigned: what you would pay if every sold put were assigned at its strike." />
        </h2>
        @if ($this->open->exposure()->isEmpty())
            <x-empty-state>Nothing held or open.</x-empty-state>
        @else
            <div class="card overflow-x-auto p-0">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Ticker</th>
                            <th class="text-right">Shares</th>
                            <th class="text-right">Market value</th>
                            <th class="text-right">Open puts</th>
                            <th class="text-right">Open calls</th>
                            <th class="text-right">Cash needed if puts assigned</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->open->exposure() as $key => $row)
                            <tr wire:key="exposure-{{ $key }}">
                                <td>{{ $row['accountName'] }}</td>
                                <td class="font-semibold">{{ $row['ticker'] }}</td>
                                <td class="num">{{ $row['shares']->format() }}</td>
                                <td class="num">{{ $row['shares']->isZero() ? '—' : ($row['marketValue']?->format() ?? 'Price needed') }}</td>
                                <td class="num">{{ $row['puts'] }}</td>
                                <td class="num">{{ $row['calls'] }}</td>
                                <td class="num">{{ $row['putObligation']->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    {{-- Cash by account --}}
    <section class="flex flex-col gap-3">
        <h2 class="section-title">
            Cash
            <x-tip text="Cash today includes trades that have not settled yet. Settled cash only counts trades whose settlement date has passed." />
        </h2>
        @if ($this->cash->isEmpty())
            <x-empty-state>No accounts.</x-empty-state>
        @else
            <div class="card overflow-x-auto p-0">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th class="text-right">Cash today</th>
                            <th class="text-right">Settled</th>
                            <th>Latest broker balance</th>
                            <th class="text-right">Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->cash as $row)
                            <tr wire:key="cash-{{ $row['account']->id }}">
                                <td>
                                    {{ $row['account']->displayName() }}
                                    @if ($row['total']->isNegative())
                                        <span class="mt-1 block"><x-badge tone="red"><x-icon name="warning" class="size-3.5" />Negative cash balance</x-badge></span>
                                    @endif
                                </td>
                                <td class="num"><x-money :amount="$row['total']" tone="balance" /></td>
                                <td class="num"><x-money :amount="$row['settled']" tone="balance" /></td>
                                <td>
                                    @if ($row['broker'])
                                        {{ $row['broker']->total_cash->format() }} on {{ $row['broker']->as_of_date->format('M j, Y') }}
                                    @else
                                        <a href="{{ route('cash.reconciliation', ['account' => $row['account']->id]) }}" class="link" wire:navigate>None entered</a>
                                    @endif
                                </td>
                                <td class="num">
                                    @if ($row['difference'] === null)
                                        —
                                    @elseif ($row['difference']->isZero())
                                        <x-badge tone="green">Matches</x-badge>
                                    @else
                                        <x-badge tone="amber">Differs by {{ $row['difference']->format() }}</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <p class="text-xs text-zinc-500 dark:text-zinc-400">
        This is a tracking tool, not tax software or investment advice. Prices are entered by hand or fetched with "Refresh Stock Prices"; fetched prices come from the IEX exchange and can differ slightly from your broker's.
    </p>
</div>
