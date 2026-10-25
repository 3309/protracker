<?php

namespace App\Models;

use App\Casts\CalendarDate;
use App\Casts\MoneyCast;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One piece of a stock sale: the shares it took from one lot and the
 * realized profit or loss on them.
 *
 * Under Method B the cost is the lot's actual purchase price. Fees are the
 * sale fee and purchase fee belonging to these shares, kept separately so
 * gross profit, fees and net profit can all be shown.
 *
 * These rows are calculated by RebuildStockAllocations and are replaced
 * whenever the trades or lots change, so they are never edited directly.
 *
 * @property int $id
 * @property int $account_id
 * @property string $ticker
 * @property int $stock_trade_id
 * @property int $stock_lot_id
 * @property CarbonImmutable $sold_on
 * @property Quantity $shares
 * @property Money $proceeds
 * @property Money $cost
 * @property Money $sale_fees
 * @property Money $purchase_fees
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read StockTrade $sale
 * @property-read StockLot $lot
 */
#[Fillable([
    'account_id',
    'ticker',
    'stock_trade_id',
    'stock_lot_id',
    'sold_on',
    'shares',
    'proceeds',
    'cost',
    'sale_fees',
    'purchase_fees',
])]
class StockSaleAllocation extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sold_on' => CalendarDate::class,
            'shares' => Quantity::class,
            'proceeds' => MoneyCast::class,
            'cost' => MoneyCast::class,
            'sale_fees' => MoneyCast::class,
            'purchase_fees' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<StockTrade, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(StockTrade::class, 'stock_trade_id');
    }

    /**
     * @return BelongsTo<StockLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }

    /**
     * Proceeds minus cost, before fees.
     */
    public function grossProfit(): Money
    {
        return $this->proceeds->minus($this->cost);
    }

    public function fees(): Money
    {
        return $this->sale_fees->plus($this->purchase_fees);
    }

    public function netProfit(): Money
    {
        return $this->grossProfit()->minus($this->fees());
    }
}
