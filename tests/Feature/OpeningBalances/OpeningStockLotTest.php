<?php

use App\Enums\AuditEvent;
use App\Enums\OpeningBalanceSource;
use App\Enums\PositionOrigin;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\StockLot;
use App\Models\StockSaleAllocation;
use App\Models\User;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create();
});

function fillValidStockLot(Testable $page): Testable
{
    return $page
        ->set('form.ticker', 'aapl')
        ->set('form.acquired_on', '2025-03-14')
        ->set('form.shares', '100')
        ->set('form.cost_per_share', '50')
        ->set('form.source', 'broker_statement')
        ->set('form.reference_note', 'Dec 2025 statement');
}

describe('create', function () {
    it('adds an opening stock lot with its original date and cost', function () {
        fillValidStockLot(Livewire::test('pages::opening-balances.stock-lots.create', ['account' => $this->account]))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('opening-balances.show', $this->account));

        $lot = StockLot::sole();

        expect($lot->account_id)->toBe($this->account->id)
            ->and($lot->ticker)->toBe('AAPL')
            ->and($lot->origin)->toBe(PositionOrigin::OpeningBalance)
            ->and($lot->acquired_on->format('Y-m-d'))->toBe('2025-03-14')
            ->and($lot->shares->toDecimal())->toBe('100')
            ->and($lot->cost_per_share->toDecimal())->toBe('50')
            ->and($lot->fees->cents)->toBe(0)
            ->and($lot->source)->toBe(OpeningBalanceSource::BrokerStatement)
            ->and($lot->reference_note)->toBe('Dec 2025 statement')
            ->and($lot->costBasis()->format())->toBe('$5,000.00')
            ->and($lot->auditEntries()->sole()->event)->toBe(AuditEvent::Created);

        $row = DB::table('stock_lots')->first();

        expect($row->shares)->toBe(100_000_000)
            ->and($row->cost_per_share)->toBe(500_000);
    });

    it('keeps fractional shares and purchase fees exactly', function () {
        fillValidStockLot(Livewire::test('pages::opening-balances.stock-lots.create', ['account' => $this->account]))
            ->set('form.shares', '0.5')
            ->set('form.cost_per_share', '180.25')
            ->set('form.fees', '0.35')
            ->call('save')
            ->assertHasNoErrors();

        expect(StockLot::sole())
            ->shares->units->toBe(500_000)
            ->fees->cents->toBe(35)
            ->and(StockLot::sole()->costBasis()->format())->toBe('$90.13');
    });

    it('rejects a purchase on or after the reporting start date', function (string $date) {
        fillValidStockLot(Livewire::test('pages::opening-balances.stock-lots.create', ['account' => $this->account]))
            ->set('form.acquired_on', $date)
            ->call('save')
            ->assertHasErrors(['form.acquired_on' => 'before'])
            ->assertSee('Enter later purchases as trades.');

        expect(StockLot::count())->toBe(0);
    })->with(['the start date' => '2026-01-01', 'after the start date' => '2026-02-01']);

    it('rejects invalid values', function (string $field, string $value) {
        fillValidStockLot(Livewire::test('pages::opening-balances.stock-lots.create', ['account' => $this->account]))
            ->set($field, $value)
            ->call('save')
            ->assertHasErrors($field);

        expect(StockLot::count())->toBe(0);
    })->with([
        'zero shares' => ['form.shares', '0'],
        'seven decimal places of a share' => ['form.shares', '1.0000001'],
        'negative shares' => ['form.shares', '-5'],
        'five decimal places of cost' => ['form.cost_per_share', '50.12345'],
        'ticker with spaces' => ['form.ticker', 'AA PL'],
        'fee with a fraction of a cent' => ['form.fees', '0.675'],
        'impossible date' => ['form.acquired_on', '2025-02-30'],
        'missing source' => ['form.source', ''],
    ]);
});

