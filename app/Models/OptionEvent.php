<?php

namespace App\Models;

use App\Casts\CalendarDate;
use App\Casts\MoneyCast;
use App\Enums\OptionEventType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Voidable;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Database\Factories\OptionEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something that happened to some or all contracts of an option position,
 * such as buying them back to close.
 *
 * The event's realized profit is the part of the opening premium belonging
 * to the contracts it closed, minus what it cost to close them, minus the
 * matching part of the opening fees and the event's own fees. Premium is
 * counted once: only when contracts close, never when they are opened.
 *
 * @property int $id
 * @property int $option_position_id
 * @property int $account_id
 * @property OptionEventType $event_type
 * @property CarbonImmutable $event_date
 * @property CarbonImmutable|null $settlement_date
 * @property bool $settlement_date_overridden
 * @property int $contracts
 * @property UnitPrice|null $price_per_share
 * @property Money $fees
 * @property Money $allocated_premium
 * @property Money $allocated_opening_fees
 * @property string|null $reference_note
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OptionPosition $position
 * @property-read Account $account
 */
#[Fillable([
    'option_position_id',
    'account_id',
    'event_type',
    'event_date',
    'settlement_date',
    'settlement_date_overridden',
    'contracts',
    'price_per_share',
    'fees',
    'reference_note',
])]
class OptionEvent extends Model
{
    /** @use HasFactory<OptionEventFactory> */
    use Auditable, HasFactory, Voidable;

    /**
     * Defaults matching the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'settlement_date_overridden' => false,
        'fees' => 0,
        'allocated_premium' => 0,
        'allocated_opening_fees' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => OptionEventType::class,
            'event_date' => CalendarDate::class,
            'settlement_date' => CalendarDate::class,
            'settlement_date_overridden' => 'boolean',
            'contracts' => 'integer',
            'price_per_share' => UnitPrice::class,
            'fees' => MoneyCast::class,
            'allocated_premium' => MoneyCast::class,
            'allocated_opening_fees' => MoneyCast::class,
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OptionPosition, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(OptionPosition::class, 'option_position_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * What it cost to buy the contracts back (price × multiplier × contracts).
     */
    public function closingCost(): Money
    {
        if ($this->price_per_share === null) {
            return Money::zero();
        }

        return $this->price_per_share->totalFor(Quantity::fromInteger($this->contracts * $this->position->multiplier));
    }

    /**
     * Premium kept minus closing cost, before fees.
     */
    public function grossProfit(): Money
    {
        return $this->allocated_premium->minus($this->closingCost());
    }

    /**
     * The opening fees of the closed contracts plus this event's own fees.
     */
    public function totalFees(): Money
    {
        return $this->allocated_opening_fees->plus($this->fees);
    }

    public function netProfit(): Money
    {
        return $this->grossProfit()->minus($this->totalFees());
    }
}
