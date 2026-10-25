<?php

namespace App\Models;

use App\Casts\CalendarDate;
use App\Casts\MoneyCast;
use App\Enums\OpeningBalanceSource;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\AccountOpeningFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An account's starting cash on the reporting start date, and whether its
 * opening-balance setup is complete.
 *
 * Opening cash is a starting point only. It is never counted as a deposit
 * or as income in the reporting period.
 *
 * @property int $id
 * @property int $account_id
 * @property CarbonImmutable $as_of_date
 * @property Money|null $cash_balance
 * @property Money|null $unsettled_cash
 * @property OpeningBalanceSource|null $cash_source
 * @property string|null $cash_note
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
#[Fillable(['account_id', 'as_of_date', 'cash_balance', 'unsettled_cash', 'cash_source', 'cash_note'])]
class AccountOpening extends Model
{
    /** @use HasFactory<AccountOpeningFactory> */
    use Auditable, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'as_of_date' => CalendarDate::class,
            'cash_balance' => MoneyCast::class,
            'unsettled_cash' => MoneyCast::class,
            'cash_source' => OpeningBalanceSource::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function hasCash(): bool
    {
        return $this->cash_balance !== null;
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    public function markComplete(): void
    {
        $this->forceFill(['completed_at' => now()])->save();
    }

    public function markInProgress(?string $reason): void
    {
        $this->withAuditReason($reason)->forceFill(['completed_at' => null])->save();
    }
}
