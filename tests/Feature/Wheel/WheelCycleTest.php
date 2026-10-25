<?php

use App\Actions\Wheel\BuildWheelCycles;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Enums\WheelCycleKind;
use App\Models\Account;
use App\Models\Dividend;
use App\Models\StockLot;
use App\Support\DividendRate;
use App\Support\Quantity;
use App\Support\UnitPrice;
use App\Support\WheelCycle;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->cycles = fn (?string $ticker = null) => app(BuildWheelCycles::class)->handle(null, $ticker);
});

it('Example A: a 70-day cycle nets $448.66 on $5,000, a raw return of 8.97% and about 46.8% annualized', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-02-20', '2026-01-12', 1, '1.20', '0.67');
    recordAssignment($put, '2026-02-20', 1);
    $call = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '52', '2026-03-20', '2026-02-23', 1, '0.80', '0.67');
    $dividend = Dividend::factory()->for($this->account)->create(['ticker' => 'XYZ', 'ex_date' => '2026-03-10', 'pay_date' => '2026-03-31', 'amount_per_share' => DividendRate::fromDecimal('0.50')]);
    $dividend->confirm($dividend->currentExpectedAmount());
    recordAssignment($call, '2026-03-23', 1);

    $cycle = ($this->cycles)()->sole();
    $today = CarbonImmutable::parse('2026-10-25');

    expect($cycle->kind)->toBe(WheelCycleKind::Put)
        ->and($cycle->start->format('Y-m-d'))->toBe('2026-01-12')
        ->and($cycle->end?->format('Y-m-d'))->toBe('2026-03-23')
        ->and($cycle->days($today))->toBe(70)
        ->and($cycle->optionProfit->format())->toBe('$198.66')
        ->and($cycle->stockProfit->format())->toBe('$200.00')
        ->and($cycle->dividends->format())->toBe('$50.00')
        ->and($cycle->netProfit()->format())->toBe('$448.66')
        ->and($cycle->capitalAtRisk->format())->toBe('$5,000.00')
        ->and($cycle->optionLegs)->toBe(2)
        ->and(WheelCycle::formatPercent($cycle->rawReturnPercent(), 2))->toBe('8.97%')
        ->and(WheelCycle::formatPercent($cycle->annualizedReturnPercent($today), 1))->toBe('46.8%');
});

it('treats a put that expires worthless as a completed put-only cycle', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-03-02', 2, '1.00', '1.34');
    recordExpiry($put, '2026-03-20', 2);

    $cycle = ($this->cycles)()->sole();

    expect($cycle->isOpen())->toBeFalse()
        ->and($cycle->end?->format('Y-m-d'))->toBe('2026-03-20')
        ->and($cycle->netProfit()->format())->toBe('$198.66')
        ->and($cycle->capitalAtRisk->format())->toBe('$10,000.00');
});

it('keeps a roll inside the same cycle', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-03-02', 1, '1.00', '0.67');
    $rolled = recordRoll($put, '2026-03-13', 1, '1.50', '0.67', '48', '2026-04-17', 1, '2.00', '0.67');
    recordExpiry($rolled, '2026-04-17', 1);

    $cycle = ($this->cycles)()->sole();

    expect($cycle->start->format('Y-m-d'))->toBe('2026-03-02')
        ->and($cycle->end?->format('Y-m-d'))->toBe('2026-04-17')
        ->and($cycle->optionLegs)->toBe(2)
        ->and($cycle->netProfit()->format())->toBe('$147.99');
});

it('shows an open cycle with its result to date', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-02-20', '2026-01-12', 1, '1.20', '0.67');
    recordAssignment($put, '2026-02-20', 1);

    $cycle = ($this->cycles)()->sole();

    expect($cycle->isOpen())->toBeTrue()
        ->and($cycle->netProfit()->format())->toBe('$119.33')
        ->and($cycle->days(CarbonImmutable::parse('2026-03-14')))->toBe(61);
});

it('starts a covered-call cycle on shares already held, with their cost as capital', function () {
    StockLot::factory()->for($this->account)->create([
        'ticker' => 'XYZ',
        'acquired_on' => '2025-06-02',
        'shares' => Quantity::fromInteger(100),
        'cost_per_share' => UnitPrice::fromDecimal('40'),
    ]);
    $call = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '45', '2026-03-20', '2026-02-02', 1, '1.00', '0.67');
    recordAssignment($call, '2026-03-20', 1);

    $cycle = ($this->cycles)()->sole();

    expect($cycle->kind)->toBe(WheelCycleKind::CoveredCall)
        ->and($cycle->start->format('Y-m-d'))->toBe('2026-02-02')
        ->and($cycle->end?->format('Y-m-d'))->toBe('2026-03-20')
        ->and($cycle->capitalAtRisk->format())->toBe('$4,000.00')
        ->and($cycle->netProfit()->format())->toBe('$599.33');
});

it('splits back-to-back cycles and keeps accounts and tickers apart', function () {
    $first = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-03-02', 1, '1.00', '0');
    recordExpiry($first, '2026-03-20', 1);
    $second = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-04-17', '2026-03-23', 1, '0.80', '0');
    recordExpiry($second, '2026-04-17', 1);
    $otherTicker = recordSellToOpen($this->account, OptionType::Put, 'ABC', '20', '2026-04-17', '2026-03-23', 1, '0.30', '0');
    $otherAccount = recordSellToOpen(Account::factory()->create(), OptionType::Put, 'XYZ', '50', '2026-04-17', '2026-03-23', 1, '0.90', '0');

    expect(($this->cycles)('XYZ'))->toHaveCount(3)
        ->and(($this->cycles)('ABC'))->toHaveCount(1)
        ->and(app(BuildWheelCycles::class)->handle([$this->account->id], 'XYZ')->map(fn ($cycle) => $cycle->netProfit()->format())->all())
        ->toBe(['$100.00', '$80.00']);
});

it('does not start a cycle when a put is sold while shares are already held', function () {
    recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-05', '100', '50');
    recordSellToOpen($this->account, OptionType::Put, 'XYZ', '45', '2026-03-20', '2026-02-02', 1, '1.00', '0.67');

    expect(($this->cycles)())->toBeEmpty();
});
