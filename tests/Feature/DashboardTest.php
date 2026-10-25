<?php

use App\Enums\Broker;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\Dividend;
use App\Models\Price;
use App\Models\User;
use App\Support\DividendRate;
use App\Support\Money;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

it('shows the number of active accounts and the reporting start date', function () {
    Account::factory()->count(2)->create();
    Account::factory()->archived()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('You have 2 active accounts.')
        ->assertSeeText('Reporting starts on January 1, 2026.');
});

it('shows how many active accounts have completed their opening balances', function () {
    AccountOpening::factory()->complete()->create();
    AccountOpening::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSeeText('1 of 2 accounts complete.')
        ->assertSee('Continue setting up opening balances');
});

it('invites the user to add an account when there are none', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee('adding your first brokerage account')
        ->assertSee('Nothing needs attention.');
});

describe('with the Example A wheel cycle', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-31 18:00:00'));
        $this->actingAs(User::factory()->create());

        $this->taxable = Account::factory()->create(['nickname' => 'Taxable', 'broker' => Broker::Fidelity]);
        AccountOpening::factory()->complete()->for($this->taxable)->create(['cash_balance' => Money::fromDecimal('10000')]);

        $put = recordSellToOpen($this->taxable, OptionType::Put, 'XYZ', '50', '2026-02-20', '2026-01-12', 1, '1.20', '0.67');
        recordAssignment($put, '2026-02-20', 1);
        $call = recordSellToOpen($this->taxable, OptionType::Call, 'XYZ', '52', '2026-03-20', '2026-02-23', 1, '0.80', '0.67');
        $dividend = Dividend::factory()->for($this->taxable)->create(['ticker' => 'XYZ', 'ex_date' => '2026-03-10', 'pay_date' => '2026-03-20', 'amount_per_share' => DividendRate::fromDecimal('0.50')]);
        $dividend->confirm($dividend->currentExpectedAmount());
        recordAssignment($call, '2026-03-23', 1);

        $this->ira = Account::factory()->create(['nickname' => 'Robinhood IRA', 'broker' => Broker::Robinhood]);
        AccountOpening::factory()->complete()->for($this->ira)->create(['cash_balance' => Money::fromDecimal('5000')]);
        recordStockTrade($this->ira, TradeSide::Buy, 'ABC', '2026-03-02', '10', '20');
        Price::factory()->create(['ticker' => 'ABC', 'price' => UnitPrice::fromDecimal('25'), 'as_of' => '2026-03-31 20:00:00']);
    });

    it('shows realized results, the cycle returns, fees, premium by month and cash', function () {
        Livewire::withQueryParams(['year' => '2026', 'account' => (string) $this->taxable->id])
            ->test('pages::dashboard')
            ->assertSeeInOrder(['Realized P/L · 2026', '$200.00', '$198.66', '$50.00', '$448.66'])
            ->assertSeeInOrder(['Wheel cycles', 'XYZ', 'Started with a put', 'Jan 12, 2026 – Mar 23, 2026', '70 days', '$448.66', '$5,000.00', '8.97%', '46.8%'])
            ->assertSeeInOrder(['Option premium by month', 'January 2026', '$120.00', 'February 2026', '$80.00', '$119.33', 'March 2026', '$0.00', '$79.33'])
            ->assertSeeInOrder(['Fees paid · $1.34', 'Option opening', '$1.34'])
            ->assertSeeInOrder(['Cash', 'Fidelity · Taxable', '$10,448.66']);
    });

    it('shows unrealized profit from entered prices and asks for missing ones', function () {
        Livewire::withQueryParams(['year' => '2026'])
            ->test('pages::dashboard')
            ->assertSee('Stocks: unrealized $50.00.')
            ->assertSeeInOrder(['Robinhood · Robinhood IRA', 'ABC', '10', '$200.00', '$25.00', '$250.00', '$50.00'])
            ->assertSee('Nothing needs attention.');
    });

    it('filters by broker and ticker', function () {
        Livewire::withQueryParams(['year' => '2026', 'broker' => 'robinhood'])
            ->test('pages::dashboard')
            ->assertSee('No wheel cycles in this period.')
            ->assertSee('ABC');

        Livewire::withQueryParams(['year' => '2026', 'ticker' => 'abc'])
            ->test('pages::dashboard')
            ->assertSee('No wheel cycles in this period.')
            ->assertSeeInOrder(['Open exposure', 'ABC']);
    });

    it('lists data-quality alerts with links', function () {
        recordSellToOpen($this->ira, OptionType::Put, 'ABC', '18', '2026-04-17', '2026-03-30', 1, '0.40', '0.67');

        Livewire::withQueryParams(['year' => '2026'])
            ->test('pages::dashboard')
            ->assertSee('ABC $18.00 Put, expires Apr 17, 2026: option price needed.');
    });
});
