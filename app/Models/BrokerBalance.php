<?php

namespace App\Models;

use App\Casts\CalendarDate;
use App\Casts\MoneyCast;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Voidable;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\BrokerBalanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cash balance reported by the broker at the end of a date, used to
 * reconcile against the calculated cash ledger. Reconciliation never changes
 * any transaction; a difference is only shown.
 *
 * @property int $id
 * @property int $account_id
 * @property CarbonImmutable $as_of_date
 * @property Money $total_cash
 * @property Money|null $settled_cash
 * @property string|null $reference_note
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
#[Fillable(['account_id', 'as_of_date', 'total_cash', 'settled_cash', 'reference_note'])]
class BrokerBalance extends Model
{
    /** @use HasFactory<BrokerBalanceFactory> */
    use Auditable, HasFactory, Voidable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'as_of_date' => CalendarDate::class,
            'total_cash' => MoneyCast::class,
            'settled_cash' => MoneyCast::class,
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
}
