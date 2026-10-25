<?php

use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\Dividend;
use App\Models\User;
use App\Support\DividendRate;
use App\Support\Money;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create(['nickname' => 'Main']);
});

function fillValidDividend(Testable $page, Account $account): Testable
{
    return $page
        ->set('form.account_id', (string) $account->id)
        ->set('form.ticker', 'xyz')
        ->set('form.ex_date', '2026-03-10')
        ->set('form.pay_date', '2026-03-31')
        ->set('form.amount_per_share', '0.50');
}

it('sends guests to the login page', function (string $url) {
    $this->app['auth']->logout();

    $this->get($url)->assertRedirect(route('login'));
})->with(['/dividends', '/dividends/create', '/cash', '/cash/movements/create', '/cash/reconciliation']);

describe('adding', function () {
    it('records a dividend and opens it to confirm', function () {
        fillValidDividend(Livewire::test('pages::dividends.create'), $this->account)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('dividends.edit', Dividend::sole()));

        expect(Dividend::sole())
            ->ticker->toBe('XYZ')
            ->amount_per_share->format()->toBe('$0.50')
            ->isConfirmed()->toBeFalse();
    });

    it('refuses the same account, ticker and ex-date twice', function () {
        Dividend::factory()->for($this->account)->create(['ticker' => 'XYZ', 'ex_date' => '2026-03-10']);

        fillValidDividend(Livewire::test('pages::dividends.create'), $this->account)
            ->call('save')
            ->assertHasErrors(['form.ex_date' => 'unique'])
            ->assertSee('This dividend is already recorded for this account, ticker and ex-date.');

        expect(Dividend::count())->toBe(1);
    });

    it('allows it again once the earlier entry is voided, or in another account', function () {
        Dividend::factory()->for($this->account)->create(['ticker' => 'XYZ', 'ex_date' => '2026-03-10'])->void('Duplicate');
        Dividend::factory()->create(['ticker' => 'XYZ', 'ex_date' => '2026-03-10']);

        fillValidDividend(Livewire::test('pages::dividends.create'), $this->account)
            ->call('save')
            ->assertHasNoErrors();
    });

    it('rejects invalid values', function (string $field, string $value) {
        fillValidDividend(Livewire::test('pages::dividends.create'), $this->account)
            ->set($field, $value)
            ->call('save')
            ->assertHasErrors($field);
    })->with([
        'paid before the reporting start date' => ['form.pay_date', '2025-12-31'],
        'seven decimal places' => ['form.amount_per_share', '0.1234567'],
        'zero per share' => ['form.amount_per_share', '0'],
        'bad ticker' => ['form.ticker', 'X Y'],
    ]);
});

describe('confirming', function () {
    beforeEach(function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-03-01', '200', '50');
        recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-03-05', '100', '51');
        $this->dividend = Dividend::factory()->for($this->account)->create(['ticker' => 'XYZ', 'ex_date' => '2026-03-10']);
    });

    it('shows the eligible shares and expected amount and accepts it as received', function () {
        Livewire::test('pages::dividends.edit', ['dividend' => $this->dividend])
            ->assertSeeInOrder(['Shares held on the ex-date', '100', 'Expected dividend', '$50.00', 'Not confirmed yet'])
            ->call('acceptExpected')
            ->assertSee('Expected amount accepted as received.');

        expect($this->dividend->fresh()->actual_amount?->format())->toBe('$50.00');
    });

    it('Example E: confirms a different actual amount and flags the difference', function () {
        Livewire::test('pages::dividends.edit', ['dividend' => $this->dividend])
            ->set('form.actual_amount', '42.50')
            ->call('confirmActual')
            ->assertHasNoErrors()
            ->assertSee('The actual amount differs from the expected $50.00 by -$7.50.');

        Livewire::withQueryParams(['year' => '2026'])
            ->test('pages::dividends.index')
            ->assertSee('Confirmed · differs by -$7.50');
    });

    it('needs a reason to change an amount already confirmed', function () {
        $this->dividend->confirm(Money::fromDecimal('50'));

        Livewire::test('pages::dividends.edit', ['dividend' => $this->dividend])
            ->set('form.actual_amount', '49.00')
            ->call('confirmActual')
            ->assertHasErrors('form.reason')
            ->set('form.reason', 'Statement shows 49.00')
            ->call('confirmActual')
            ->assertHasNoErrors();

        expect($this->dividend->fresh()->actual_amount?->format())->toBe('$49.00');
    });

    it('voids a dividend with a reason', function () {
        Livewire::test('pages::dividends.edit', ['dividend' => $this->dividend])
            ->set('form.reason', 'Entered twice')
            ->call('void')
            ->assertSee('Dividend voided.');

        expect($this->dividend->fresh()->isVoided())->toBeTrue();
    });
});

it('Example F: says "no shares on ex-date" rather than showing a silent zero', function () {
    $dividend = Dividend::factory()->for($this->account)->create(['ticker' => 'XYZ', 'ex_date' => '2026-03-10']);

    Livewire::test('pages::dividends.edit', ['dividend' => $dividend])->assertSee('No shares on ex-date');

    Livewire::withQueryParams(['year' => '2026'])->test('pages::dividends.index')->assertSee('No shares on ex-date');
});

it('shows confirmed dividends on the realized P/L page and adds them to the combined total', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-03-01', '100', '50');
    Dividend::factory()->for($this->account)->create([
        'ticker' => 'XYZ',
        'ex_date' => '2026-03-10',
        'pay_date' => '2026-03-31',
        'amount_per_share' => DividendRate::fromDecimal('0.50'),
    ])->confirm(Money::fromDecimal('50'));
    Dividend::factory()->for($this->account)->create(['ticker' => 'XYZ', 'ex_date' => '2026-06-10', 'pay_date' => '2026-06-30']);

    Livewire::withQueryParams(['year' => '2026'])
        ->test('pages::realized.index')
        ->assertSeeInOrder(['Dividends received', '$50.00', 'Combined net · 2026', '$50.00'])
        ->assertSeeInOrder(['Mar 31, 2026', 'Main', 'XYZ', 'Mar 10, 2026', '$50.00', '$50.00'])
        ->assertDontSee('Jun 30, 2026');
});
