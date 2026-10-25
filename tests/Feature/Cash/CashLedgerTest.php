<?php

use App\Actions\Cash\BuildCashLedger;
use App\Actions\Stocks\SaveStockTrade;
use App\Enums\CashCategory;
use App\Enums\CashMovementType;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\BrokerBalance;
use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\OptionPosition;
use App\Models\StockSaleAllocation;
use App\Models\StockTrade;
use App\Support\DividendRate;
use App\Support\Money;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->buildLedger = fn () => app(BuildCashLedger::class)->handle($this->account->id);
});

it('Example A: the full wheel cycle nets $448.66 in profit and in cash, with each cash movement counted once', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-02-20', '2026-01-12', 1, '1.20', '0.67');
    $putAssignment = recordAssignment($put, '2026-02-20', 1);
    $call = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '52', '2026-03-20', '2026-02-23', 1, '0.80', '0.67');
    $dividend = Dividend::factory()->for($this->account)->create([
        'ticker' => 'XYZ',
        'ex_date' => '2026-03-10',
        'pay_date' => '2026-03-17',
        'amount_per_share' => DividendRate::fromDecimal('0.50'),
    ]);
    $dividend->confirm($dividend->currentExpectedAmount());
    $callAssignment = recordAssignment($call, '2026-03-20', 1);

    $stockProfit = StockSaleAllocation::sole()->netProfit();

    expect($putAssignment->netProfit()->format())->toBe('$119.33')
        ->and($callAssignment->netProfit()->format())->toBe('$79.33')
        ->and($stockProfit->format())->toBe('$200.00')
        ->and($dividend->actual_amount?->format())->toBe('$50.00')
        ->and($putAssignment->netProfit()->plus($callAssignment->netProfit())->plus($stockProfit)->plus($dividend->actual_amount ?? Money::zero())->format())->toBe('$448.66');

    $ledger = ($this->buildLedger)();

    expect($ledger->entries->map(fn ($entry) => [$entry->category, $entry->amount()->format()])->all())->toBe([
        [CashCategory::OptionPremium, '$119.33'],
        [CashCategory::StockBuy, '-$5,000.00'],
        [CashCategory::OptionPremium, '$79.33'],
        [CashCategory::Dividend, '$50.00'],
        [CashCategory::StockSale, '$5,200.00'],
    ])
        ->and($ledger->totalOn(CarbonImmutable::parse('2026-03-31'))->format())->toBe('$448.66');
});

it('Example K: the ledger adds up and a different broker balance shows the difference without changing anything', function () {
    AccountOpening::factory()->for($this->account)->create(['cash_balance' => Money::fromDecimal('2000')]);
    CashMovement::factory()->for($this->account)->create(['movement_date' => '2026-01-05', 'settlement_date' => '2026-01-05', 'amount' => Money::fromDecimal('5000')]);
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50', '0.65');
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '45', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');
    recordBuyToClose($put, '2026-02-20', 1, '0.40', '0.67');
    Dividend::factory()->for($this->account)->create(['ticker' => 'XYZ', 'ex_date' => '2026-03-02', 'pay_date' => '2026-03-16', 'amount_per_share' => DividendRate::fromDecimal('0.10')])
        ->confirm(Money::fromDecimal('10'));
    recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-03-23', '50', '52', '0.65');

    $ledger = ($this->buildLedger)();
    $calculated = $ledger->totalOn(CarbonImmutable::parse('2026-03-31'));

    expect($calculated->format())->toBe('$4,687.36');

    $broker = BrokerBalance::factory()->for($this->account)->create(['as_of_date' => '2026-03-31', 'total_cash' => Money::fromDecimal('4690.00')]);
    $recordsBefore = [StockTrade::count(), OptionPosition::count(), CashMovement::count(), Dividend::count()];

    expect($broker->total_cash->minus($calculated)->format())->toBe('$2.64')
        ->and(($this->buildLedger)()->totalOn(CarbonImmutable::parse('2026-03-31'))->format())->toBe('$4,687.36')
        ->and([StockTrade::count(), OptionPosition::count(), CashMovement::count(), Dividend::count()])->toBe($recordsBefore);
});

