<?php

use App\Actions\Options\SaveOptionTrade;
use App\Enums\AuditEvent;
use App\Enums\OptionEventType;
use App\Enums\OptionType;
use App\Enums\TradeSide;
use App\Exceptions\InsufficientSharesException;
use App\Exceptions\OptionPositionException;
use App\Models\Account;
use App\Models\OptionEvent;
use App\Models\StockLot;
use App\Models\StockSaleAllocation;
use App\Models\StockTrade;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->saveOptionTrade = app(SaveOptionTrade::class);
});

describe('expiry', function () {
    beforeEach(function () {
        $this->put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 3, '1.20', '2.01');
    });

    it('realizes the premium less the opening fee for the expired contracts', function () {
        $expiry = recordExpiry($this->put, '2026-03-20', 1);

        expect($expiry->event_type)->toBe(OptionEventType::Expired)
            ->and($expiry->closingCost()->isZero())->toBeTrue()
            ->and($expiry->netProfit()->format())->toBe('$119.33')
            ->and($this->put->openContracts())->toBe(2);
    });

    it('may be dated up to 7 days after expiration, as brokers often post it later', function () {
        expect(recordExpiry($this->put, '2026-03-27', 3)->exists)->toBeTrue();
    });

    it('is refused more than 7 days after expiration', function () {
        expect(fn () => recordExpiry($this->put, '2026-03-28', 3))->toThrow(OptionPositionException::class, 'no more than 7 days after expiration');
    });

    it('is refused before the expiration date', function () {
        expect(fn () => recordExpiry($this->put, '2026-03-19', 3))->toThrow(OptionPositionException::class, 'cannot expire before its expiration date');

        expect(OptionEvent::count())->toBe(0);
    });
});

describe('assignment', function () {
    it('of part of a put buys only those shares at the strike and leaves the rest open', function () {
        $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 3, '1.20', '2.01');

        $assignment = recordAssignment($put, '2026-03-20', 2);

        expect($assignment->stockTrade->shares->format())->toBe('200')
            ->and($assignment->stockTrade->settlement_date->format('Y-m-d'))->toBe('2026-03-23')
            ->and($assignment->allocated_premium->format())->toBe('$240.00')
            ->and($put->openContracts())->toBe(1);
    });

    it('may happen early, before expiration', function () {
        $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');

        expect(recordAssignment($put, '2026-03-02', 1)->stockTrade->trade_date->format('Y-m-d'))->toBe('2026-03-02');
    });

    it('charges the assignment fee on the stock trade, not the option', function () {
        $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');

        $assignment = recordAssignment($put, '2026-03-20', 1, '5.00');

        expect($assignment->totalFees()->format())->toBe('$0.67')
            ->and($assignment->netProfit()->format())->toBe('$119.33')
            ->and($assignment->stockTrade->fees->format())->toBe('$5.00')
            ->and($assignment->stockTrade->lot->fees->format())->toBe('$5.00');
    });

    it('of a call sells the oldest shares first', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-05', '100', '40');
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-20', '100', '48');
        $call = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '52', '2026-03-20', '2026-02-02', 1, '0.80', '0.67');

        $assignment = recordAssignment($call, '2026-03-20', 1);

        $piece = StockSaleAllocation::query()->where('stock_trade_id', $assignment->stockTrade->id)->with('lot')->sole();

        expect($piece->lot->acquired_on->format('Y-m-d'))->toBe('2026-01-05')
            ->and($piece->grossProfit()->format())->toBe('$1,200.00');
    });

    it('of a call without enough shares is refused and nothing is saved', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-05', '60', '40');
        $call = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '52', '2026-03-20', '2026-02-02', 1, '0.80', '0.67');

        expect(fn () => recordAssignment($call, '2026-03-20', 1))
            ->toThrow(InsufficientSharesException::class, '100 needed, 60 available');

        expect(OptionEvent::count())->toBe(0)
            ->and(StockTrade::query()->where('side', TradeSide::Sell)->exists())->toBeFalse()
            ->and($call->openContracts())->toBe(1);
    });

    it('when corrected also corrects its stock trade', function () {
        $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 2, '1.20', '1.34');
        $assignment = recordAssignment($put, '2026-03-20', 1);

        $this->saveOptionTrade->updateEvent($assignment, ['contracts' => 2, 'event_date' => '2026-03-23'], 'Both contracts were assigned');

        expect($assignment->stockTrade->fresh())
            ->shares->format()->toBe('200')
            ->trade_date->format('Y-m-d')->toBe('2026-03-23')
            ->and($assignment->stockTrade->auditEntries()->latest('id')->first()->reason)->toBe('Both contracts were assigned');
    });

    it('follows a corrected strike on its position', function () {
        $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');
        $assignment = recordAssignment($put, '2026-03-20', 1);

        $this->saveOptionTrade->updatePosition($put, ['strike' => UnitPrice::fromDecimal('47.50')], 'Strike was 47.50');

        expect($assignment->stockTrade->fresh()->price_per_share->format())->toBe('$47.50')
            ->and($assignment->stockTrade->lot->fresh()->cost_per_share->format())->toBe('$47.50');
    });

    it('stops put and call from being swapped on the position', function () {
        $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');
        recordAssignment($put, '2026-03-20', 1);

        expect(fn () => $this->saveOptionTrade->updatePosition($put, ['option_type' => OptionType::Call], 'Was a call'))
            ->toThrow(OptionPositionException::class, 'Void them before changing put or call');

        expect($put->fresh()->option_type)->toBe(OptionType::Put);
    });

    it('when voided also voids its stock trade and lot', function () {
        $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');
        $assignment = recordAssignment($put, '2026-03-20', 1);

        $this->saveOptionTrade->voidEvent($assignment, 'Assignment reversed by broker');

        expect($assignment->stockTrade->fresh()->isVoided())->toBeTrue()
            ->and(StockLot::query()->notVoided()->exists())->toBeFalse()
            ->and($put->openContracts())->toBe(1)
            ->and($assignment->auditEntries()->latest('id')->first()->event)->toBe(AuditEvent::Voided);
    });

    it('cannot be voided once its shares have been sold', function () {
        $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');
        $assignment = recordAssignment($put, '2026-03-20', 1);
        recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-04-01', '100', '55');

        expect(fn () => $this->saveOptionTrade->voidEvent($assignment, 'Mistake'))->toThrow(InsufficientSharesException::class);

        expect($assignment->fresh()->isVoided())->toBeFalse()
            ->and($assignment->stockTrade->fresh()->isVoided())->toBeFalse();
    });
});

