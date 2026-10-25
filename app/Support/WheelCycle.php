<?php

namespace App\Support;

use App\Enums\WheelCycleKind;
use BcMath\Number;
use Carbon\CarbonImmutable;
use RoundingMode;

/**
 * One wheel cycle for a ticker in one account, with its realized results.
 *
 * Returns use a simplified capital-at-risk figure (the largest amount tied
 * up in puts and shares during the cycle). The annualized figure is an
 * estimate from the raw return and the cycle length; it is not a forecast
 * and not an account-level return. An open cycle shows return to date.
 */
final readonly class WheelCycle
{
    public function __construct(
        public int $accountId,
        public string $ticker,
        public WheelCycleKind $kind,
        public CarbonImmutable $start,
        public ?CarbonImmutable $end,
        public Money $optionProfit,
        public Money $stockProfit,
        public Money $dividends,
        public Money $capitalAtRisk,
        public int $optionLegs,
    ) {}

    public function isOpen(): bool
    {
        return $this->end === null;
    }

    public function netProfit(): Money
    {
        return $this->optionProfit->plus($this->stockProfit)->plus($this->dividends);
    }

    /**
     * Calendar days from the start to the end (or to today while open), at least one.
     */
    public function days(CarbonImmutable $today): int
    {
        return max(1, (int) $this->start->diffInDays($this->end ?? $today));
    }

    /**
     * Net profit ÷ capital at risk, as a percentage. Null when there was no
     * capital at risk.
     */
    public function rawReturnPercent(): ?Number
    {
        if ($this->capitalAtRisk->cents <= 0) {
            return null;
        }

        return (new Number($this->netProfit()->cents))->mul(100)->div($this->capitalAtRisk->cents, 10);
    }

    /**
     * The raw return scaled to a 365-day year: an estimate only.
     */
    public function annualizedReturnPercent(CarbonImmutable $today): ?Number
    {
        return $this->rawReturnPercent()?->mul(365)->div($this->days($today), 10);
    }

    public static function formatPercent(?Number $percent, int $decimals): string
    {
        return $percent === null ? '—' : $percent->round($decimals, RoundingMode::HalfAwayFromZero)->value.'%';
    }
}
