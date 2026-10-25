<?php

use App\Actions\Stocks\SaveStockTrade;
use App\Enums\AuditEvent;
use App\Enums\PositionOrigin;
use App\Enums\TradeSide;
use App\Exceptions\InsufficientSharesException;
use App\Models\Account;
use App\Models\StockLot;
use App\Models\StockSaleAllocation;
use App\Models\StockTrade;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->saveStockTrade = app(SaveStockTrade::class);
});

describe('buys', function () {
    it('create a lot at the purchase price with the fee kept separately', function () {
        $buy = recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50', '0.65');

        $lot = $buy->lot()->sole();

        expect($lot->origin)->toBe(PositionOrigin::Purchase)
            ->and($lot->account_id)->toBe($this->account->id)
            ->and($lot->acquired_on->format('Y-m-d'))->toBe('2026-01-12')
            ->and($lot->shares->format())->toBe('100')
            ->and($lot->cost_per_share->format())->toBe('$50.00')
            ->and($lot->fees->format())->toBe('$0.65')
            ->and($lot->costBasis()->format())->toBe('$5,000.00');
    });

    it('do not create a lot for a sale', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
        recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '40', '55');

        expect(StockLot::count())->toBe(1);
    });
});

describe('fees', function () {
    it('include the sale fee and the matching part of the purchase fee in net profit', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50', '1.00');
        $sale = recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '40', '52', '0.65');

        $piece = $sale->saleAllocations()->sole();

        expect($piece->grossProfit()->format())->toBe('$80.00')
            ->and($piece->sale_fees->format())->toBe('$0.65')
            ->and($piece->purchase_fees->format())->toBe('$0.40')
            ->and($piece->fees()->format())->toBe('$1.05')
            ->and($piece->netProfit()->format())->toBe('$78.95');
    });

    it('split a purchase fee across several sales so the pieces add up to the whole fee', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '3', '10', '1.00');

        foreach (['2026-02-02', '2026-02-03', '2026-02-04'] as $date) {
            recordStockTrade($this->account, TradeSide::Sell, 'XYZ', $date, '1', '11');
        }

        $purchaseFees = StockSaleAllocation::query()->orderBy('id')->get()->map(fn ($piece) => $piece->purchase_fees->cents)->all();

        expect($purchaseFees)->toBe([33, 34, 33])
            ->and(array_sum($purchaseFees))->toBe(100);
    });

    it('split a sale fee across the lots it uses so the pieces add up to the whole fee', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-13', '50', '45');

        $sale = recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-02', '150', '52', '1.00');

        $saleFees = $sale->saleAllocations()->orderBy('id')->get()->map(fn ($piece) => $piece->sale_fees->cents)->all();

        expect($saleFees)->toBe([67, 33]);
    });

    it('split a lot cost across several sales so the pieces add up to the lot cost basis', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '3', '0.3333');

        foreach (['2026-02-02', '2026-02-03', '2026-02-04'] as $date) {
            recordStockTrade($this->account, TradeSide::Sell, 'XYZ', $date, '1', '0.3333');
        }

        $costs = StockSaleAllocation::query()->orderBy('id')->get()->map(fn ($piece) => $piece->cost->cents)->all();

        expect($costs)->toBe([33, 34, 33])
            ->and(array_sum($costs))->toBe(UnitPrice::fromDecimal('0.3333')->totalFor(Quantity::fromInteger(3))->cents);
    });
});

describe('selling more shares than held', function () {
    it('is refused and saves nothing', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');

        expect(fn () => recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '101', '52'))
            ->toThrow(InsufficientSharesException::class, 'Not enough XYZ shares in this account for the sale on Feb 12, 2026: 101 needed, 100 available.');

        expect(StockTrade::count())->toBe(1)
            ->and(StockSaleAllocation::count())->toBe(0);
    });

    it('does not count shares bought after the sale date', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-03-01', '100', '50');

        expect(fn () => recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '100', '52'))
            ->toThrow(InsufficientSharesException::class, '0 available');
    });

    it('counts shares bought earlier on the same day', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-03-02', '100', '50');
        $sale = recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-03-02', '100', '51');

        expect($sale->saleAllocations()->sole()->grossProfit()->format())->toBe('$100.00');
    });

    it('does not count shares of a voided lot', function () {
        StockLot::factory()->for($this->account)->voided()->create(['ticker' => 'XYZ']);

        expect(fn () => recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '1', '52'))
            ->toThrow(InsufficientSharesException::class);
    });
});

