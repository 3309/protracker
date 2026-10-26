<?php

namespace App\Support;

use App\Enums\OptionSide;
use App\Enums\OptionType;
use App\Models\OptionPosition;
use App\Models\StockLot;
use App\Models\StockSaleAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The shares of one ticker in one account that are free to cover another
 * sold call at the end of a date:
 *
 *   shares held − open sold-call contracts × shares per contract.
 *
 * Shares held come from the stock lots and their oldest-first sales, as on
 * the Positions page. A sold call reserves its shares from its trade date
 * until its contracts are bought back, expire, are assigned or rolled, that
 * is until a closing event is recorded on or before the date. A call past
 * its expiration date with nothing recorded still reserves its shares:
 * whether it expired or was assigned is not guessed.
 */
final readonly class CoveredCallCapacity
{
    /**
     * @param  Collection<int, OptionPosition>  $openCalls  sold calls still open at the end of the date
     */
    private function __construct(
        public int $accountId,
        public string $ticker,
        public CarbonImmutable $date,
        public Quantity $held,
        public Collection $openCalls,
    ) {}

    public static function for(int $accountId, string $ticker, CarbonImmutable $date): self
    {
        $day = $date->format('Y-m-d');

        $boughtUnits = (int) StockLot::query()
            ->notVoided()
            ->where('account_id', $accountId)
            ->where('ticker', $ticker)
            ->where('acquired_on', '<=', $day)
            ->sum('shares');

        $soldUnits = (int) StockSaleAllocation::query()
            ->where('account_id', $accountId)
            ->where('ticker', $ticker)
            ->where('sold_on', '<=', $day)
            ->sum('shares');

        $openCalls = self::soldCalls($accountId, $ticker)
            ->where('opened_on', '<=', $day)
            ->withSum(['events as closed_contracts' => fn (Builder $events) => $events->whereNull('voided_at')->where('event_date', '<=', $day)], 'contracts')
            ->orderBy('expires_on')
            ->orderBy('id')
            ->get()
            ->filter(fn (OptionPosition $position) => $position->openContracts() > 0)
            ->values();

        return new self($accountId, $ticker, $date, Quantity::fromUnits($boughtUnits - $soldUnits), $openCalls);
    }

    /**
     * Every ticker in the account with enough free shares on the date for at
     * least one more contract of this size, by ticker.
     *
     * @return list<self>
     */
    public static function eligible(int $accountId, CarbonImmutable $date, int $multiplier): array
    {
        return array_values(StockLot::query()
            ->notVoided()
            ->where('account_id', $accountId)
            ->where('acquired_on', '<=', $date->format('Y-m-d'))
            ->distinct()
            ->orderBy('ticker')
            ->pluck('ticker')
            ->map(fn (string $ticker) => self::for($accountId, $ticker, $date))
            ->filter(fn (self $capacity) => $capacity->maxContracts($multiplier) > 0)
            ->all());
    }

    /**
     * Sold (short) calls on the ticker in the account, not voided.
     *
     * @return Builder<OptionPosition>
     */
    public static function soldCalls(int $accountId, string $ticker): Builder
    {
        return OptionPosition::query()
            ->notVoided()
            ->where('account_id', $accountId)
            ->where('underlying', $ticker)
            ->where('side', OptionSide::Short)
            ->where('option_type', OptionType::Call);
    }

    /**
     * Shares reserved by the open sold calls.
     */
    public function committed(): Quantity
    {
        return Quantity::fromInteger($this->openCalls->sum(fn (OptionPosition $position) => $position->openContracts() * $position->multiplier));
    }

    public function openContracts(): int
    {
        return $this->openCalls->sum(fn (OptionPosition $position) => $position->openContracts());
    }

    /**
     * Held minus committed. Negative when the open calls need more shares
     * than are held.
     */
    public function available(): Quantity
    {
        return Quantity::fromUnits($this->held->units - $this->committed()->units);
    }

    /**
     * The number of further contracts of this size the free shares cover.
     * Fractional shares never make up a contract.
     */
    public function maxContracts(int $multiplier): int
    {
        $available = $this->available()->units;

        return $available > 0 && $multiplier > 0 ? intdiv($available, Quantity::fromInteger($multiplier)->units) : 0;
    }

    /**
     * Open calls whose expiration date has passed with no expiry, assignment
     * or buy-back recorded. They still reserve shares until one is recorded.
     *
     * @return Collection<int, OptionPosition>
     */
    public function pastExpiration(): Collection
    {
        return $this->openCalls->filter(fn (OptionPosition $position) => $position->expires_on->lessThan($this->date))->values();
    }
}
