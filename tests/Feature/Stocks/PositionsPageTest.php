<?php

use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\StockLot;
use App\Models\User;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->taxable = Account::factory()->create(['nickname' => 'Taxable']);
    $this->ira = Account::factory()->create(['nickname' => 'IRA']);
    recordStockTrade($this->taxable, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    recordStockTrade($this->ira, TradeSide::Buy, 'XYZ', '2026-01-05', '100', '45');
});

it('Example H: all accounts shows both positions and an account filter shows only its own', function () {
    Livewire::withQueryParams(['as-of' => '2026-02-01'])
        ->test('pages::positions')
        ->assertSeeInOrder(['IRA', 'XYZ', '100', '$45.00', '$4,500.00', 'Taxable', 'XYZ', '100', '$50.00', '$5,000.00']);

    Livewire::withQueryParams(['as-of' => '2026-02-01', 'account' => (string) $this->taxable->id])
        ->test('pages::positions')
        ->assertSee('$5,000.00')
        ->assertDontSee('$4,500.00');
});

it('shows only the shares left after sales, as of the chosen date', function () {
    recordStockTrade($this->taxable, TradeSide::Sell, 'XYZ', '2026-03-02', '60', '52');

    Livewire::withQueryParams(['as-of' => '2026-03-01', 'account' => (string) $this->taxable->id])
        ->test('pages::positions')
        ->assertSee('$5,000.00');

    Livewire::withQueryParams(['as-of' => '2026-03-02', 'account' => (string) $this->taxable->id])
        ->test('pages::positions')
        ->assertSee('$2,000.00')
        ->assertDontSee('$5,000.00');
});

it('leaves out lots bought after the chosen date and fully sold lots', function () {
    recordStockTrade($this->ira, TradeSide::Sell, 'XYZ', '2026-03-02', '100', '52');

    Livewire::withQueryParams(['as-of' => '2026-01-06'])
        ->test('pages::positions')
        ->assertSee('$4,500.00')
        ->assertDontSee('$5,000.00');

    Livewire::withQueryParams(['as-of' => '2026-03-31'])
        ->test('pages::positions')
        ->assertDontSee('$4,500.00')
        ->assertSee('$5,000.00');
});

it('includes opening-balance lots', function () {
    StockLot::factory()->for($this->ira)->create([
        'ticker' => 'OLD',
        'acquired_on' => '2025-05-01',
        'shares' => Quantity::fromInteger(10),
        'cost_per_share' => UnitPrice::fromDecimal('12.34'),
    ]);

    Livewire::withQueryParams(['as-of' => '2026-02-01'])
        ->test('pages::positions')
        ->assertSee('OLD')
        ->assertSee('Opening balance')
        ->assertSee('$123.40');
});
