<?php

namespace App\Support;

use App\Enums\CashCategory;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One line of the cash ledger, worked out from the record it comes from.
 *
 * Gross is signed (positive brings cash in, negative takes it out); fees are
 * the amount charged on top. The cash effect is gross minus fees.
 */
final readonly class CashLedgerEntry
{
    public function __construct(
        public int $accountId,
        public CashCategory $category,
        public CarbonImmutable $date,
        public CarbonImmutable $settlementDate,
        public string $description,
        public Money $gross,
        public Money $fees,
        public string $sourceUrl,
        public string $sourceKey,
    ) {}

    /**
     * The change in cash: gross minus fees.
     */
    public function amount(): Money
    {
        return $this->gross->minus($this->fees);
    }

    public function isSettledBy(DateTimeInterface $date): bool
    {
        return $this->settlementDate->format('Y-m-d') <= $date->format('Y-m-d');
    }

    public function isOnOrBefore(DateTimeInterface $date): bool
    {
        return $this->date->format('Y-m-d') <= $date->format('Y-m-d');
    }
}
