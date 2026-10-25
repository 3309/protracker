<?php

namespace App\Actions\Stocks;

use App\Enums\PositionOrigin;
use App\Exceptions\InsufficientSharesException;
use App\Models\StockLot;
use App\Models\StockTrade;

/**
 * Records, corrects and voids stock trades, keeping each buy's lot in step
 * and recalculating realized profit for the ticker afterwards.
 *
 * Every change runs in one database transaction: if it would leave a sale
 * without enough shares, nothing is saved.
 */
class SaveStockTrade
{
    public function __construct(private RebuildStockAllocations $rebuildAllocations) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InsufficientSharesException
     */
    public function create(array $attributes): StockTrade
    {
        $trade = new StockTrade($attributes);

        return $this->rebuildAllocations->afterChange($trade->account_id, [$trade->ticker], function () use ($trade): StockTrade {
            $trade->save();

            $this->syncLot($trade, null);

            return $trade;
        });
    }

    /**
     * Correct a trade. The account and buy/sell side cannot change; void the
     * trade and enter a new one instead.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InsufficientSharesException
     */
    public function update(StockTrade $trade, array $attributes, string $reason): void
    {
        $originalTicker = $trade->ticker;
        $trade->fill($attributes);

        $this->rebuildAllocations->afterChange($trade->account_id, [$originalTicker, $trade->ticker], function () use ($trade, $reason): void {
            $trade->withAuditReason($reason)->save();

            $this->syncLot($trade, $reason);
        });
    }

    /**
     * @throws InsufficientSharesException
     */
    public function void(StockTrade $trade, string $reason): void
    {
        $this->rebuildAllocations->afterChange($trade->account_id, [$trade->ticker], function () use ($trade, $reason): void {
            $trade->void($reason);

            $trade->lot?->void($reason);
        });
    }

    /**
     * Create or update the lot a buy holds. Under Method B its cost per share
     * is the purchase price; the buy's fee is kept separately on the lot.
     */
    private function syncLot(StockTrade $trade, ?string $reason): void
    {
        if (! $trade->isBuy()) {
            return;
        }

        $lot = $trade->lot ?? new StockLot([
            'account_id' => $trade->account_id,
            'stock_trade_id' => $trade->id,
            'origin' => $trade->isFromAssignment() ? PositionOrigin::PutAssignment : PositionOrigin::Purchase,
        ]);

        $lot->withAuditReason($reason)->fill([
            'ticker' => $trade->ticker,
            'acquired_on' => $trade->trade_date,
            'shares' => $trade->shares,
            'cost_per_share' => $trade->price_per_share,
            'fees' => $trade->fees,
        ])->save();

        $trade->setRelation('lot', $lot);
    }
}
