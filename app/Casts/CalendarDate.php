<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stores a calendar date (no time, no time zone) as "Y-m-d" text.
 *
 * Trade, settlement and reporting dates are plain calendar dates, so they
 * must never shift when shown in a different time zone.
 *
 * Values other than a date object or "Y-m-d" text are rejected when assigned.
 *
 * @implements CastsAttributes<CarbonImmutable, mixed>
 */
class CalendarDate implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $value, 0, 10)) ?: null;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidArgumentException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d')) {
            return $value;
        }

        throw new InvalidArgumentException("The [{$key}] attribute must be a date in YYYY-MM-DD format.");
    }
}