describe('corrections', function () {
    it('recalculate profit when a sale price is corrected, and keep the history', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
        $sale = recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '100', '52');

        $this->saveStockTrade->update($sale, ['price_per_share' => UnitPrice::fromDecimal('53')], 'Wrong price');

        expect($sale->saleAllocations()->sole()->grossProfit()->format())->toBe('$300.00')
            ->and($sale->auditEntries()->latest('id')->first())
            ->event->toBe(AuditEvent::Updated)
            ->reason->toBe('Wrong price')
            ->old_values->toBe(['price_per_share' => 520_000]);
    });

    it('update the lot when a buy is corrected', function () {
        $buy = recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');

        $this->saveStockTrade->update($buy, ['price_per_share' => UnitPrice::fromDecimal('49.5'), 'fees' => Money::fromCents(65)], 'Price from confirmation');

        expect($buy->lot()->sole())
            ->cost_per_share->format()->toBe('$49.50')
            ->fees->format()->toBe('$0.65');
    });

    it('re-run oldest-first matching when an older purchase date is corrected', function () {
        $firstBuy = recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-05', '100', '40');
        $sale = recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '100', '52');

        expect($sale->saleAllocations()->sole()->grossProfit()->format())->toBe('$1,200.00');

        $this->saveStockTrade->update($firstBuy, ['trade_date' => '2026-01-02'], 'Bought earlier than entered');

        expect($sale->saleAllocations()->sole()->grossProfit()->format())->toBe('$200.00');
    });

    it('recalculate both tickers when a ticker is corrected', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
        recordStockTrade($this->account, TradeSide::Buy, 'ABC', '2026-01-12', '100', '20');
        $sale = recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '100', '52');

        $this->saveStockTrade->update($sale, ['ticker' => 'ABC'], 'Wrong ticker');

        expect($sale->saleAllocations()->sole())
            ->ticker->toBe('ABC')
            ->grossProfit()->format()->toBe('$3,200.00');
    });

    it('refuse a correction that would leave a sale without enough shares, and keep the original', function () {
        $buy = recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
        recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '100', '52');

        expect(fn () => $this->saveStockTrade->update($buy, ['shares' => Quantity::fromInteger(50)], 'Fewer shares'))
            ->toThrow(InsufficientSharesException::class);

        expect($buy->fresh()->shares->format())->toBe('100')
            ->and($buy->lot()->sole()->shares->format())->toBe('100')
            ->and($buy->auditEntries()->count())->toBe(1)
            ->and(StockSaleAllocation::count())->toBe(1);
    });
});

describe('voiding', function () {
    it('a sale gives its shares back to the lot', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
        $sale = recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '60', '52');

        $this->saveStockTrade->void($sale, 'Entered twice');

        expect(StockSaleAllocation::count())->toBe(0)
            ->and(StockLot::query()->withSoldShares()->sole()->remainingShares()->format())->toBe('100')
            ->and($sale->fresh()->isVoided())->toBeTrue()
            ->and($sale->auditEntries()->latest('id')->first()->event)->toBe(AuditEvent::Voided);
    });

    it('a buy also voids its lot', function () {
        $buy = recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');

        $this->saveStockTrade->void($buy, 'Order was cancelled');

        expect($buy->lot()->sole()->isVoided())->toBeTrue();
    });

    it('a buy whose shares were sold is refused', function () {
        $buy = recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
        recordStockTrade($this->account, TradeSide::Sell, 'XYZ', '2026-02-12', '10', '52');

        expect(fn () => $this->saveStockTrade->void($buy, 'Mistake'))->toThrow(InsufficientSharesException::class);

        expect($buy->fresh()->isVoided())->toBeFalse()
            ->and($buy->lot()->sole()->isVoided())->toBeFalse();
    });
});
