<?php

namespace App\Models;

use App\Enums\AuditEvent;
use App\Support\Money;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * A permanent record of one change to an audited model.
 *
 * Entries can only be added. Changing or deleting one throws an exception,
 * and on SQLite a database trigger blocks it as well.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $auditable_type
 * @property int $auditable_id
 * @property AuditEvent $event
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $reason
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'event', 'old_values', 'new_values', 'reason'])]
class AuditEntry extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Audit entries cannot be changed.'));
        static::deleting(fn (): never => throw new LogicException('Audit entries cannot be deleted.'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The recorded changes in readable form, e.g. "Option Fee Per Contract: $0.67 → $0.71".
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    public function readableChanges(): array
    {
        $modelClass = Relation::getMorphedModel($this->auditable_type);
        $model = $modelClass !== null ? new $modelClass : null;

        $changes = [];

        foreach (Arr::except($this->new_values ?? [], ['id']) as $field => $newValue) {
            $changes[] = [
                'field' => Str::headline($field),
                'old' => $this->readableValue($model, $field, $this->old_values[$field] ?? null),
                'new' => $this->readableValue($model, $field, $newValue),
            ];
        }

        return $changes;
    }

    private function readableValue(?Model $model, string $field, mixed $rawValue): string
    {
        if ($rawValue === null) {
            return '—';
        }

        $value = $model !== null ? $model->setRawAttributes([$field => $rawValue])->getAttribute($field) : $rawValue;

        return match (true) {
            $value instanceof Money => $value->format(),
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof BackedEnum && method_exists($value, 'label') => $value->label(),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
