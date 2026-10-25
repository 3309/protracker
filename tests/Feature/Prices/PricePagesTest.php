<?php

use App\Enums\OptionType;
use App\Enums\PriceInstrument;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\Price;
use App\Models\User;
use App\Support\PriceBook;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 18:00:00'));
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create();
});

it('sends guests to the login page', function () {
    $this->app['auth']->logout();

    $this->get(route('prices.index'))->assertRedirect(route('login'));
});

it('lists held stocks and open options with their price status', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    recordStockTrade($this->account, TradeSide::Buy, 'ABC', '2026-01-12', '10', '20');
    recordStockTrade($this->account, TradeSide::Buy, 'OLD', '2026-01-12', '10', '20');
    recordSellToOpen($this->account, OptionType::Put, 'XYZ', '45', '2026-04-17', '2026-03-02', 1, '1.00', '0.67');
    Price::factory()->create(['ticker' => 'ABC', 'price' => UnitPrice::fromDecimal('21'), 'as_of' => '2026-03-31 13:00:00']);
    Price::factory()->create(['ticker' => 'OLD', 'price' => UnitPrice::fromDecimal('19'), 'as_of' => '2026-03-20 20:00:00']);

    Livewire::test('pages::prices.index')
        ->assertSeeInOrder(['Stocks you hold', 'ABC', '$21.00', 'Current', 'OLD', '$19.00', 'Stale (11 days old)', 'XYZ', 'Price needed'])
        ->assertSeeInOrder(['Open option contracts', 'XYZ $45.00 Put, expires Apr 17, 2026', 'Price needed']);
});

it('saves a stock price at the 4:00 PM Eastern close when no time is given', function () {
    Livewire::test('pages::prices.index')
        ->assertSet('form.as_of_date', '2026-03-31')
        ->set('form.ticker', 'xyz')
        ->set('form.price', '52.10')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Price for XYZ saved: $52.10.');

    expect(Price::sole())
        ->instrument->toBe(PriceInstrument::Stock)
        ->ticker->toBe('XYZ')
        ->and(Price::sole()->as_of->utc()->format('Y-m-d H:i'))->toBe('2026-03-31 20:00');
});

it('prefills an option contract and saves its price, which may be zero', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '45', '2026-04-17', '2026-03-02', 1, '1.00', '0.67');

    Livewire::test('pages::prices.index')
        ->call('enterOptionPrice', $put->id)
        ->assertSet('form.instrument', 'option')
        ->assertSet('form.ticker', 'XYZ')
        ->assertSet('form.option_type', 'put')
        ->assertSet('form.strike', '45')
        ->assertSet('form.expires_on', '2026-04-17')
        ->set('form.price', '0')
        ->set('form.as_of_time', '10:30')
        ->call('save')
        ->assertHasNoErrors();

    expect(Price::sole()->identity())->toBe(Price::identityKey(PriceInstrument::Option, 'XYZ', OptionType::Put, UnitPrice::fromDecimal('45'), '2026-04-17'))
        ->and(Price::sole()->as_of->utc()->format('H:i'))->toBe('14:30');
});

it('rejects invalid prices', function (string $field, string $value) {
    Livewire::test('pages::prices.index')
        ->set('form.ticker', 'XYZ')
        ->set('form.price', '52')
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors($field);
})->with([
    'future date' => ['form.as_of_date', '2026-04-01'],
    'zero stock price' => ['form.price', '0'],
    'five decimal places' => ['form.price', '52.00001'],
    'bad time' => ['form.as_of_time', '4pm'],
]);

it('requires the contract details for an option price', function () {
    Livewire::test('pages::prices.index')
        ->set('form.instrument', 'option')
        ->set('form.ticker', 'XYZ')
        ->set('form.price', '1.00')
        ->call('save')
        ->assertHasErrors(['form.option_type', 'form.strike', 'form.expires_on']);
});

it('voids a mistaken price so the earlier one is used again', function () {
    $earlier = Price::factory()->create(['price' => UnitPrice::fromDecimal('50'), 'as_of' => '2026-03-30 20:00:00']);
    $mistake = Price::factory()->create(['price' => UnitPrice::fromDecimal('500'), 'as_of' => '2026-03-31 20:00:00']);

    Livewire::test('pages::prices.edit', ['price' => $mistake])
        ->call('void')
        ->assertHasErrors(['reason' => 'required'])
        ->set('reason', 'Extra zero')
        ->call('void')
        ->assertSee('Price voided.');

    expect(PriceBook::load(CarbonImmutable::parse('2026-03-31'))->forStock('XYZ')?->id)->toBe($earlier->id);
});
