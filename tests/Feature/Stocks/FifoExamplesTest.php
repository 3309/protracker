<?php

use App\Enums\AccountType;
use App\Enums\PositionOrigin;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\StockLot;
use App\Models\StockSaleAllocation;
use App\Models\StockTrade;
use App\Support\Quantity;
use App\Support\UnitPrice;

it('Example D: a sale spanning two lots uses the oldest lot first', function () {
    $account = Account::factory()->create();
    recordStockTrade($account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    recordStockTrade($account, TradeSide::Buy, 'XYZ', '2026-02-03', '100', '45');

    $sale = recordStockTrade($account, TradeSide::Sell, 'XYZ', '2026-03-02', '150', '52');

    $pieces = $sale->saleAllocations()->with('lot')->orderBy('id')->get();

    expect($pieces)->toHaveCount(2)
        ->and($pieces[0]->lot->acquired_on->format('Y-m-d'))->toBe('2026-01-12')
        ->and($pieces[0]->shares->format())->toBe('100')
        ->and($pieces[0]->grossProfit()->format())->toBe('$200.00')
        ->and($pieces[1]->lot->acquired_on->format('Y-m-d'))->toBe('2026-02-03')
        ->and($pieces[1]->shares->format())->toBe('50')
        ->and($pieces[1]->grossProfit()->format())->toBe('$350.00')
        ->and($pieces[0]->grossProfit()->plus($pieces[1]->grossProfit())->format())->toBe('$550.00');

    $lots = StockLot::query()->withSoldShares()->orderBy('acquired_on')->get();

    expect($lots[0]->remainingShares()->isZero())->toBeTrue()
        ->and($lots[1]->remainingShares()->format())->toBe('50')
        ->and($lots[1]->cost_per_share->format())->toBe('$45.00')
        ->and($lots[1]->remainingCostBasis()->format())->toBe('$2,250.00');
});

it('Example H: a sale in one account never uses another account\'s lot', function () {
    $taxable = Account::factory()->create(['account_type' => AccountType::Taxable]);
    $ira = Account::factory()->create(['account_type' => AccountType::Ira]);
    recordStockTrade($taxable, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '50');
    recordStockTrade($ira, TradeSide::Buy, 'XYZ', '2026-01-05', '100', '45');

    $sale = recordStockTrade($taxable, TradeSide::Sell, 'XYZ', '2026-03-02', '100', '52');

    $piece = $sale->saleAllocations()->with('lot')->sole();

    expect($piece->lot->account_id)->toBe($taxable->id)
        ->and($piece->grossProfit()->format())->toBe('$200.00');

    $iraLot = StockLot::query()->where('account_id', $ira->id)->withSoldShares()->sole();

    expect($iraLot->remainingShares()->format())->toBe('100')
        ->and($iraLot->remainingCostBasis()->format())->toBe('$4,500.00');
});

it('Example I: an opening lot from 2025 sold in 2026 gives +$200.00 and is not a 2026 purchase', function () {
    $account = Account::factory()->create();
    StockLot::factory()->for($account)->create([
        'ticker' => 'XYZ',
        'acquired_on' => '2025-06-02',
        'shares' => Quantity::fromInteger(100),
        'cost_per_share' => UnitPrice::fromDecimal('50'),
    ]);

    $sale = recordStockTrade($account, TradeSide::Sell, 'XYZ', '2026-02-10', '100', '52');

    $piece = $sale->saleAllocations()->with('lot')->sole();

    expect($piece->lot->origin)->toBe(PositionOrigin::OpeningBalance)
        ->and($piece->lot->acquired_on->format('Y-m-d'))->toBe('2025-06-02')
        ->and($piece->grossProfit()->format())->toBe('$200.00')
        ->and($piece->netProfit()->format())->toBe('$200.00')
        ->and(StockTrade::query()->where('side', TradeSide::Buy)->exists())->toBeFalse()
        ->and(StockSaleAllocation::count())->toBe(1);
});
