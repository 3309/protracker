<?php

use App\Actions\Cash\BuildCashLedger;
use App\Actions\Options\SaveOptionTrade;
use App\Actions\Wheel\BuildWheelCycles;
use App\Enums\CashCategory;
use App\Enums\OptionEventType;
use App\Enums\OptionSide;
use App\Enums\OptionType;
use App\Enums\PositionOrigin;
use App\Enums\TradeSide;
use App\Exceptions\OptionPositionException;
use App\Models\Account;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Models\Price;
use App\Models\StockTrade;
use App\Support\DataQualityAlerts;
use App\Support\FeeReport;
use App\Support\Money;
use App\Support\OpenPositionsReport;
use App\Support\PriceBook;
use App\Support\RealizedProfit;
use App\Support\ReportingPeriod;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->ledger = fn () => app(BuildCashLedger::class)->handle($this->account->id);
    $this->cash = fn () => ($this->ledger)()->totalOn(CarbonImmutable::parse('2026-12-31'))->format();
    $this->buyCall = fn (int $contracts = 1, string $premium = '5.00', string $fee = '0.67') => recordBuyToOpen($this->account, OptionType::Call, 'TSLA', '250', '2026-03-20', '2026-02-02', $contracts, $premium, $fee);
});

it('records a bought option as long, with nothing realized until it closes', function () {
    $call = ($this->buyCall)();

    expect($call->side)->toBe(OptionSide::Long)
        ->and($call->origin)->toBe(PositionOrigin::BuyToOpen)
        ->and($call->description())->toBe('TSLA $250.00 Call (long), expires Mar 20, 2026')
        ->and($call->openContracts())->toBe(1)
        ->and($call->realizedNetProfit()->format())->toBe('$0.00')
        ->and(RealizedProfit::for(ReportingPeriod::forYear(2026))->optionTotals()['net']->format())->toBe('$0.00')
        ->and(($this->cash)())->toBe('-$500.67');
});

it('Test A: buying a call at $5.00 and selling it to close at $8.00 realizes $298.66 and closes the position', function () {
    $call = ($this->buyCall)();
    $sale = recordSellToClose($call, '2026-03-02', 1, '8.00', '0.67');

    expect($sale->event_type)->toBe(OptionEventType::SellToClose)
        ->and($sale->closingCost()->format())->toBe('$800.00')
        ->and($sale->allocated_premium->format())->toBe('$500.00')
        ->and($sale->grossProfit()->format())->toBe('$300.00')
        ->and($sale->totalFees()->format())->toBe('$1.34')
        ->and($sale->netProfit()->format())->toBe('$298.66')
        ->and($call->openContracts())->toBe(0)
        ->and(($this->ledger)()->entries->map(fn ($entry) => [$entry->category, $entry->amount()->format()])->all())->toBe([
            [CashCategory::OptionPurchase, '-$500.67'],
            [CashCategory::OptionSale, '$799.33'],
        ])
        ->and(($this->cash)())->toBe('$298.66');
});

it('Test B: a partial close of 2 of 5 contracts allocates premium and fees by contract and leaves 3 open', function () {
    $call = ($this->buyCall)(5, '2.00', '3.35');
    $sale = recordSellToClose($call, '2026-03-02', 2, '3.00', '1.34');

    expect($sale->allocated_premium->format())->toBe('$400.00')
        ->and($sale->allocated_opening_fees->format())->toBe('$1.34')
        ->and($sale->closingCost()->format())->toBe('$600.00')
        ->and($sale->netProfit()->format())->toBe('$197.32')
        ->and($call->openContracts())->toBe(3)
        ->and($call->openPremium()->format())->toBe('$600.00')
        ->and($call->openOpeningFees()->format())->toBe('$2.01');

    $rest = recordSellToClose($call, '2026-03-10', 3, '1.00', '2.01');

    expect($rest->allocated_premium->format())->toBe('$600.00')
        ->and($rest->allocated_opening_fees->format())->toBe('$2.01')
        ->and($rest->netProfit()->format())->toBe('-$304.02')
        ->and($sale->fresh()->allocated_premium->plus($rest->allocated_premium)->format())->toBe('$1,000.00')
        ->and($call->openContracts())->toBe(0);
});

