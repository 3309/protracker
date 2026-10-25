<?php

namespace App\Models;

use App\Casts\CalendarDate;
use App\Casts\MoneyCast;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Voidable;
use App\Support\DividendRate;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Database\Factories\DividendFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A dividend on one ticker in one account.
 *
 * Eligible shares are those held at the start of the ex-date: shares bought
 * on or after the ex-date do not count, and shares sold before it do not
 * count (both by trade date). The expected amount is eligible shares × the
 * amount per share. The actual amount from the broker is entered to confirm
 * it; only confirmed amounts count as income, on the pay date. Dividends
 * never change stock cost or option profit.
 *
 * @property int $id
 * @property int $account_id
 * @property string $ticker
 * @property CarbonImmutable $ex_date
 * @property CarbonImmutable $pay_date
 * @property DividendRate $amount_per_share
 * @property Quantity|null $eligible_shares
 * @property Money|null $expected_amount
 * @property Money|null $actual_amount
 * @property Carbon|null $confirmed_at
 * @property string|null $reference_note
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
#[Fillable(['account_id', 'ticker', 'ex_date', 'pay_date', 'amount_per_share', 'reference_note'])]
class Dividend extends Model
{
    /** @use HasFactory<DividendFactory> */
    use Auditable, HasFactory, Voidable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ex_date' => CalendarDate::class,
            'pay_date' => CalendarDate::class,
            'amount_per_share' => DividendRate::class,
            'eligible_shares' => Quantity::class,
            'expected_amount' => MoneyCast::class,
            'actual_amount' => MoneyCast::class,
            'confirmed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isConfirmed(): bool
    {
        return $this->actual_amount !== null;
    }

    /**
     * Shares of the ticker held in the account at the start of the ex-date,
     * worked out now from the current lots and sales.
     */
    public function currentEligibleShares(): Quantity
    {
        $exDate = $this->ex_date->format('Y-m-d');

        $boughtUnits = StockLot::query()
            ->where('account_id', $this->account_id)
            ->where('ticker', $this->ticker)
            ->notVoided()
            ->where('acquired_on', '<', $exDate)
            ->sum('shares');

        $soldUnits = StockSaleAllocation::query()
            ->where('account_id', $this->account_id)
            ->where('ticker', $this->ticker)
            ->where('sold_on', '<', $exDate)
            ->sum('shares');

        return Quantity::fromUnits((int) $boughtUnits - (int) $soldUnits);
    }

    /**
     * Eligible shares × amount per share, worked out now.
     */
    public function currentExpectedAmount(): Money
    {
        return $this->amount_per_share->totalFor($this->currentEligibleShares());
    }

    /**
     * Confirm the actual amount from the broker, keeping the eligible shares
     * and expected amount as they stand now alongside it.
     */
    public function confirm(Money $actualAmount, ?string $reason = null): void
    {
        $eligibleShares = $this->currentEligibleShares();

        $this->withAuditReason($reason)->forceFill([
            'eligible_shares' => $eligibleShares,
            'expected_amount' => $this->amount_per_share->totalFor($eligibleShares),
            'actual_amount' => $actualAmount,
            'confirmed_at' => $this->confirmed_at ?? now(),
        ])->save();
    }

    /**
     * Actual minus expected, once confirmed.
     */
    public function difference(): ?Money
    {
        if ($this->actual_amount === null || $this->expected_amount === null) {
            return null;
        }

        return $this->actual_amount->minus($this->expected_amount);
    }

    public function hasDifference(): bool
    {
        return $this->difference()?->isZero() === false;
    }

    /**
     * Whether the shares held on the ex-date have changed since the dividend
     * was confirmed, for example because a trade was corrected.
     */
    public function eligibleSharesChangedSinceConfirmation(): bool
    {
        return $this->eligible_shares !== null && ! $this->eligible_shares->equals($this->currentEligibleShares());
    }
}
