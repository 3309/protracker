<?php

namespace App\Actions\Options;

use App\Exceptions\UncoveredCallException;
use App\Models\StockSaleAllocation;
use App\Support\CoveredCallCapacity;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Makes sure sold calls entered by hand are covered by shares that are not
 * already covering another call (see CoveredCallCapacity).
 *
 * The change is made first and the shares are counted afterwards, inside
 * the same database transaction: saving takes the database's write lock, so
 * a second sale submitted at the same time waits and then counts this one.
 * If the calls are not covered, the whole change is rolled back.
 */
class EnsureCallsCovered
{
    /**
     * Make a change that sells calls on the ticker, then check that its open
     * calls are covered on every date from $from to $until on which shares
     * are sold or another call is sold.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $change
     * @return TResult
     *
     * @throws UncoveredCallException
     */
    public function afterChange(int $accountId, string $ticker, CarbonImmutable $from, CarbonImmutable $until, Closure $change): mixed
    {
        return DB::transaction(function () use ($accountId, $ticker, $from, $until, $change) {
            $result = $change();

            foreach ($this->checkDates($accountId, $ticker, $from, $until) as $date) {
                $capacity = CoveredCallCapacity::for($accountId, $ticker, $date);

                if ($capacity->available()->units < 0) {
                    throw UncoveredCallException::on($capacity);
                }
            }

            return $result;
        });
    }

    /**
     * The first date, and every later date up to $until on which the free
     * shares can go down: a sale of shares or another call sold.
     *
     * @return list<CarbonImmutable>
     */
    private function checkDates(int $accountId, string $ticker, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $range = [$from->format('Y-m-d'), $until->max($from)->format('Y-m-d')];

        return array_values(collect([$range[0]])
            ->merge(CoveredCallCapacity::soldCalls($accountId, $ticker)->whereBetween('opened_on', $range)->toBase()->pluck('opened_on'))
            ->merge(StockSaleAllocation::query()->where('account_id', $accountId)->where('ticker', $ticker)->whereBetween('sold_on', $range)->toBase()->pluck('sold_on'))
            ->map(fn (mixed $date) => (string) $date)
            ->unique()
            ->sort()
            ->map(fn (string $date) => CarbonImmutable::parse($date)->startOfDay())
            ->all());
    }
}
