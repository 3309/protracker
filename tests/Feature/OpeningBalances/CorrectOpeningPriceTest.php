<?php

use App\Actions\Cash\BuildCashLedger;
use App\Enums\Broker;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\AccountOpening;
use App\Models\CashMovement;
use App\Models\Price;
use App\Models\StockLot;
use App\Models\StockTrade;
use App\Models\User;
use App\Support\Money;
use App\Support\OpenPositionsReport;
use App\Support\PriceBook;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00'));
    $this->actingAs(User::factory()->create());

    $this->fidelity = Account::factory()->create(['broker' => Broker::Fidelity]);
    AccountOpening::factory()->complete()->for($this->fidelity)->create(['cash_balance' => Money::fromDecimal('10000')]);

    $this->mstr = StockLot::factory()->for($this->fidelity)->create([
        'ticker' => 'MSTR',
        'acquired_on' => '2025-12-01',
        'shares' => Quantity::fromInteger(300),
        'cost_per_share' => UnitPrice::fromDecimal('151.95'),
    ]);
    $this->tsla = StockLot::factory()->for($this->fidelity)->create(['ticker' => 'TSLA', 'shares' => Quantity::fromInteger(100), 'cost_per_share' => UnitPrice::fromDecimal('400')]);
    $this->trade = recordStockTrade($this->fidelity, TradeSide::Buy, 'NVDA', '2026-02-02', '10', '180');
});

function correctMstrPrice(StockLot $lot, string $price): Testable
{
    return Livewire::test('pages::opening-balances.stock-lots.edit', ['stockLot' => $lot])
        ->set('form.cost_per_share', $price)
        ->set('form.reason', 'Original purchase price from the broker statement')
        ->call('save');
}

it('corrects the MSTR opening price in the same record, keeping its shares, date and account', function () {
    correctMstrPrice($this->mstr, '445.34')
        ->assertHasNoErrors()
        ->assertSee('Stock lot saved.')
        ->assertDontSee('This changes realized profit');

    expect(StockLot::query()->where('ticker', 'MSTR')->sole())
        ->id->toBe($this->mstr->id)
        ->account_id->toBe($this->fidelity->id)
        ->acquired_on->format('Y-m-d')->toBe('2025-12-01')
        ->shares->format()->toBe('300')
        ->cost_per_share->format()->toBe('$445.34')
        ->costBasis()->format()->toBe('$133,602.00')
        ->and($this->mstr->auditEntries()->latest('id')->first()->reason)->toBe('Original purchase price from the broker statement');
});

it('uses the corrected cost for holdings and unrealized P/L', function () {
    Price::factory()->create(['ticker' => 'MSTR', 'price' => UnitPrice::fromDecimal('500'), 'as_of' => now()]);

    correctMstrPrice($this->mstr, '445.34')->assertHasNoErrors();

    $mstr = OpenPositionsReport::build([$this->fidelity->id], 'MSTR', PriceBook::load(CarbonImmutable::parse('2026-10-25')), CarbonImmutable::parse('2026-10-25'))->stocks->sole();

    expect($mstr['shares']->format())->toBe('300')
        ->and($mstr['cost']->format())->toBe('$133,602.00')
        ->and($mstr['unrealized']->format())->toBe('$16,398.00');

    $this->get(route('positions'))->assertSee('$445.34')->assertSee('$133,602.00');
});

it('changes no cash and leaves the other lots and trades as they were', function () {
    $cashBefore = app(BuildCashLedger::class)->handle($this->fidelity->id)->totalOn(CarbonImmutable::parse('2026-10-25'));

    correctMstrPrice($this->mstr, '445.34')->assertHasNoErrors();

    expect(app(BuildCashLedger::class)->handle($this->fidelity->id)->totalOn(CarbonImmutable::parse('2026-10-25'))->format())->toBe($cashBefore->format())
        ->and(CashMovement::count())->toBe(0)
        ->and(StockLot::count())->toBe(3)
        ->and(StockTrade::sole()->is($this->trade))->toBeTrue()
        ->and($this->tsla->fresh()->cost_per_share->format())->toBe('$400.00')
        ->and($this->trade->fresh()->price_per_share->format())->toBe('$180.00');
});

it('rejects an invalid price and saves nothing', function (string $price) {
    correctMstrPrice($this->mstr, $price)->assertHasErrors('form.cost_per_share');

    expect($this->mstr->fresh()->cost_per_share->format())->toBe('$151.95')
        ->and($this->mstr->auditEntries()->count())->toBe(1);
})->with([
    'blank' => [''],
    'negative' => ['-445.34'],
    'text' => ['abc'],
    'five decimal places' => ['445.34001'],
]);

it('rejects zero shares and saves nothing', function () {
    Livewire::test('pages::opening-balances.stock-lots.edit', ['stockLot' => $this->mstr])
        ->set('form.shares', '0')
        ->set('form.reason', 'Typo')
        ->call('save')
        ->assertHasErrors('form.shares');

    expect($this->mstr->fresh()->shares->format())->toBe('300');
});

it('links each opening lot on the Positions page to its edit page', function () {
    $this->get(route('positions'))
        ->assertSee(route('opening-balances.stock-lots.edit', $this->mstr))
        ->assertDontSee(route('opening-balances.stock-lots.edit', StockLot::query()->where('ticker', 'NVDA')->sole()));
});
