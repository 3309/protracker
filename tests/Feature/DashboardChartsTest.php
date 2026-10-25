<?php

use App\Enums\Broker;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\Dividend;
use App\Models\User;
use App\Support\ChartScale;
use App\Support\DashboardCharts;
use App\Support\DividendRate;
use App\Support\Money;
use App\Support\OpenPositionsReport;
use App\Support\PriceBook;
use App\Support\RealizedProfit;
use App\Support\ReportingPeriod;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-15 18:00:00'));
    $this->today = CarbonImmutable::parse('2026-04-15');
    $this->year = ReportingPeriod::forYear(2026);

    $this->fidelity = Account::factory()->create(['nickname' => 'Taxable', 'broker' => Broker::Fidelity]);
    AccountOpening::factory()->complete()->for($this->fidelity)->create(['cash_balance' => Money::fromDecimal('10000')]);
    $this->robinhood = Account::factory()->create(['nickname' => 'IRA', 'broker' => Broker::Robinhood]);
    AccountOpening::factory()->complete()->for($this->robinhood)->create(['cash_balance' => Money::fromDecimal('1000')]);

    // January: a stock gain of $100.00.
    recordStockTrade($this->fidelity, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    recordStockTrade($this->fidelity, TradeSide::Sell, 'XYZ', '2026-01-20', '50', '52');

    // February: a sold put expires (+$198.66) and a bought call is sold at a loss (-$201.34).
    recordExpiry(recordSellToOpen($this->fidelity, OptionType::Put, 'ABC', '40', '2026-02-20', '2026-02-02', 2, '1.00', '1.34'), '2026-02-20', 2);
    recordSellToClose(recordBuyToOpen($this->robinhood, OptionType::Call, 'TSLA', '250', '2026-03-20', '2026-02-03', 1, '3.00', '0.67'), '2026-02-10', 1, '1.00', '0.67');

    // March: nothing realized; Robinhood buys more stock than its cash.
    recordStockTrade($this->robinhood, TradeSide::Buy, 'QQQ', '2026-03-02', '10', '150');

    // April: 2 of 5 sold puts bought back (+$297.32), a $25.00 dividend, and options left open.
    $this->put = recordSellToOpen($this->fidelity, OptionType::Put, 'XYZ', '45', '2026-05-15', '2026-04-01', 5, '2.00', '3.35');
    recordBuyToClose($this->put, '2026-04-10', 2, '0.50', '1.34');
    $dividend = Dividend::factory()->for($this->fidelity)->create(['ticker' => 'XYZ', 'ex_date' => '2026-04-05', 'pay_date' => '2026-04-12', 'amount_per_share' => DividendRate::fromDecimal('0.50')]);
    $dividend->confirm(Money::fromDecimal('25'));
    recordSellToOpen($this->fidelity, OptionType::Call, 'XYZ', '60', '2026-04-17', '2026-04-06', 1, '0.50', '0.67');
    recordBuyToOpen($this->robinhood, OptionType::Call, 'TSLA', '260', '2026-05-15', '2026-04-02', 2, '1.00', '1.34');

    $this->months = fn (?array $accountIds = null, ?ReportingPeriod $period = null) => collect(DashboardCharts::realizedByMonth(
        RealizedProfit::for($period ?? $this->year, $accountIds),
        $period ?? $this->year,
        $this->today,
    ))->map(fn (array $row) => [$row['month']->format('M'), $row['total']->format(), $row['cumulative']->format()])->all();
});

