<?php

namespace App\Models;

use App\Casts\CalendarDate;
use App\Models\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The application-wide settings. There is only ever one row.
 *
 * @property int $id
 * @property CarbonImmutable $reporting_start_date
 * @property int $stale_price_threshold_days
 * @property string $display_timezone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['reporting_start_date', 'stale_price_threshold_days', 'display_timezone'])]
class Setting extends Model
{
    use Auditable;

    /**
     * Defaults matching the database defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'reporting_start_date' => '2026-01-01',
        'stale_price_threshold_days' => 3,
        'display_timezone' => 'America/New_York',
    ];

    /**
     * Get the settings row, creating it with the defaults the first time.
     */
    public static function current(): self
    {
        return static::query()->oldest('id')->first() ?? static::create();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reporting_start_date' => CalendarDate::class,
            'stale_price_threshold_days' => 'integer',
        ];
    }
}
