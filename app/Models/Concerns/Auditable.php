<?php

namespace App\Models\Concerns;

use App\Enums\AuditEvent;
use App\Models\AuditEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

/**
 * Records an audit entry every time the model is created or changed.
 *
 * Each entry keeps the original and revised database values, who made the
 * change, when, and the reason given with withAuditReason().
 *
 * @mixin Model
 */
trait Auditable
{
    private ?string $pendingAuditReason = null;

    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            /** @var Model&self $model */
            $model->recordAuditEntry(AuditEvent::Created, null, $model->auditableValues($model->getAttributes()));
        });

        static::updated(function (Model $model): void {
            /** @var Model&self $model */
            $newValues = $model->auditableValues($model->getChanges());

            if ($newValues === []) {
                return;
            }

            $oldValues = Arr::only($model->getRawOriginal(), array_keys($newValues));

            $model->recordAuditEntry($model->auditEventForChanges($oldValues, $newValues), $oldValues, $newValues);
        });
    }

    /**
     * Attach a reason to the next audited change.
     */
    public function withAuditReason(?string $reason): static
    {
        $this->pendingAuditReason = $reason;

        return $this;
    }

    /**
     * @return MorphMany<AuditEntry, $this>
     */
    public function auditEntries(): MorphMany
    {
        return $this->morphMany(AuditEntry::class, 'auditable');
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function recordAuditEntry(AuditEvent $event, ?array $oldValues, array $newValues): void
    {
        $this->auditEntries()->create([
            'user_id' => Auth::id(),
            'event' => $event,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'reason' => $this->pendingAuditReason,
        ]);

        $this->pendingAuditReason = null;
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function auditEventForChanges(array $oldValues, array $newValues): AuditEvent
    {
        if (array_key_exists('archived_at', $newValues)) {
            return $newValues['archived_at'] === null ? AuditEvent::Restored : AuditEvent::Archived;
        }

        return AuditEvent::Updated;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function auditableValues(array $values): array
    {
        return Arr::except($values, [$this->getCreatedAtColumn(), $this->getUpdatedAtColumn()]);
    }
}
