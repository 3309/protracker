<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stores a Money value in an integer column of whole cents.
 *
 * Anything other than a Money value (for example a float such as 0.67) is
 * rejected when it is assigned, so imprecise numbers never reach the database.
 *
 * @implements CastsAttributes<Money, mixed>
 */
class MoneyCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::fromCents((int) $value);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidArgumentException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("The [{$key}] attribute must be set with a Money value, not a ".get_debug_type($value).'.');
        }

        return $value->cents;
    }
}
