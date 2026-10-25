<?php

use App\Enums\OptionType;
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

    $put = recordSellToOpen($this->taxable, OptionType::Put, 'XYZ', '50', '2026-04-17', '2026-02-02', 1, '1.20', '0.67');
    recordBuyToClose($put, '2026-03-10', 1, '0.40', '0.67');
});

it('shows stock and option profit separately with a combined total for the year', function () {
    Livewire::withQueryParams(['year' => '2026'])
        ->test('pages::realized.index')
        ->assertSeeInOrder([
            'Stocks · net', '$148.35', 'Gross $150.00 · fees $1.65',
            'Options · net', '$78.66', 'Gross $80.00 · fees $1.34',
            'Combined net · 2026', '$227.01',
        ])
        ->assertSeeInOrder(['Mar 2, 2026', 'Taxable', 'XYZ', '$5,200.00', '$5,000.00', '$200.00', '$1.65', '$198.35'])
        ->assertSeeInOrder(['Jun 15, 2026', 'IRA', 'ABC', '$150.00', '$200.00', '-$50.00', '$0.00', '-$50.00'])
        ->assertSeeInOrder(['Mar 10, 2026', 'Taxable', 'XYZ $50.00 Put, expires Apr 17, 2026', 'Bought to close', '1', '$120.00', '$40.00', '$80.00', '$1.34', '$78.66']);
});

it('filters by account', function () {
    Livewire::withQueryParams(['year' => '2026', 'account' => (string) $this->ira->id])
        ->test('pages::realized.index')
        ->assertSee('ABC')
        ->assertDontSee('XYZ')
        ->assertSee('-$50.00')
        ->assertSee('No option contracts were closed in this period.');
});

it('filters by a custom date range by sale and close date', function () {
    Livewire::withQueryParams(['year' => 'custom', 'from' => '2026-03-05', 'to' => '2026-03-31'])
        ->test('pages::realized.index')
        ->assertSee('Mar 10, 2026')
        ->assertDontSee('Mar 2, 2026')
        ->assertDontSee('Jun 15, 2026');
});

it('asks for valid dates when a custom range is incomplete or reversed', function (string $from, string $to) {
    Livewire::withQueryParams(['year' => 'custom', 'from' => $from, 'to' => $to])
        ->test('pages::realized.index')
        ->assertSee('Choose a valid "from" and "to" date.', escape: false)
        ->assertDontSee('XYZ');
})->with([
    'missing end' => ['2026-03-01', ''],
    'end before start' => ['2026-03-31', '2026-03-01'],
]);

it('shows nothing for a year with no sales or closes', function () {
    $this->travelTo(now()->setDate(2027, 2, 1));

    Livewire::withQueryParams(['year' => '2027'])
        ->test('pages::realized.index')
        ->assertSee('No shares were sold in this period.')
        ->assertSee('No option contracts were closed in this period.');
});

it('does not count option premium until contracts close', function () {
    recordSellToOpen($this->ira, OptionType::Call, 'ABC', '25', '2026-12-18', '2026-06-01', 2, '1.00', '1.34');

    Livewire::withQueryParams(['year' => '2026', 'account' => (string) $this->ira->id])
        ->test('pages::realized.index')
        ->assertSee('No option contracts were closed in this period.');
});
