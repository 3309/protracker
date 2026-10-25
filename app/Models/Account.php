<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\AccountType;
use App\Enums\Broker;
use App\Models\Concerns\Auditable;
use App\Support\Money;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
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
