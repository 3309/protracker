<?php

use App\Actions\Cash\BuildCashLedger;
use App\Enums\Broker;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\BrokerBalance;
use App\Models\Dividend;
use App\Models\Price;
use App\Support\DataQualityAlerts;
use App\Support\Money;
use App\Support\OpenPositionsReport;
use App\Support\PriceBook;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 16:00:00'));
    $this->today = CarbonImmutable::parse('2026-03-31');
    $this->account = Account::factory()->create(['nickname' => 'Main', 'broker' => Broker::Fidelity]);
    AccountOpening::factory()->complete()->for($this->account)->create(['cash_balance' => Money::fromDecimal('10000')]);
    $this->messages = fn () => collect(DataQualityAlerts::collect(
        OpenPositionsReport::build(null, null, PriceBook::load($this->today), $this->today),
        $this->today,
        null,
    ))->pluck('message')->all();
});

it('reports nothing when everything is in order', function () {
    expect(($this->messages)())->toBe([]);
});

it('asks for missing prices and flags stale ones', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '10', '50');
    recordStockTrade($this->account, TradeSide::Buy, 'ABC', '2026-01-12', '10', '20');
    Price::factory()->create(['ticker' => 'ABC', 'price' => UnitPrice::fromDecimal('21'), 'as_of' => '2026-03-20 20:00:00']);

    expect(($this->messages)())->toContain(
        'XYZ: price needed. Unrealized P/L is not shown until a price is entered.',
        'ABC: the latest price is stale (11 days old).',
    );
});

it('flags open options past expiration until an expiry or assignment is recorded', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-03-02', 1, '1.00', '0.67');
    Price::factory()->option(OptionType::Put, '50', '2026-03-20')->create(['ticker' => 'XYZ', 'price' => UnitPrice::fromDecimal('0'), 'as_of' => '2026-03-31 20:00:00']);

    expect(($this->messages)())->toContain('XYZ $50.00 Put, expires Mar 20, 2026 is past expiration. Record whether it expired worthless or was assigned.');

    recordExpiry($put, '2026-03-20', 1);

    expect(($this->messages)())->toBe([]);
});

it('flags dividends not confirmed after the pay date and confirmed amounts that differ', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-03-02', '100', '50');
    Price::factory()->create(['ticker' => 'XYZ', 'price' => UnitPrice::fromDecimal('50'), 'as_of' => '2026-03-31 20:00:00']);
    $unconfirmed = Dividend::factory()->for($this->account)->create(['ticker' => 'XYZ', 'ex_date' => '2026-03-10', 'pay_date' => '2026-03-20']);
    Dividend::factory()->for($this->account)->create(['ticker' => 'XYZ', 'ex_date' => '2026-03-11', 'pay_date' => '2026-03-25'])->confirm(Money::fromDecimal('42.50'));
    Dividend::factory()->for($this->account)->create(['ticker' => 'XYZ', 'ex_date' => '2026-03-12', 'pay_date' => '2026-04-15']);

    expect(($this->messages)())->toBe([
        'XYZ dividend paid Mar 20, 2026 has not been confirmed.',
        'XYZ dividend paid Mar 25, 2026: the amount received differs from the expected amount by -$7.50.',
    ]);

    $unconfirmed->confirm(Money::fromDecimal('50'));

    expect(($this->messages)())->toHaveCount(1);
});

it('flags a broker balance that does not match the calculated cash', function () {
    BrokerBalance::factory()->for($this->account)->create(['as_of_date' => '2026-03-31', 'total_cash' => Money::fromDecimal('10005')]);

    expect(($this->messages)())->toBe(['Fidelity · Main: cash on Mar 31, 2026 differs from the broker by $5.00.']);
});

it('flags open options expiring today as critical and within 7 days as warnings, with contracts and account', function () {
    Price::factory()->create(['ticker' => 'XYZ', 'price' => UnitPrice::fromDecimal('50'), 'as_of' => '2026-03-31 20:00:00']);

    foreach (['2026-03-31' => 2, '2026-04-03' => 1, '2026-04-07' => 1, '2026-04-08' => 1] as $expiresOn => $contracts) {
        recordSellToOpen($this->account, OptionType::Put, 'XYZ', '45', $expiresOn, '2026-03-02', $contracts, '1.00', '0.67');
        Price::factory()->option(OptionType::Put, '45', $expiresOn)->create(['ticker' => 'XYZ', 'price' => UnitPrice::fromDecimal('0.10'), 'as_of' => '2026-03-31 20:00:00']);
    }

    $alerts = DataQualityAlerts::collect(OpenPositionsReport::build(null, null, PriceBook::load($this->today), $this->today), $this->today, null);

    expect(array_map(fn (array $alert) => [$alert['level'], $alert['title'], $alert['message']], $alerts))->toBe([
        ['critical', 'Expires today', 'XYZ $45.00 Put, expires Mar 31, 2026 · 2 contracts open · Fidelity · Main'],
        ['warning', 'Expires in 3 days', 'XYZ $45.00 Put, expires Apr 3, 2026 · 1 contract open · Fidelity · Main'],
        ['warning', 'Expires in 7 days', 'XYZ $45.00 Put, expires Apr 7, 2026 · 1 contract open · Fidelity · Main'],
    ]);
});

it('warns about a negative cash balance without changing it, critical alerts first', function () {
    Account::factory()->create(['nickname' => 'Unfinished']);
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-03-02', '300', '50');
    Price::factory()->create(['ticker' => 'XYZ', 'price' => UnitPrice::fromDecimal('50'), 'as_of' => '2026-03-31 20:00:00']);

    $alerts = DataQualityAlerts::collect(OpenPositionsReport::build(null, null, PriceBook::load($this->today), $this->today), $this->today, null);

    expect($alerts[0])->toMatchArray([
        'level' => 'critical',
        'title' => 'Negative cash balance',
        'message' => 'Fidelity · Main: cash today is -$5,000.00. Check for a missing deposit or a mistyped trade.',
    ])
        ->and(end($alerts)['level'])->toBe('info')
        ->and(app(BuildCashLedger::class)->handle($this->account->id)->totalOn($this->today)->format())->toBe('-$5,000.00');
});

it('flags accounts whose opening balances are not complete', function () {
    Account::factory()->create(['nickname' => 'New account']);

    expect(collect(($this->messages)())->filter(fn ($message) => str_contains($message, 'New account'))->values()->all())
        ->toHaveCount(1);
});
