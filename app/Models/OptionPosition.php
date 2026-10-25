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
 * @property CarbonImmutable|null $opening_settlement_date
 * @property bool $opening_settlement_date_overridden
 * @property int $contracts
 * @property int $multiplier
 * @property UnitPrice $premium_per_share
 * @property Money $opening_fees
 * @property PositionOrigin $origin
 * @property int|null $rolled_from_event_id
 * @property int|null $roll_chain_id
 * @property OpeningBalanceSource|null $source
 * @property string|null $reference_note
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read OptionEvent|null $rolledFromEvent
 */
#[Fillable([
    'account_id',
    'underlying',
    'option_type',
    'side',
    'strike',
    'expires_on',
    'opened_on',
    'opening_settlement_date',
    'opening_settlement_date_overridden',
    'contracts',
    'multiplier',
    'premium_per_share',
    'opening_fees',
    'origin',
    'rolled_from_event_id',
    'roll_chain_id',
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
        'opening_settlement_date_overridden' => false,
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
            'opening_settlement_date' => CalendarDate::class,
            'opening_settlement_date_overridden' => 'boolean',
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
     * @return HasMany<OptionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(OptionEvent::class);
    }

    /**
     * For a leg sold as part of a roll, the buy-back of the contracts it replaced.
     *
     * @return BelongsTo<OptionEvent, $this>
     */
    public function rolledFromEvent(): BelongsTo
    {
        return $this->belongsTo(OptionEvent::class, 'rolled_from_event_id');
    }

    public function isInRollChain(): bool
    {
        return $this->roll_chain_id !== null;
    }

    /**
     * The net cash credit of the roll that opened this leg: the new premium
     * minus the cost of buying back the old contracts, minus the closing fee
     * and this leg's opening fee. Null when this leg was not opened by a roll.
     */
    public function rollNetCredit(): ?Money
    {
        $rolledFrom = $this->rolledFromEvent;

        if ($rolledFrom === null) {
            return null;
        }

        return $this->premiumReceived()
            ->minus($rolledFrom->closingCost())
            ->minus($rolledFrom->fees)
            ->minus($this->opening_fees);
    }

    /**
     * The realized net profit of every event that has not been voided.
     */
    public function realizedNetProfit(): Money
    {
        return $this->events()->notVoided()->get()
            ->each(fn (OptionEvent $event) => $event->setRelation('position', $this))
            ->reduce(fn (Money $total, OptionEvent $event) => $total->plus($event->netProfit()), Money::zero());
    }

    /**
     * Still has open contracts after its expiration date, so an expiry or
     * assignment needs to be recorded.
     */
    public function isPastExpiration(DateTimeInterface $today): bool
    {
        return ! $this->isVoided()
            && $this->isOpen()
            && $this->expires_on->format('Y-m-d') < $today->format('Y-m-d');
    }

    /**
     * Load the number of contracts closed by events that have not been
     * voided, as the "closed_contracts" attribute.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withClosedContracts(Builder $query): void
    {
        $query->withSum(['events as closed_contracts' => fn (Builder $events) => $events->whereNull('voided_at')], 'contracts');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereRaw('contracts > (select coalesce(sum(contracts), 0) from option_events where option_events.option_position_id = option_positions.id and option_events.voided_at is null)');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function closed(Builder $query): void
    {
        $query->whereRaw('contracts <= (select coalesce(sum(contracts), 0) from option_events where option_events.option_position_id = option_positions.id and option_events.voided_at is null)');
    }

    /**
     * Contracts closed so far, using the loaded "closed_contracts" attribute
     * when present.
     */
    public function closedContracts(): int
    {
        return (int) (array_key_exists('closed_contracts', $this->getAttributes())
            ? $this->getAttribute('closed_contracts')
            : $this->events()->notVoided()->sum('contracts'));
    }

    public function openContracts(): int
    {
        return $this->contracts - $this->closedContracts();
    }

    public function isOpen(): bool
    {
        return $this->openContracts() > 0;
    }

    /**
     * The number of shares the contracts cover (contracts × multiplier).
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
