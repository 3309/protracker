<?php

namespace App\Models;

use App\Casts\CalendarDate;
use App\Casts\MoneyCast;
use App\Enums\TradeSide;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Voidable;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Database\Factories\StockTradeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A stock buy or sell in one account.
 *
 * A buy creates a stock lot. A sell uses the account's oldest lots of the
 * same ticker first (FIFO); the pieces it used are its sale allocations.
 *
 * @property int $id
 * @property int $account_id
 * @property int|null $option_event_id
 * @property string $ticker
 * @property TradeSide $side
 * @property CarbonImmutable $trade_date
 * @property CarbonImmutable $settlement_date
 * @property bool $settlement_date_overridden
 * @property Quantity $shares
 * @property UnitPrice $price_per_share
 * @property Money $fees
 * @property string|null $reference_note
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read StockLot|null $lot
 * @property-read OptionEvent|null $optionEvent
 */
#[Fillable([
    'account_id',
    'option_event_id',
    'ticker',
    'side',
    'trade_date',
    'settlement_date',
    'settlement_date_overridden',
    'shares',
    'price_per_share',
    'fees',
    'reference_note',
])]
class StockTrade extends Model
{
    /** @use HasFactory<StockTradeFactory> */
    use Auditable, HasFactory, Voidable;

    /**
     * Defaults matching the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'settlement_date_overridden' => false,
        'fees' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'side' => TradeSide::class,
            'trade_date' => CalendarDate::class,
            'settlement_date' => CalendarDate::class,
            'settlement_date_overridden' => 'boolean',
            'shares' => Quantity::class,
            'price_per_share' => UnitPrice::class,
            'fees' => MoneyCast::class,
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
     * The option assignment that created this trade, if any. Such a trade is
     * changed only through its assignment.
     *
     * @return BelongsTo<OptionEvent, $this>
     */
    public function optionEvent(): BelongsTo
    {
        return $this->belongsTo(OptionEvent::class);
    }

    public function isFromAssignment(): bool
    {
        return $this->option_event_id !== null;
    }

    /**
     * The lot a buy created.
     *
     * @return HasOne<StockLot, $this>
     */
    public function lot(): HasOne
    {
        return $this->hasOne(StockLot::class);
    }

    /**
     * The lot pieces a sell used.
     *
     * @return HasMany<StockSaleAllocation, $this>
     */
    public function saleAllocations(): HasMany
    {
        return $this->hasMany(StockSaleAllocation::class);
    }

    public function isBuy(): bool
    {
        return $this->side === TradeSide::Buy;
    }

    /**
     * Shares × price, before fees.
     */
    public function grossAmount(): Money
    {
        return $this->price_per_share->totalFor($this->shares);
    }
}