describe('edit', function () {
    it('requires a reason and records the original and corrected values', function () {
        $lot = StockLot::factory()->for($this->account)->create([
            'shares' => Quantity::fromInteger(100),
            'cost_per_share' => UnitPrice::fromDecimal('50'),
        ]);

        $page = Livewire::test('pages::opening-balances.stock-lots.edit', ['stockLot' => $lot])
            ->set('form.cost_per_share', '49.50')
            ->call('save')
            ->assertHasErrors(['form.reason' => 'required']);

        expect($lot->refresh()->cost_per_share->toDecimal())->toBe('50');

        $page->set('form.reason', 'Statement shows 49.50')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Cost Per Share: $50.00 → $49.50')
            ->assertSee('Reason: Statement shows 49.50');

        expect($lot->refresh()->cost_per_share->toDecimal())->toBe('49.5');
    });

    it('voids a lot with a reason, keeping it out of the totals but in the history', function () {
        $kept = StockLot::factory()->for($this->account)->create(['ticker' => 'KEPT', 'shares' => Quantity::fromInteger(10), 'cost_per_share' => UnitPrice::fromDecimal('10')]);
        $lot = StockLot::factory()->for($this->account)->create(['ticker' => 'GONE', 'shares' => Quantity::fromInteger(100), 'cost_per_share' => UnitPrice::fromDecimal('50')]);

        Livewire::test('pages::opening-balances.stock-lots.edit', ['stockLot' => $lot])
            ->call('void')
            ->assertHasErrors(['form.reason' => 'required']);

        expect($lot->refresh()->isVoided())->toBeFalse();

        Livewire::test('pages::opening-balances.stock-lots.edit', ['stockLot' => $lot])
            ->set('form.reason', 'Entered twice')
            ->call('void')
            ->assertHasNoErrors()
            ->assertSee('Stock lot voided.');

        expect($lot->refresh()->isVoided())->toBeTrue()
            ->and(StockLot::count())->toBe(2)
            ->and($lot->auditEntries()->latest('id')->first())
            ->event->toBe(AuditEvent::Voided)
            ->reason->toBe('Entered twice');

        $this->get(route('opening-balances.show', $this->account))
            ->assertSeeInOrder(['KEPT', 'GONE', 'Voided', 'Total cost basis (excluding voided lots)', $kept->costBasis()->format()]);
    });

    it('does not allow a voided lot to be changed', function () {
        $lot = StockLot::factory()->for($this->account)->voided()->create();

        Livewire::test('pages::opening-balances.stock-lots.edit', ['stockLot' => $lot])
            ->assertSee('This stock lot was voided.')
            ->set('form.reason', 'Trying anyway')
            ->call('save')
            ->assertForbidden();
    });
});

describe('lots that sales have used', function () {
    beforeEach(function () {
        $this->lot = StockLot::factory()->for($this->account)->create([
            'ticker' => 'XYZ',
            'acquired_on' => '2025-06-02',
            'shares' => Quantity::fromInteger(100),
            'cost_per_share' => UnitPrice::fromDecimal('50'),
        ]);

        recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-10', '80', '52');
    });

    it('cannot be voided', function () {
        Livewire::test('pages::opening-balances.stock-lots.edit', ['stockLot' => $this->lot])
            ->set('form.reason', 'Mistake')
            ->call('void')
            ->assertHasErrors('form.reason')
            ->assertSee('This lot cannot be voided because recorded sales use its shares.');

        expect($this->lot->fresh()->isVoided())->toBeFalse();
    });

    it('cannot be reduced below the shares already sold', function () {
        Livewire::test('pages::opening-balances.stock-lots.edit', ['stockLot' => $this->lot])
            ->set('form.shares', '50')
            ->set('form.reason', 'Fewer shares')
            ->call('save')
            ->assertHasErrors('form.shares')
            ->assertSee('Not enough XYZ shares');

        expect($this->lot->fresh()->shares->format())->toBe('100');
    });

    it('recalculates the sale when its cost is corrected', function () {
        Livewire::test('pages::opening-balances.stock-lots.edit', ['stockLot' => $this->lot])
            ->set('form.cost_per_share', '40')
            ->set('form.reason', 'Statement shows $40')
            ->call('save')
            ->assertHasNoErrors();

        expect(StockSaleAllocation::sole()->grossProfit()->format())->toBe('$960.00');
    });

    it('re-runs oldest-first matching when an older lot is added', function () {
        Livewire::test('pages::opening-balances.stock-lots.create', ['account' => $this->account])
            ->set('form.ticker', 'XYZ')
            ->set('form.acquired_on', '2024-03-01')
            ->set('form.shares', '80')
            ->set('form.cost_per_share', '30')
            ->set('form.source', 'broker_statement')
            ->call('save')
            ->assertHasNoErrors();

        expect(StockSaleAllocation::with('lot')->sole())
            ->lot->acquired_on->format('Y-m-d')->toBe('2024-03-01')
            ->grossProfit()->format()->toBe('$1,760.00');
    });
});

it('rejects a floating-point number assigned directly to the model', function () {
    new StockLot(['shares' => 1.5]);
})->throws(InvalidArgumentException::class, 'must be set with a Quantity value, not a float');
