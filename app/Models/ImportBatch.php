<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportFormatType;
use App\Enums\ImportRowStatus;
use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One uploaded CSV file. Only its name and a SHA-256 fingerprint of its
 * contents are kept, not the file itself; its rows keep the original values.
 *
 * @property int $id
 * @property int $account_id
 * @property int|null $user_id
 * @property ImportFormatType $format
 * @property string $original_filename
 * @property string $file_sha256
 * @property ImportBatchStatus $status
 * @property int $row_count
 * @property Carbon|null $committed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read User|null $user
 */
#[Fillable(['account_id', 'user_id', 'format', 'original_filename', 'file_sha256', 'status', 'row_count', 'committed_at'])]
class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => ImportFormatType::class,
            'status' => ImportBatchStatus::class,
            'row_count' => 'integer',
            'committed_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function isCommitted(): bool
    {
        return $this->status === ImportBatchStatus::Committed;
    }

    /**
     * The number of rows with each status.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = $this->rows()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all();

        $result = [];

        foreach (ImportRowStatus::cases() as $status) {
            if (isset($counts[$status->value])) {
                $result[$status->value] = (int) $counts[$status->value];
            }
        }

        return $result;
    }
}
