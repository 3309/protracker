<?php

use App\Actions\Stocks\SaveStockTrade;
use App\Enums\AuditEvent;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\StockSaleAllocation;
use App\Models\StockTrade;
use App\Models\User;
use App\Support\Money;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create(['stock_fee_per_trade' => Money::fromCents(65)]);
});

function fillValidTrade(Testable $page, Account $account, string $side = 'buy'): Testable
{
    return $page
        ->set('form.account_id', (string) $account->id)
        ->set('form.side', $side)
        ->set('form.ticker', 'xyz')
        ->set('form.trade_date', '2026-07-02')
        ->set('form.shares', '100')
        ->set('form.price_per_share', '50');
}

it('sends guests to the login page', function (string $url) {
    $this->app['auth']->logout();

    $this->get($url)->assertRedirect(route('login'));
})->with(['/stock-trades', '/stock-trades/create', '/positions', '/realized', '/options', '/options/create']);

describe('create', function () {
    it('records a buy with the default fee and a calculated settlement date', function () {
        fillValidTrade(Livewire::test('pages::stock-trades.create'), $this->account)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('stock-trades.index'));

        $trade = StockTrade::sole();

        expect($trade->ticker)->toBe('XYZ')
            ->and($trade->side)->toBe(TradeSide::Buy)
            ->and($trade->fees->format())->toBe('$0.65')
            ->and($trade->settlement_date->format('Y-m-d'))->toBe('2026-07-06')
            ->and($trade->settlement_date_overridden)->toBeFalse()
            ->and($trade->lot()->exists())->toBeTrue();
    });

    it('keeps an actual fee and a settlement date entered by hand', function () {
        fillValidTrade(Livewire::test('pages::stock-trades.create'), $this->account)
            ->set('form.fees', '0')
            ->set('form.settlement_date', '2026-07-07')
            ->call('save')
            ->assertHasNoErrors();

        expect(StockTrade::sole())
            ->fees->cents->toBe(0)
            ->settlement_date->format('Y-m-d')->toBe('2026-07-07')
            ->settlement_date_overridden->toBeTrue();
    });

    it('explains when a sale needs more shares than the account holds', function () {
        fillValidTrade(Livewire::test('pages::stock-trades.create'), $this->account, 'sell')
            ->call('save')
            ->assertHasErrors('form.shares')
            ->assertSee('Not enough XYZ shares in this account for the sale on Jul 2, 2026: 100 needed, 0 available.');

        expect(StockTrade::count())->toBe(0);
    });

    it('rejects invalid values', function (string $field, string $value) {
        fillValidTrade(Livewire::test('pages::stock-trades.create'), $this->account)
            ->set($field, $value)
            ->call('save')
            ->assertHasErrors($field);

        expect(StockTrade::count())->toBe(0);
    })->with([
        'trade before the reporting start date' => ['form.trade_date', '2025-12-31'],
        'settlement before the trade date' => ['form.settlement_date', '2026-07-01'],
        'zero shares' => ['form.shares', '0'],
        'zero price' => ['form.price_per_share', '0'],
        'price with five decimal places' => ['form.price_per_share', '50.00001'],
        'fee with a fraction of a cent' => ['form.fees', '0.655'],
        'unknown side' => ['form.side', 'short'],
        'missing account' => ['form.account_id', ''],
    ]);

    it('does not accept an archived account', function () {
        $archived = Account::factory()->archived()->create();

        fillValidTrade(Livewire::test('pages::stock-trades.create'), $archived)
            ->call('save')
            ->assertHasErrors(['form.account_id' => 'exists']);
    });
});

describe('edit', function () {
    it('requires a reason, recalculates profit and records the change', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
        $sale = recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '100', '52');

        Livewire::test('pages::stock-trades.edit', ['stockTrade' => $sale])
            ->assertSee('Shares used (oldest first)')
            ->assertSee('$200.00')
            ->set('form.price_per_share', '53')
            ->call('save')
            ->assertHasErrors(['form.reason' => 'required'])
            ->set('form.reason', 'Price from confirmation')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Realized profit for this ticker has been recalculated.')
            ->assertSee('$300.00')
            ->assertSee('Price Per Share: $52.00 → $53.00');
    });

    it('shows an error and keeps the original when a correction would oversell', function () {
        $buy = recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
        recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '100', '52');

        Livewire::test('pages::stock-trades.edit', ['stockTrade' => $buy])
            ->set('form.shares', '50')
            ->set('form.reason', 'Fewer shares')
            ->call('save')
            ->assertHasErrors('form.shares')
            ->assertSee('Not enough XYZ shares');

        expect($buy->fresh()->shares->format())->toBe('100');
    });

    it('voids a trade with a reason and recalculates', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
        $sale = recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '100', '52');

        Livewire::test('pages::stock-trades.edit', ['stockTrade' => $sale])
            ->call('void')
            ->assertHasErrors(['form.reason' => 'required'])
            ->set('form.reason', 'Entered twice')
            ->call('void')
            ->assertHasNoErrors()
            ->assertSee('Trade voided.');

        expect($sale->fresh()->isVoided())->toBeTrue()
            ->and(StockSaleAllocation::count())->toBe(0)
            ->and($sale->auditEntries()->latest('id')->first()->event)->toBe(AuditEvent::Voided);
    });

    it('explains why a buy whose shares were sold cannot be voided', function () {
        $buy = recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
        recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '10', '52');

        Livewire::test('pages::stock-trades.edit', ['stockTrade' => $buy])
            ->set('form.reason', 'Mistake')
            ->call('void')
            ->assertHasErrors('form.reason')
            ->assertSee('This buy cannot be voided because later sales use its shares.');

        expect($buy->fresh()->isVoided())->toBeFalse();
    });

    it('does not allow a voided trade to be changed', function () {
        $buy = recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
        app(SaveStockTrade::class)->void($buy, 'Cancelled');

        Livewire::test('pages::stock-trades.edit', ['stockTrade' => $buy->fresh()])
            ->assertSee('This trade was voided.')
            ->set('form.reason', 'Trying anyway')
            ->call('save')
            ->assertForbidden();
    });
});

describe('index', function () {
    it('lists trades in the selected period and account, newest first, with voided ones marked', function () {
        $other = Account::factory()->create(['nickname' => 'Other account']);
        recordStockTrade($this->account, TradeSide::Buy, 'AAA', '2026-01-12', '10', '50');
        $voided = recordStockTrade($this->account, TradeSide::Buy, 'BBB', '2026-02-12', '10', '50');
        app(SaveStockTrade::class)->void($voided, 'Cancelled');
        recordStockTrade($other, TradeSide::Buy, 'CCC', '2026-03-12', '10', '50');

        Livewire::withQueryParams(['year' => '2026', 'account' => (string) $this->account->id])
            ->test('pages::stock-trades.index')
            ->assertSeeInOrder(['BBB', 'Voided', 'AAA'])
            ->assertDontSee('CCC');
    });
});