it('Test C: selling a put for less than it cost realizes the loss with fees', function () {
    $put = recordBuyToOpen($this->account, OptionType::Put, 'NVDA', '120', '2026-03-20', '2026-02-02', 1, '4.00', '0.67');

    $sale = recordSellToClose($put, '2026-03-02', 1, '1.50', '0.67');

    expect($sale->grossProfit()->format())->toBe('-$250.00')
        ->and($sale->netProfit()->format())->toBe('-$251.34')
        ->and(($this->cash)())->toBe('-$251.34');
});

describe('Test D: expiring worthless', function () {
    it('turns the premium and opening fees into a loss, with no sale proceeds', function () {
        $call = ($this->buyCall)(1, '3.00', '0.67');
        $expiry = recordExpiry($call, '2026-03-20', 1);

        expect($expiry->closingCost()->format())->toBe('$0.00')
            ->and($expiry->netProfit()->format())->toBe('-$300.67')
            ->and($call->openContracts())->toBe(0)
            ->and(($this->ledger)()->entries->map(fn ($entry) => $entry->category)->all())->toBe([CashCategory::OptionPurchase])
            ->and(($this->cash)())->toBe('-$300.67');
    });

    it('cannot be recorded twice or before the expiration date', function (string $date) {
        $call = ($this->buyCall)(1, '3.00', '0.67');

        if ($date === '2026-03-20') {
            recordExpiry($call, '2026-03-20', 1);
        }

        expect(fn () => recordExpiry($call, $date, 1))->toThrow(OptionPositionException::class)
            ->and(OptionEvent::query()->sum('contracts'))->toBe($date === '2026-03-20' ? 1 : 0)
            ->and(RealizedProfit::for(ReportingPeriod::forYear(2026))->optionTotals()['net']->format())->toBe($date === '2026-03-20' ? '-$300.67' : '$0.00');
    })->with([
        'a second time' => '2026-03-20',
        'before expiration' => '2026-03-19',
    ]);
});

describe('Test E: refused closes', function () {
    it('cannot sell more contracts than are open, or sell a fully closed position', function () {
        $call = ($this->buyCall)(2);

        expect(fn () => recordSellToClose($call, '2026-03-02', 3, '8.00', '0.67'))->toThrow(OptionPositionException::class, 'Close no more contracts than are open');

        recordSellToClose($call, '2026-03-02', 2, '8.00', '1.34');

        expect(fn () => recordSellToClose($call, '2026-03-03', 1, '8.00', '0.67'))->toThrow(OptionPositionException::class)
            ->and(OptionEvent::count())->toBe(1)
            ->and(($this->cash)())->toBe('$597.99');
    });

    it('cannot sell after expiration', function () {
        expect(fn () => recordSellToClose(($this->buyCall)(), '2026-03-23', 1, '8.00', '0.67'))->toThrow(OptionPositionException::class, 'on or before expiration');
    });

    it('cannot buy back, assign or roll a bought option, and saves nothing', function (Closure $close) {
        $call = ($this->buyCall)();

        expect(fn () => $close($call))->toThrow(OptionPositionException::class, 'was bought, so it can only be sold to close or expire worthless')
            ->and(OptionEvent::count())->toBe(0)
            ->and(StockTrade::query()->whereNotNull('option_event_id')->count())->toBe(0)
            ->and(OptionPosition::count())->toBe(1);
    })->with([
        'buy to close' => [fn (OptionPosition $call) => recordBuyToClose($call, '2026-03-02', 1, '8.00', '0.67')],
        'assignment' => [function (OptionPosition $call) {
            recordStockTrade($call->account, TradeSide::Buy, 'TSLA', '2026-01-12', '100', '200');

            return recordAssignment($call, '2026-03-20', 1);
        }],
        'roll' => [fn (OptionPosition $call) => recordRoll($call, '2026-03-02', 1, '8.00', '0.67', '260', '2026-04-17', 1, '6.00', '0.67')],
    ]);

    it('cannot sell a sold option to close', function () {
        $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');

        expect(fn () => app(SaveOptionTrade::class)->sellToClose($put, ['event_date' => '2026-03-02', 'contracts' => 1, 'price_per_share' => UnitPrice::fromDecimal('0.40'), 'fees' => Money::fromDecimal('0.67')]))
            ->toThrow(OptionPositionException::class, 'was sold, so it cannot be Sold to close');
    });

    it('records the close in the position\'s own account', function () {
        $other = Account::factory()->create();
        $sale = app(SaveOptionTrade::class)->sellToClose(($this->buyCall)(), [
            'account_id' => $other->id,
            'event_date' => '2026-03-02',
            'contracts' => 1,
            'price_per_share' => UnitPrice::fromDecimal('8.00'),
            'fees' => Money::fromDecimal('0.67'),
        ]);

        expect($sale->account_id)->toBe($this->account->id)
            ->and(app(BuildCashLedger::class)->handle($other->id)->entries)->toBeEmpty();
    });
});

