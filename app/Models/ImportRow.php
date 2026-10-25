<?php

namespace App\Models;

use App\Enums\ImportAction;
use App\Enums\ImportRowStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One row of an uploaded file.
 *
 * "raw" keeps the original values exactly as they were in the file, for the
 * audit trail. "data" holds the values understood from them, using the
 * common field names below, and can be corrected in the review queue.
 * Once imported, "imported" points to the record the row created.
 *
 * Common fields: date, action, symbol, quantity, price, fees, amount,
 * option_type, strike, expiration, multiplier, settlement_date, ex_date,
 * transaction_id, description.
 *
 * @property int $id
 * @property int $import_batch_id
 * @property int $row_number
 * @property array<string, string> $raw
 * @property ImportAction|null $action
 * @property array<string, string> $data
 * @property ImportRowStatus $status
 * @property list<string> $messages
 * @property string|null $fingerprint
 * @property string|null $broker_reference
 * @property int|null $paired_row_id
 * @property bool $import_anyway
 * @property string|null $skip_reason
 * @property string|null $imported_type
 * @property int|null $imported_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ImportBatch $batch
 * @property-read Model|null $imported
 */
#[Fillable([
    'import_batch_id',
    'row_number',
    'raw',
    'action',
    'data',
    'status',
    'messages',
    'fingerprint',
    'broker_reference',
    'paired_row_id',
    'import_anyway',
    'skip_reason',
])]
class ImportRow extends Model
{
    /**
     * The common field names every format maps its columns to.
     */
    public const array FIELDS = [
        'date', 'action', 'symbol', 'quantity', 'price', 'fees', 'amount', 'option_type', 'strike',
        'expiration', 'multiplier', 'settlement_date', 'ex_date', 'transaction_id', 'description',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'action' => ImportAction::class,
            'data' => 'array',
            'status' => ImportRowStatus::class,
            'messages' => 'array',
            'import_anyway' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    /**
     * @return BelongsTo<ImportRow, $this>
     */
    public function pairedRow(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class, 'paired_row_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function imported(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * A common field's value, or an empty string when it is not set.
     */
    public function value(string $field): string
    {
        return trim((string) ($this->data[$field] ?? ''));
    }
}
