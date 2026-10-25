<?php

namespace App\Casts;

use App\Support\FixedDecimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Stores an exact decimal value (such as a share quantity or a per-share
 * price) in an integer column of its smallest unit.
 *
 * Attach it by casting the attribute to the value class itself, e.g.
 * 'shares' => Quantity::class. Anything other than that value class (for
 * example a float) is rejected when it is assigned.
 *
 * @template TValue of FixedDecimal
 *
 * @implements CastsAttributes<TValue, mixed>
 */
class FixedDecimalCast implements CastsAttributes
{
    /**
     * @param  class-string<TValue>  $valueClass
     */
    public function __construct(private string $valueClass) {}

    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     * @return TValue|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?FixedDecimal
    {
        if ($value === null) {
            return null;
        }

        return $this->valueClass::fromUnits((int) $value);
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

        if (! $value instanceof $this->valueClass) {
            throw new InvalidArgumentException("The [{$key}] attribute must be set with a ".class_basename($this->valueClass).' value, not a '.get_debug_type($value).'.');
        }

        return $value->units;
    }
}
