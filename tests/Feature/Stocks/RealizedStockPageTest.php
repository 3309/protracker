<?php

use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->taxable = Account::factory()->create(['nickname' => 'Taxable']);
    $this->ira = Account::factory()->create(['nickname' => 'IRA']);

    recordStockTrade($this->taxable, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50', '1.00');
    recordStockTrade($this->taxable, TradeSide::Sell, 'XYZ', '2026-03-02', '100', '52', '0.65');
    recordStockTrade($this->ira, TradeSide::Buy, 'ABC', '2026-01-12', '10', '20');
    recordStockTrade($this->ira, TradeSide::Sell, 'ABC', '2026-06-15', '10', '15');
});

it('shows gross profit, fees and net profit with totals for the year', function () {
    Livewire::withQueryParams(['year' => '2026'])
        ->test('pages::realized.stocks')
        ->assertSeeInOrder(['Gross P/L', '$150.00', 'Fees', '$1.65', 'Net P/L · 2026', '$148.35'])
        ->assertSeeInOrder(['Mar 2, 2026', 'Taxable', 'XYZ', '$5,200.00', '$5,000.00', '$200.00', '$1.65', '$198.35'])
        ->assertSeeInOrder(['Jun 15, 2026', 'IRA', 'ABC', '$150.00', '$200.00', '-$50.00', '$0.00', '-$50.00']);
});

it('filters by account', function () {
    Livewire::withQueryParams(['year' => '2026', 'account' => (string) $this->ira->id])
        ->test('pages::realized.stocks')
        ->assertSee('ABC')
        ->assertDontSee('XYZ')
        ->assertSee('-$50.00');
});

it('filters by a custom date range by sale date', function () {
    Livewire::withQueryParams(['year' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-31'])
        ->test('pages::realized.stocks')
        ->assertSee('XYZ')
        ->assertDontSee('Jun 15, 2026');
});

it('asks for valid dates when a custom range is incomplete or reversed', function (string $from, string $to) {
    Livewire::withQueryParams(['year' => 'custom', 'from' => $from, 'to' => $to])
        ->test('pages::realized.stocks')
        ->assertSee('Choose a valid "from" and "to" date.', escape: false)
        ->assertDontSee('XYZ');
})->with([
    'missing end' => ['2026-03-01', ''],
    'end before start' => ['2026-03-31', '2026-03-01'],
]);

it('shows nothing for a year with no sales', function () {
    $this->travelTo(now()->setDate(2027, 2, 1));

    Livewire::withQueryParams(['year' => '2027'])
        ->test('pages::realized.stocks')
        ->assertSee('No shares were sold in this period.');
});