describe('reports', function () {
    it('include bought options in realized P/L and fees, but not in premium collected or wheel cycles', function () {
        recordSellToClose(($this->buyCall)(), '2026-03-02', 1, '8.00', '0.67');
        $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');
        recordExpiry($put, '2026-03-20', 1);

        $realized = RealizedProfit::for(ReportingPeriod::forYear(2026));
        $fees = FeeReport::for(ReportingPeriod::forYear(2026), null, null);

        expect($realized->optionCloses->map(fn (OptionEvent $event) => $event->event_type)->all())->toBe([OptionEventType::SellToClose, OptionEventType::Expired])
            ->and($realized->optionTotals()['net']->format())->toBe('$417.99')
            ->and($fees->byTicker()->map->format()->all())->toBe(['TSLA' => '$1.34', 'XYZ' => '$0.67'])
            ->and(app(BuildWheelCycles::class)->handle(null, 'TSLA'))->toBeEmpty()
            ->and(app(BuildWheelCycles::class)->handle(null, 'XYZ'))->toHaveCount(1);
    });

    it('value an open bought option as its price less the premium and fees paid, with no assignment cash needed for a bought put', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-02 16:00:00'));
        $today = CarbonImmutable::parse('2026-03-02');
        recordBuyToOpen($this->account, OptionType::Put, 'NVDA', '120', '2026-03-20', '2026-02-02', 2, '4.00', '1.34');
        Price::factory()->option(OptionType::Put, '120', '2026-03-20')->create(['ticker' => 'NVDA', 'price' => UnitPrice::fromDecimal('5.00'), 'as_of' => '2026-03-02 20:00:00']);

        $option = OpenPositionsReport::build(null, null, PriceBook::load($today), $today)->options->sole();

        expect($option['costToClose']?->format())->toBe('$1,000.00')
            ->and($option['unrealized']?->format())->toBe('$198.66')
            ->and($option['putObligation']->format())->toBe('$0.00');
    });

    it('alert when a bought option is past expiration', function () {
        ($this->buyCall)();

        $alerts = collect(DataQualityAlerts::collect(OpenPositionsReport::build(null, null, PriceBook::load(CarbonImmutable::parse('2026-03-25')), CarbonImmutable::parse('2026-03-25')), CarbonImmutable::parse('2026-03-25'), null));

        expect($alerts->pluck('message'))->toContain('TSLA $250.00 Call (long), expires Mar 20, 2026 is past expiration. Record whether it expired worthless or was sold to close.');
    });
});
