<?php

namespace App\Actions\Stocks;

use App\Enums\TradeSide;
use App\Exceptions\InsufficientSharesException;
use App\Models\StockLot;
use App\Models\StockSaleAllocation;
use App\Models\StockTrade;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out, for one ticker in one account, which lots every sale used.
 *
 * Sales are matched oldest lot first (FIFO) by trade date, only against
 * lots of the same account bought on or before the sale date. Each piece
 * records its share of the sale proceeds, the lot cost, the sale fee and
 * the purchase fee. Pieces are split with running totals, so they always
 * add up exactly to the whole sale and the whole lot.
 *
 * The allocations are rebuilt from scratch every time, so any correction
 * to a trade or lot gives the same result as if it had been right from the
 * start.
 */
class RebuildStockAllocations
{
    /**
     * Make a change to trades or lots, then rebuild the affected tickers.
     * If any sale would be left without enough shares, the whole change is
     * rolled back.
     *
     * @template TResult
     *
     * @param  list<string>  $tickers
     * @param  Closure(): TResult  $change
     * @return TResult
     *
     * @throws InsufficientSharesException
     */
    public function afterChange(int $accountId, array $tickers, Closure $change): mixed
    {
        return DB::transaction(function () use ($accountId, $tickers, $change) {
            $result = $change();

            foreach (array_unique($tickers) as $ticker) {
                $this->handle($accountId, $ticker);
            }

            return $result;
        });
    }

    /**
     * @throws InsufficientSharesException
     */
    public function handle(int $accountId, string $ticker): void
    {
        DB::transaction(function () use ($accountId, $ticker): void {
            StockSaleAllocation::query()->where('account_id', $accountId)->where('ticker', $ticker)->delete();

            $lots = StockLot::query()
                ->where('account_id', $accountId)
                ->where('ticker', $ticker)
                ->notVoided()
                ->orderBy('acquired_on')
                ->orderBy('id')
                ->get();

            $remainingUnits = $lots->mapWithKeys(fn (StockLot $lot) => [$lot->id => $lot->shares->units])->all();

            $sales = StockTrade::query()
                ->where('account_id', $accountId)
                ->where('ticker', $ticker)
                ->where('side', TradeSide::Sell)
                ->notVoided()
                ->orderBy('trade_date')
                ->orderBy('id')
                ->get();

            foreach ($sales as $sale) {
                $this->allocateSale($sale, $lots, $remainingUnits);
            }
        });
    }

    /**
     * @param  Collection<int, StockLot>  $lots
     * @param  array<int, int>  $remainingUnits  unsold shares (in millionths) by lot ID
     *
     * @throws InsufficientSharesException
     */
    private function allocateSale(StockTrade $sale, Collection $lots, array &$remainingUnits): void
    {
        $eligibleLots = $lots->filter(fn (StockLot $lot) => $lot->acquired_on->format('Y-m-d') <= $sale->trade_date->format('Y-m-d')
            && $remainingUnits[$lot->id] > 0);

        $availableUnits = $eligibleLots->sum(fn (StockLot $lot) => $remainingUnits[$lot->id]);
        $saleUnits = $sale->shares->units;

        if ($availableUnits < $saleUnits) {
            throw InsufficientSharesException::forSale($sale, Quantity::fromUnits($availableUnits));
        }

        $saleUnitsBefore = 0;

        foreach ($eligibleLots as $lot) {
            if ($saleUnitsBefore === $saleUnits) {
                break;
            }

            $takenUnits = min($remainingUnits[$lot->id], $saleUnits - $saleUnitsBefore);
            $saleUnitsAfter = $saleUnitsBefore + $takenUnits;
            $lotUnitsBefore = $lot->shares->units - $remainingUnits[$lot->id];
            $lotUnitsAfter = $lotUnitsBefore + $takenUnits;

            StockSaleAllocation::create([
                'account_id' => $sale->account_id,
                'ticker' => $sale->ticker,
                'stock_trade_id' => $sale->id,
                'stock_lot_id' => $lot->id,
                'sold_on' => $sale->trade_date,
                'shares' => Quantity::fromUnits($takenUnits),
                'proceeds' => $this->slice($sale->price_per_share, $saleUnitsBefore, $saleUnitsAfter),
                'cost' => $this->slice($lot->cost_per_share, $lotUnitsBefore, $lotUnitsAfter),
                'sale_fees' => $this->feeSlice($sale->fees, $saleUnitsBefore, $saleUnitsAfter, $saleUnits),
                'purchase_fees' => $this->feeSlice($lot->fees, $lotUnitsBefore, $lotUnitsAfter, $lot->shares->units),
            ]);

            $remainingUnits[$lot->id] -= $takenUnits;
            $saleUnitsBefore = $saleUnitsAfter;
        }
    }

    /**
     * The value of the shares between two running totals at a per-share price.
     */
    private function slice(UnitPrice $price, int $unitsBefore, int $unitsAfter): Money
    {
        return $price->totalFor(Quantity::fromUnits($unitsAfter))
            ->minus($price->totalFor(Quantity::fromUnits($unitsBefore)));
    }

    /**
     * The part of a fee belonging to the shares between two running totals.
     */
    private function feeSlice(Money $fee, int $unitsBefore, int $unitsAfter, int $totalUnits): Money
    {
        return $fee->share($unitsAfter, $totalUnits)->minus($fee->share($unitsBefore, $totalUnits));
    }
}