describe('rolls', function () {
    beforeEach(function () {
        $this->call = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '52', '2026-03-20', '2026-02-23', 3, '0.80', '2.01');
    });

    it('can roll part of a position, leaving the rest open', function () {
        $newLeg = recordRoll($this->call, '2026-03-13', 2, '1.50', '1.34', '55', '2026-04-17', 2, '1.60', '1.34');

        expect($this->call->openContracts())->toBe(1)
            ->and($newLeg->contracts)->toBe(2)
            ->and($newLeg->underlying)->toBe('XYZ')
            ->and($newLeg->option_type)->toBe(OptionType::Call)
            ->and($newLeg->opened_on->format('Y-m-d'))->toBe('2026-03-13')
            ->and($newLeg->rolledFromEvent?->event_type)->toBe(OptionEventType::Rolled);
    });

    it('extends the same chain when a rolled leg is rolled again', function () {
        $second = recordRoll($this->call, '2026-03-13', 3, '1.50', '2.01', '55', '2026-04-17', 3, '1.60', '2.01');
        $third = recordRoll($second, '2026-04-10', 3, '2.00', '2.01', '57', '2026-05-15', 3, '2.20', '2.01');

        expect($third->roll_chain_id)->toBe($this->call->id)
            ->and($second->fresh()->roll_chain_id)->toBe($this->call->id);
    });

    it('is undone by voiding the new leg, which reopens the earlier contracts', function () {
        $newLeg = recordRoll($this->call, '2026-03-13', 3, '1.50', '2.01', '55', '2026-04-17', 3, '1.60', '2.01');

        $this->saveOptionTrade->voidPosition($newLeg, 'Roll entered by mistake');

        expect($newLeg->fresh()->isVoided())->toBeTrue()
            ->and($newLeg->rolledFromEvent?->fresh()->isVoided())->toBeTrue()
            ->and($this->call->openContracts())->toBe(3);
    });

    it('cannot be undone from the buy-back itself', function () {
        recordRoll($this->call, '2026-03-13', 3, '1.50', '2.01', '55', '2026-04-17', 3, '1.60', '2.01');

        expect(fn () => $this->saveOptionTrade->voidEvent($this->call->events()->sole(), 'Mistake'))
            ->toThrow(OptionPositionException::class, 'void the new option it opened');
    });

    it('cannot be undone while the new leg has its own events', function () {
        $newLeg = recordRoll($this->call, '2026-03-13', 3, '1.50', '2.01', '55', '2026-04-17', 3, '1.60', '2.01');
        recordExpiry($newLeg, '2026-04-17', 3);

        expect(fn () => $this->saveOptionTrade->voidPosition($newLeg, 'Mistake'))
            ->toThrow(OptionPositionException::class, 'Void those first, then void the roll.');
    });

    it('must buy back on or before expiration', function () {
        expect(fn () => recordRoll($this->call, '2026-03-21', 3, '1.50', '2.01', '55', '2026-04-17', 3, '1.60', '2.01'))
            ->toThrow(OptionPositionException::class, 'on or before expiration');
    });
});

it('flags an open option after its expiration date', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-03-20', '2026-02-02', 1, '1.20', '0.67');

    expect($put->isPastExpiration(CarbonImmutable::parse('2026-03-20')))->toBeFalse()
        ->and($put->isPastExpiration(CarbonImmutable::parse('2026-03-21')))->toBeTrue();

    recordExpiry($put, '2026-03-20', 1);

    expect($put->isPastExpiration(CarbonImmutable::parse('2026-03-21')))->toBeFalse();
});
