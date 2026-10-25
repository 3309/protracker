<?php

namespace App\Models;

use App\Casts\CalendarDate;
use App\Casts\MoneyCast;
use App\Enums\CashMovementType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Voidable;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A deposit, withdrawal, interest payment or other broker credit or charge
 * that is not part of a trade or dividend.
 *
 * @property int $id
 * @property int $account_id
 * @property CashMovementType $movement_type
 * @property CarbonImmutable $movement_date
 * @property CarbonImmutable $settlement_date
 * @property Money $amount
 * @property string|null $reference_note
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
#[Fillable(['account_id', 'movement_type', 'movement_date', 'settlement_date', 'amount', 'reference_note'])]
class CashMovement extends Model
{
    /** @use HasFactory<CashMovementFactory> */
    use Auditable, HasFactory, Voidable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => CashMovementType::class,
            'movement_date' => CalendarDate::class,
            'settlement_date' => CalendarDate::class,
            'amount' => MoneyCast::class,
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

    /**
     * The amount with its direction: positive adds cash, negative removes it.
     */
    public function signedAmount(): Money
    {
        return $this->movement_type->isCredit() ? $this->amount : Money::zero()->minus($this->amount);
    }
}
