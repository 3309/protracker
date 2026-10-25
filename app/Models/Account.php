<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\AccountType;
use App\Enums\Broker;
use App\Enums\OpeningStatus;
use App\Enums\PositionOrigin;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A brokerage account. Accounts are archived, never deleted, so their
 * history is always kept.
 *
 * @property int $id
 * @property Broker $broker
 * @property string $nickname
 * @property AccountType $account_type
 * @property string|null $account_number_last4
 * @property Money $option_fee_per_contract
 * @property Money $stock_fee_per_trade
 * @property Money $assignment_fee
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AccountOpening|null $opening
 * @property-read int|null $opening_stock_lots_count
 * @property-read int|null $opening_option_positions_count
 */
#[Fillable([
    'broker',
    'nickname',
    'account_type',
    'account_number_last4',
    'option_fee_per_contract',
    'stock_fee_per_trade',
    'assignment_fee',
])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use Auditable, HasFactory;

    /**
     * Default fees in cents, matching the database defaults.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'option_fee_per_contract' => 67,
        'stock_fee_per_trade' => 0,
        'assignment_fee' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'broker' => Broker::class,
            'account_type' => AccountType::class,
            'option_fee_per_contract' => MoneyCast::class,
            'stock_fee_per_trade' => MoneyCast::class,
            'assignment_fee' => MoneyCast::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return HasOne<AccountOpening, $this>
     */
    public function opening(): HasOne
    {
        return $this->hasOne(AccountOpening::class);
    }

    /**
     * @return HasMany<StockLot, $this>
     */
    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    /**
     * @return HasMany<StockTrade, $this>
     */
    public function stockTrades(): HasMany
    {
        return $this->hasMany(StockTrade::class);
    }

    /**
     * @return HasMany<OptionPosition, $this>
     */
    public function optionPositions(): HasMany
    {
        return $this->hasMany(OptionPosition::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Load what is needed to show opening-balance progress without extra queries.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withOpeningProgress(Builder $query): void
    {
        $query->with('opening')->withCount([
            'stockLots as opening_stock_lots_count' => fn (Builder $lots) => $lots->where('origin', PositionOrigin::OpeningBalance)->whereNull('voided_at'),
            'optionPositions as opening_option_positions_count' => fn (Builder $options) => $options->where('origin', PositionOrigin::OpeningBalance)->whereNull('voided_at'),
        ]);
    }

    /**
     * How far this account's opening-balance setup has got.
     */
    public function openingStatus(): OpeningStatus
    {
        $lotCount = $this->opening_stock_lots_count ?? $this->stockLots()->openingBalance()->notVoided()->count();
        $optionCount = $this->opening_option_positions_count ?? $this->optionPositions()->openingBalance()->notVoided()->count();

        return OpeningStatus::determine($this->opening, $lotCount + $optionCount);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(string $reason): void
    {
        $this->withAuditReason($reason)->forceFill(['archived_at' => now()])->save();
    }

    public function restore(string $reason): void
    {
        $this->withAuditReason($reason)->forceFill(['archived_at' => null])->save();
    }

    /**
     * A display name such as "Fidelity · Main taxable".
     */
    public function displayName(): string
    {
        return $this->broker->label().' · '.$this->nickname;
    }
}
