<?php

namespace App\Support;

use App\Enums\OptionType;
use App\Models\OptionPosition;
use App\Models\Price;
use App\Models\StockLot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Open stock and option positions today, valued with the latest manual
 * prices.
 *
 * Unrealized P/L:
 * - stock: (latest price − cost per share) × shares held, per lot;
 * - short option: premium for the open contracts − opening fees for them −
 *   latest option price × multiplier × open contracts (net of opening
 *   fees, before any closing fee);
 * - long option: latest option price × multiplier × open contracts −
 *   premium paid for them − opening fees for them (before any closing fee).
 *
 * Only sold puts can be assigned, so only they count towards the cash
 * needed if puts are assigned.
 * A position without a price shows "price needed" and is left out of the
 * totals; nothing is guessed.
 */
final readonly class OpenPositionsReport
{
    /**
     * @param  Collection<int, array{accountId: int, accountName: string, ticker: string, lots: \Illuminate\Database\Eloquent\Collection<int, StockLot>, shares: Quantity, cost: Money, price: Price|null, stale: bool, marketValue: Money|null, unrealized: Money|null}>  $stocks
     * @param  Collection<int, array{position: OptionPosition, openContracts: int, openPremium: Money, openFees: Money, price: Price|null, stale: bool, costToClose: Money|null, unrealized: Money|null, daysToExpiry: int, underlyingPrice: Price|null, underlyingStale: bool, moneyness: string|null, putObligation: Money}>  $options
     */
    private function __construct(public Collection $stocks, public Collection $options, public PriceBook $prices) {}

    /**
     * @param  list<int>|null  $accountIds  null for all accounts
     */
    public static function build(?array $accountIds, ?string $ticker, PriceBook $prices, CarbonImmutable $today): self
    {
        $lots = StockLot::query()
            ->with('account')
            ->notVoided()
            ->where('acquired_on', '<=', $today->format('Y-m-d'))
            ->withSoldShares($today)
            ->when($accountIds !== null, fn ($query) => $query->whereIn('account_id', $accountIds))
            ->when($ticker !== null, fn ($query) => $query->where('ticker', $ticker))
            ->get()
            ->filter(fn (StockLot $lot) => $lot->remainingShares()->units > 0);

        $stocks = $lots
            ->groupBy(fn (StockLot $lot) => $lot->account_id.'|'.$lot->ticker)
            ->map(function ($group) use ($prices) {
                $first = $group->first();
                $shares = Quantity::fromUnits($group->sum(fn (StockLot $lot) => $lot->remainingShares()->units));
                $cost = $group->reduce(fn (Money $total, StockLot $lot) => $total->plus($lot->remainingCostBasis()), Money::zero());
                $price = $prices->forStock($first->ticker);
                $marketValue = $price?->price->totalFor($shares);

                return [
                    'accountId' => $first->account_id,
                    'accountName' => $first->account->displayName(),
                    'ticker' => $first->ticker,
                    'lots' => $group->values(),
                    'shares' => $shares,
                    'cost' => $cost,
                    'price' => $price,
                    'stale' => $price !== null && $prices->isStale($price),
                    'marketValue' => $marketValue,
                    'unrealized' => $marketValue?->minus($cost),
                ];
            })
            ->sortBy(fn (array $row) => $row['accountName'].'|'.$row['ticker'])
            ->values()
            ->toBase();

        $options = OptionPosition::query()
            ->with('account')
            ->notVoided()
            ->open()
            ->withClosedContracts()
            ->when($accountIds !== null, fn ($query) => $query->whereIn('account_id', $accountIds))
            ->when($ticker !== null, fn ($query) => $query->where('underlying', $ticker))
            ->orderBy('expires_on')
            ->orderBy('underlying')
            ->get()
            ->map(function (OptionPosition $position) use ($prices, $today) {
                $openContracts = $position->openContracts();
                $openShares = Quantity::fromInteger($openContracts * $position->multiplier);
                $openPremium = $position->openPremium();
                $openFees = $position->openOpeningFees();
                $price = $prices->forOption($position);
                $costToClose = $price?->price->totalFor($openShares);
                $underlying = $prices->forStock($position->underlying);

                return [
                    'position' => $position,
                    'openContracts' => $openContracts,
                    'openPremium' => $openPremium,
                    'openFees' => $openFees,
                    'price' => $price,
                    'stale' => $price !== null && $prices->isStale($price),
                    'costToClose' => $costToClose,
                    'unrealized' => match (true) {
                        $costToClose === null => null,
                        $position->isLong() => $costToClose->minus($openPremium)->minus($openFees),
                        default => $openPremium->minus($openFees)->minus($costToClose),
                    },
                    'daysToExpiry' => (int) $today->diffInDays($position->expires_on, false),
                    'underlyingPrice' => $underlying,
                    'underlyingStale' => $underlying !== null && $prices->isStale($underlying),
                    'moneyness' => $underlying === null ? null : self::moneyness($position, $underlying),
                    'putObligation' => $position->option_type === OptionType::Put && ! $position->isLong() ? $position->strike->totalFor($openShares) : Money::zero(),
                ];
            })
            ->toBase();

        return new self($stocks, $options, $prices);
    }

    /**
     * "In the money", "at the money" or "out of the money" for a short option,
     * from the underlying price.
     */
    private static function moneyness(OptionPosition $position, Price $underlying): string
    {
        $comparison = $underlying->price->units <=> $position->strike->units;

        if ($comparison === 0) {
            return 'At the money';
        }

        $inTheMoney = $position->option_type === OptionType::Put ? $comparison < 0 : $comparison > 0;

        return $inTheMoney ? 'In the money' : 'Out of the money';
    }

    /**
     * Market value of the stocks that have a price; stocks without one are
     * left out (see stocksNeedingPrices).
     */
    public function stockMarketValueTotal(): Money
    {
        return $this->stocks->reduce(fn (Money $total, array $row) => $row['marketValue'] !== null ? $total->plus($row['marketValue']) : $total, Money::zero());
    }

    public function stockUnrealizedTotal(): Money
    {
        return $this->stocks->reduce(fn (Money $total, array $row) => $row['unrealized'] !== null ? $total->plus($row['unrealized']) : $total, Money::zero());
    }

    public function optionUnrealizedTotal(): Money
    {
        return $this->options->reduce(fn (Money $total, array $row) => $row['unrealized'] !== null ? $total->plus($row['unrealized']) : $total, Money::zero());
    }

    public function stocksNeedingPrices(): int
    {
        return $this->stocks->filter(fn (array $row) => $row['price'] === null)->count();
    }

    public function optionsNeedingPrices(): int
    {
        return $this->options->filter(fn (array $row) => $row['price'] === null)->count();
    }

    /**
     * Shares, open puts and calls, put cash obligation and stock market value
     * by account and ticker.
     *
     * @return Collection<string, array{accountName: string, ticker: string, shares: Quantity, marketValue: Money|null, puts: int, calls: int, putObligation: Money}>
     */
    public function exposure(): Collection
    {
        $rows = [];

        foreach ($this->stocks as $stock) {
            $key = $stock['accountName'].'|'.$stock['ticker'];
            $rows[$key] = ['accountName' => $stock['accountName'], 'ticker' => $stock['ticker'], 'shares' => $stock['shares'], 'marketValue' => $stock['marketValue'], 'puts' => 0, 'calls' => 0, 'putObligation' => Money::zero()];
        }

        foreach ($this->options as $option) {
            $position = $option['position'];
            $key = $position->account->displayName().'|'.$position->underlying;
            $rows[$key] ??= ['accountName' => $position->account->displayName(), 'ticker' => $position->underlying, 'shares' => Quantity::fromUnits(0), 'marketValue' => null, 'puts' => 0, 'calls' => 0, 'putObligation' => Money::zero()];

            if ($position->option_type === OptionType::Put) {
                $rows[$key]['puts'] += $option['openContracts'];
                $rows[$key]['putObligation'] = $rows[$key]['putObligation']->plus($option['putObligation']);
            } else {
                $rows[$key]['calls'] += $option['openContracts'];
            }
        }

        ksort($rows);

        return collect($rows);
    }
}
