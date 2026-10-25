<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Lets a financial record be voided instead of deleted.
 *
 * A voided record stays in the database and its history, but is left out of
 * every total. The model must also use Auditable and have a nullable
 * voided_at timestamp column.
 *
 * @mixin Model
 */
trait Voidable
{
    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Mark the record as voided, keeping it and recording the reason.
     */
    public function void(string $reason): void
    {
        $this->withAuditReason($reason)->forceFill(['voided_at' => now()])->save();
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notVoided(Builder $query): void
    {
        $query->whereNull('voided_at');
    }
}