it('starts from the opening cash without counting it as a deposit', function () {
    AccountOpening::factory()->for($this->account)->create(['cash_balance' => Money::fromDecimal('2000')]);

    $entry = ($this->buildLedger)()->entries->sole();

    expect($entry->category)->toBe(CashCategory::OpeningBalance)
        ->and($entry->date->format('Y-m-d'))->toBe('2026-01-01')
        ->and($entry->amount()->format())->toBe('$2,000.00');
});

it('settles the unsettled part of opening cash on the first trading day', function () {
    AccountOpening::factory()->for($this->account)->create([
        'cash_balance' => Money::fromDecimal('2000'),
        'unsettled_cash' => Money::fromDecimal('300'),
    ]);

    $ledger = ($this->buildLedger)();

    expect($ledger->totalOn(CarbonImmutable::parse('2026-01-01'))->format())->toBe('$2,000.00')
        ->and($ledger->settledOn(CarbonImmutable::parse('2026-01-01'))->format())->toBe('$1,700.00')
        ->and($ledger->settledOn(CarbonImmutable::parse('2026-01-02'))->format())->toBe('$2,000.00');
});

it('keeps settled and total cash apart until a trade settles', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-07-02', '10', '50');

    $ledger = ($this->buildLedger)();

    expect($ledger->totalOn(CarbonImmutable::parse('2026-07-02'))->format())->toBe('-$500.00')
        ->and($ledger->settledOn(CarbonImmutable::parse('2026-07-03'))->format())->toBe('$0.00')
        ->and($ledger->settledOn(CarbonImmutable::parse('2026-07-06'))->format())->toBe('-$500.00');
});

it('does not count the premium of an opening-balance option again, but does count its buy-back', function () {
    $opening = OptionPosition::factory()->for($this->account)->create([
        'contracts' => 1,
        'opened_on' => '2025-12-05',
        'expires_on' => '2026-01-16',
        'premium_per_share' => UnitPrice::fromDecimal('1.20'),
    ]);
    recordBuyToClose($opening, '2026-01-09', 1, '0.40', '0.67');

    expect(($this->buildLedger)()->entries->map(fn ($entry) => [$entry->category, $entry->amount()->format()])->all())
        ->toBe([[CashCategory::OptionBuyBack, '-$40.67']]);
});

it('gives deposits and interest a plus sign and withdrawals and charges a minus sign', function () {
    foreach ([CashMovementType::Deposit, CashMovementType::Withdrawal, CashMovementType::Interest, CashMovementType::OtherCharge] as $type) {
        CashMovement::factory()->for($this->account)->create(['movement_type' => $type, 'amount' => Money::fromDecimal('10')]);
    }

    expect(($this->buildLedger)()->totalOn(CarbonImmutable::parse('2026-12-31'))->format())->toBe('$0.00');
});

it('leaves out voided records and unconfirmed dividends', function () {
    $buy = recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-03-02', '10', '50');
    app(SaveStockTrade::class)->void($buy, 'Mistake');
    CashMovement::factory()->for($this->account)->create()->void('Mistake');
    Dividend::factory()->for($this->account)->create();

    expect(($this->buildLedger)()->entries)->toBeEmpty();
});

it('builds one ledger per account, or all accounts together', function () {
    $other = Account::factory()->create();
    CashMovement::factory()->for($this->account)->create(['amount' => Money::fromDecimal('100')]);
    CashMovement::factory()->for($other)->create(['amount' => Money::fromDecimal('50')]);

    expect(($this->buildLedger)()->totalOn(CarbonImmutable::parse('2026-12-31'))->format())->toBe('$100.00')
        ->and(app(BuildCashLedger::class)->handle()->totalOn(CarbonImmutable::parse('2026-12-31'))->format())->toBe('$150.00');
});