describe('realized P/L by month', function () {
    it('shows gains, losses and empty months up to this month, with the running total', function () {
        expect(($this->months)())->toBe([
            ['Jan', '$100.00', '$100.00'],
            ['Feb', '-$2.68', '$97.32'],
            ['Mar', '$0.00', '$97.32'],
            ['Apr', '$322.32', '$419.64'],
        ]);
    });

    it('splits each month into stocks, options and dividends, counting each close once', function () {
        $april = DashboardCharts::realizedByMonth(RealizedProfit::for($this->year), $this->year, $this->today)[3];

        expect([$april['stocks']->format(), $april['options']->format(), $april['dividends']->format()])->toBe(['$0.00', '$297.32', '$25.00']);
    });

    it('adds up to the Realized P/L report for the same filters', function (?string $broker) {
        $accountIds = $broker === null ? null : [$broker === 'fidelity' ? $this->fidelity->id : $this->robinhood->id];
        $rows = DashboardCharts::realizedByMonth(RealizedProfit::for($this->year, $accountIds), $this->year, $this->today);

        expect(end($rows)['cumulative']->format())->toBe(RealizedProfit::for($this->year, $accountIds)->combinedNet()->format());
    })->with(['all accounts' => null, 'Fidelity only' => 'fidelity', 'Robinhood only' => 'robinhood']);

    it('shows only the chosen broker', function () {
        expect(($this->months)([$this->robinhood->id]))->toBe([
            ['Jan', '$0.00', '$0.00'],
            ['Feb', '-$201.34', '-$201.34'],
            ['Mar', '$0.00', '-$201.34'],
            ['Apr', '$0.00', '-$201.34'],
        ]);
    });

    it('includes records on the first and last day of a custom range and nothing outside it', function () {
        expect(($this->months)(null, ReportingPeriod::between('2026-02-10', '2026-02-20')))->toBe([
            ['Feb', '-$2.68', '-$2.68'],
        ])->and(($this->months)(null, ReportingPeriod::between('2026-02-11', '2026-02-19')))->toBe([
            ['Feb', '$0.00', '$0.00'],
        ]);
    });
});

describe('cash balance by broker', function () {
    it('follows the cash ledger per broker at each month end, showing negative balances', function () {
        $chart = DashboardCharts::cashByMonth(Account::query()->get(), $this->year, $this->today);
        $format = fn (array $values) => array_map(fn (Money $value) => $value->format(), $values);

        expect(array_map(fn (CarbonImmutable $date) => $date->format('Y-m-d'), $chart['dates']))->toBe(['2026-01-01', '2026-01-31', '2026-02-28', '2026-03-31', '2026-04-15'])
            ->and($format($chart['brokers']['fidelity']['values']))->toBe(['$10,000.00', '$7,600.00', '$7,798.66', '$7,798.66', '$8,768.30'])
            ->and($format($chart['brokers']['robinhood']['values']))->toBe(['$1,000.00', '$1,000.00', '$798.66', '-$701.34', '-$902.68'])
            ->and($format($chart['total']))->toBe(['$11,000.00', '$8,600.00', '$8,597.32', '$7,097.32', '$7,865.62']);
    });
});

describe('open contracts by expiration', function () {
    it('counts only contracts still open, soonest first, split into sold puts, sold calls and bought', function () {
        $rows = DashboardCharts::openContractsByExpiration(OpenPositionsReport::build(null, null, PriceBook::load($this->today), $this->today));

        expect(array_map(fn (array $row) => [$row['date']->format('Y-m-d'), $row['daysToExpiry'], $row['shortPuts'], $row['shortCalls'], $row['long']], $rows))->toBe([
            ['2026-04-17', 2, 0, 1, 0],
            ['2026-05-15', 30, 3, 0, 2],
        ]);
    });
});

it('draws the three charts on the dashboard with exact amounts and links', function () {
    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['year' => '2026'])
        ->test('pages::dashboard')
        ->assertSee('April 2026: gain $322.32 (stocks $0.00, options $297.32, dividends $25.00). Running total $419.64.')
        ->assertSee('Robinhood, end of Apr 15, 2026: -$902.68 (negative cash balance)')
        ->assertSee('Expires Apr 17, 2026 (2 days left): 1 open contract: 0 sold puts, 1 sold calls, 0 bought.')
        ->assertSee(route('realized.index', ['year' => 'custom', 'from' => '2026-02-01', 'to' => '2026-02-28']))
        ->assertSee(route('options.index', ['status' => 'open', 'expiry' => '2026-05']));
});

it('shows a message instead of an empty chart when there is nothing to draw', function () {
    $this->actingAs(User::factory()->create());

    Livewire::withQueryParams(['year' => '2026', 'ticker' => 'NONE'])
        ->test('pages::dashboard')
        ->assertSee('Nothing realized in this period yet.')
        ->assertSee('No open option contracts.');
});

it('rounds gridlines to whole steps that include zero', function () {
    $scale = ChartScale::for([-250_00, 1000_00], 0, 100);

    expect($scale->ticks)->toBe([-500_00, 0, 500_00, 1000_00])
        ->and($scale->y(0))->toEqualWithDelta(66.667, 0.001)
        ->and(ChartScale::dollars(-500_00))->toBe('-$500')
        ->and(ChartScale::dollars(12_50))->toBe('$12.50');
});
