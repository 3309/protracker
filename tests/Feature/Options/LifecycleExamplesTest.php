<?php

use App\Enums\OptionType;
use App\Enums\PositionOrigin;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\OptionPosition;
use App\Models\StockSaleAllocation;
use App\Support\Money;

beforeEach(function () {
    $this->account = Account::factory()->create();
});

/**
 * Example A without the dividend: the $50.00 dividend, the $448.66 cycle
 * total and the cash cross-check need the Phase 6 cash ledger.
 */
it('Example A: put assigned, then call assigned, gives put +$119.33, call +$79.33 and stock +$200.00', function () {
    $put = recordSellToOpen($this->account, OptionType::Put, 'XYZ', '50', '2026-02-20', '2026-01-12', 1, '1.20', '0.67');
    $putAssignment = recordAssignment($put, '2026-02-20', 1);

    $boughtShares = $putAssignment->stockTrade;

    expect($boughtShares->side)->toBe(TradeSide::Buy)
        ->and($boughtShares->shares->format())->toBe('100')
        ->and($boughtShares->price_per_share->format())->toBe('$50.00')
        ->and($boughtShares->grossAmount()->format())->toBe('$5,000.00')
        ->and($boughtShares->lot->origin)->toBe(PositionOrigin::PutAssignment)
        ->and($boughtShares->lot->cost_per_share->format())->toBe('$50.00')
        ->and($putAssignment->netProfit()->format())->toBe('$119.33');

    $call = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '52', '2026-03-20', '2026-02-23', 1, '0.80', '0.67');
    $callAssignment = recordAssignment($call, '2026-03-20', 1);

    $soldShares = $callAssignment->stockTrade;
    $stockPiece = StockSaleAllocation::query()->where('stock_trade_id', $soldShares->id)->sole();

    expect($soldShares->side)->toBe(TradeSide::Sell)
        ->and($soldShares->grossAmount()->format())->toBe('$5,200.00')
        ->and($callAssignment->netProfit()->format())->toBe('$79.33')
        ->and($stockPiece->stock_lot_id)->toBe($boughtShares->lot->id)
        ->and($stockPiece->netProfit()->format())->toBe('$200.00')
        ->and($putAssignment->netProfit()->plus($callAssignment->netProfit())->plus($stockPiece->netProfit())->format())->toBe('$398.66');
});

it('Example C: a rolled call shows the closed leg, the roll credit and the chain result separately', function () {
    $originalCall = recordSellToOpen($this->account, OptionType::Call, 'XYZ', '52', '2026-03-20', '2026-02-23', 1, '0.80', '0.67');

    $newCall = recordRoll($originalCall, '2026-03-13', 1, '1.50', '0.67', '55', '2026-04-17', 1, '1.60', '0.67');

    $rolledLeg = $originalCall->events()->sole();
    $rolledLeg->setRelation('position', $originalCall);

    expect($rolledLeg->grossProfit()->format())->toBe('-$70.00')
        ->and($rolledLeg->netProfit()->format())->toBe('-$71.34')
        ->and($newCall->rollNetCredit()?->format())->toBe('$8.66')
        ->and($newCall->roll_chain_id)->toBe($originalCall->id)
        ->and($originalCall->fresh()->roll_chain_id)->toBe($originalCall->id);

    recordExpiry($newCall, '2026-04-17', 1);

    $chainResult = OptionPosition::query()->where('roll_chain_id', $originalCall->id)->get()
        ->reduce(fn ($total, OptionPosition $leg) => $total->plus($leg->realizedNetProfit()), Money::zero());

    expect($newCall->realizedNetProfit()->format())->toBe('$159.33')
        ->and($chainResult->format())->toBe('$87.99');
});
