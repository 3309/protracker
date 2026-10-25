<?php

namespace App\Models;

use App\Casts\CalendarDate;
use App\Casts\MoneyCast;
use App\Enums\OpeningBalanceSource;
use App\Enums\PositionOrigin;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\Voidable;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitPrice;
use Carbon\CarbonImmutable;
use Database\Factories\StockLotFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A group of shares of one ticker in one account, bought on one date at one
 * cost per share. Lots are sold oldest first (FIFO) within their account.
 *
 * Under Method B the cost per share is the actual purchase price; option
 * premium is never added to it. Purchase fees are kept separately.
 *
 * @property int $id
 * @property int $account_id
 * @property int|null $stock_trade_id
 * @property string $ticker
 * @property PositionOrigin $origin
 * @property CarbonImmutable $acquired_on
 * @property Quantity $shares
 * @property UnitPrice $cost_per_share
 * @property Money $fees
 * @property OpeningBalanceSource|null $source
 * @property string|null $reference_note
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
#[Fillable([
    'account_id',
    'stock_trade_id',
    'ticker',
    'origin',
    'acquired_on',
    'shares',
    'cost_per_share',
    'fees',
    'source',
    'reference_note',
])]
class StockLot extends Model
{
    /** @use HasFactory<StockLotFactory> */
    use Auditable, HasFactory, Voidable;

    /**
     * Defaults matching the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
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
            'origin' => PositionOrigin::class,
            'acquired_on' => CalendarDate::class,
            'shares' => Quantity::class,
            'cost_per_share' => UnitPrice::class,
            'fees' => MoneyCast::class,
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
     * The buy that created this lot. Opening-balance lots have none.
     *
     * @return BelongsTo<StockTrade, $this>
     */
    public function trade(): BelongsTo
    {
        return $this->belongsTo(StockTrade::class, 'stock_trade_id');
    }

    /**
     * The sale pieces that used shares from this lot.
     *
     * @return HasMany<StockSaleAllocation, $this>
     */
    public function saleAllocations(): HasMany
    {
        return $this->hasMany(StockSaleAllocation::class);
    }

    /**
     * Load the number of shares sold from each lot, optionally only counting
     * sales on or before a date, as the "sold_units" attribute.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withSoldShares(Builder $query, ?DateTimeInterface $asOf = null): void
    {
        $query->withSum([
            'saleAllocations as sold_units' => fn (Builder $allocations) => $asOf === null
                ? $allocations
                : $allocations->where('sold_on', '<=', $asOf->format('Y-m-d')),
        ], 'shares');
    }

    /**
     * Shares × cost per share, rounded to the cent. Fees are not included.
     */
    public function costBasis(): Money
    {
        return $this->cost_per_share->totalFor($this->shares);
    }

    /**
     * Shares sold from this lot so far, using the loaded "sold_units"
     * attribute when present.
     */
    public function soldShares(): Quantity
    {
        $soldUnits = array_key_exists('sold_units', $this->getAttributes())
            ? $this->getAttribute('sold_units')
            : $this->saleAllocations()->sum('shares');

        return Quantity::fromUnits((int) $soldUnits);
    }

    public function remainingShares(): Quantity
    {
        return Quantity::fromUnits($this->shares->units - $this->soldShares()->units);
    }

    /**
     * The cost of the shares not yet sold. Worked out as the whole lot's cost
     * minus the cost of the shares sold, so the pieces always add up to the
     * lot's cost basis exactly.
     */
    public function remainingCostBasis(): Money
    {
        return $this->costBasis()->minus($this->cost_per_share->totalFor($this->soldShares()));
    }
}
