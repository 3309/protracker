<?php

namespace App\Support;

use App\Models\Dividend;
use App\Models\OptionEvent;
use App\Models\StockSaleAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Realized profit in a period: stock sale pieces by sale date, option
 * closes (bought back, rolled, expired or assigned) by event date, and
 * confirmed dividends by pay date. Voided records are left out.
 */
final readonly class RealizedProfit
{
    /**
     * @param  Collection<int, StockSaleAllocation>  $stockPieces
     * @param  Collection<int, OptionEvent>  $optionCloses
     * @param  Collection<int, Dividend>  $dividends
     */
    public function __construct(
        public Collection $stockPieces,
        public Collection $optionCloses,
        public Collection $dividends,
    ) {}

    /**
     * @param  list<int>|null  $accountIds  null for all accounts
     */
    public static function for(ReportingPeriod $period, ?array $accountIds = null, ?string $ticker = null): self
    {
        $stockPieces = StockSaleAllocation::query()->with(['account', 'lot', 'sale']);
        self::filter($stockPieces, $accountIds, $ticker, 'ticker');
        $period->applyTo($stockPieces, 'sold_on');

        $optionCloses = OptionEvent::query()->with(['account', 'position'])->notVoided();
        self::filter($optionCloses, $accountIds, null, 'ticker');
        $period->applyTo($optionCloses, 'event_date');

        if ($ticker !== null) {
            $optionCloses->whereHas('position', fn (Builder $position) => $position->where('underlying', $ticker));
        }

        $dividends = Dividend::query()->with('account')->notVoided()->whereNotNull('actual_amount');
        self::filter($dividends, $accountIds, $ticker, 'ticker');
        $period->applyTo($dividends, 'pay_date');

        return new self(
            $stockPieces->orderBy('sold_on')->orderBy('stock_trade_id')->orderBy('id')->get(),
            $optionCloses->orderBy('event_date')->orderBy('id')->get(),
            $dividends->orderBy('pay_date')->orderBy('id')->get(),
        );
    }

    /**
     * @return array{gross: Money, fees: Money, net: Money}
     */
    public function stockTotals(): array
    {
        return [
            'gross' => self::sum($this->stockPieces, fn (StockSaleAllocation $piece) => $piece->grossProfit()),
            'fees' => self::sum($this->stockPieces, fn (StockSaleAllocation $piece) => $piece->fees()),
            'net' => self::sum($this->stockPieces, fn (StockSaleAllocation $piece) => $piece->netProfit()),
        ];
    }

    /**
     * @return array{gross: Money, fees: Money, net: Money}
     */
    public function optionTotals(): array
    {
        return [
            'gross' => self::sum($this->optionCloses, fn (OptionEvent $event) => $event->grossProfit()),
            'fees' => self::sum($this->optionCloses, fn (OptionEvent $event) => $event->totalFees()),
            'net' => self::sum($this->optionCloses, fn (OptionEvent $event) => $event->netProfit()),
        ];
    }

    public function dividendTotal(): Money
    {
        return self::sum($this->dividends, fn (Dividend $dividend) => $dividend->actual_amount ?? Money::zero());
    }

    /**
     * Stocks + options (both net of fees) + confirmed dividends.
     */
    public function combinedNet(): Money
    {
        return $this->stockTotals()['net']->plus($this->optionTotals()['net'])->plus($this->dividendTotal());
    }

    /**
     * @template TItem of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TItem>  $items
     * @param  callable(TItem): Money  $amount
     */
    private static function sum(Collection $items, callable $amount): Money
    {
        return $items->reduce(fn (Money $total, mixed $item) => $total->plus($amount($item)), Money::zero());
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>|null  $accountIds
     */
    private static function filter(Builder $query, ?array $accountIds, ?string $ticker, string $tickerColumn): void
    {
        if ($accountIds !== null) {
            $query->whereIn('account_id', $accountIds);
        }

        if ($ticker !== null) {
            $query->where($tickerColumn, $ticker);
        }
    }
}
