<?php

namespace App\Models;

use App\Casts\CalendarDate;
use App\Casts\MoneyCast;
use App\Enums\OpeningBalanceSource;
use App\Enums\OptionSide;
use App\Enums\OptionType;
use App\Enums\PositionOrigin;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Voidable;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Database\Factories\OptionPositionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An option position in one account, with a stable ID that later lifecycle
 * events (closing, expiry, assignment, rolls) link to.
 *
 * Prices are quoted per share. The contract multiplier is stored on each
 * position (normally 100 shares per contract) rather than assumed.
 *
 * @property int $id
 * @property int $account_id
 * @property string $underlying
 * @property OptionType $option_type
 * @property OptionSide $side
 * @property UnitPrice $strike
 * @property CarbonImmutable $expires_on
 * @property CarbonImmutable $opened_on
 * @property int $contracts
 * @property int $multiplier
 * @property UnitPrice $premium_per_share
 * @property Money $opening_fees
 * @property PositionOrigin $origin
 * @property OpeningBalanceSource|null $source
 * @property string|null $reference_note
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
#[Fillable([
    'account_id',
    'underlying',
    'option_type',
    'side',
    'strike',
    'expires_on',
    'opened_on',
    'contracts',
    'multiplier',
    'premium_per_share',
    'opening_fees',
    'origin',
    'source',
    'reference_note',
])]
class OptionPosition extends Model
{
    /** @use HasFactory<OptionPositionFactory> */
    use Auditable, HasFactory, Voidable;

    /**
     * Defaults matching the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'side' => 'short',
        'multiplier' => 100,
        'opening_fees' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'option_type' => OptionType::class,
            'side' => OptionSide::class,
            'strike' => UnitPrice::class,
            'expires_on' => CalendarDate::class,
            'opened_on' => CalendarDate::class,
            'contracts' => 'integer',
            'multiplier' => 'integer',
            'premium_per_share' => UnitPrice::class,
            'opening_fees' => MoneyCast::class,
            'origin' => PositionOrigin::class,
            'source' => OpeningBalanceSource::class,
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
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function openingBalance(Builder $query): void
    {
        $query->where('origin', PositionOrigin::OpeningBalance);
    }

    /**
     * The number of shares the open contracts cover (contracts × multiplier).
     */
    public function sharesCovered(): Quantity
    {
        return Quantity::fromInteger($this->contracts * $this->multiplier);
    }

    /**
     * The gross premium received when the position was opened, before fees.
     */
    public function premiumReceived(): Money
    {
        return $this->premium_per_share->totalFor($this->sharesCovered());
    }

    /**
     * A short description such as "AAPL $150.00 Put, expires Jan 16, 2026".
     */
    public function description(): string
    {
        return "{$this->underlying} {$this->strike->format()} {$this->option_type->label()}, expires {$this->expires_on->format('M j, Y')}";
    }
}
