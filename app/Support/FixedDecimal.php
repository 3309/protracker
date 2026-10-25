<?php

namespace App\Support;

use App\Casts\FixedDecimalCast;
use BcMath\Number;
use Illuminate\Contracts\Database\Eloquent\Castable;
use InvalidArgumentException;
use Stringable;

/**
 * An exact decimal number with a fixed number of decimal places, stored as a
 * whole number of its smallest unit (for example millionths of a share).
 *
 * Floating-point numbers are never accepted. Text with more decimal places
 * than allowed is rejected rather than silently rounded.
 */
abstract readonly class FixedDecimal implements Castable, Stringable
{
    final protected function __construct(public int $units) {}

    /**
     * The number of decimal places this kind of number keeps.
     */
    abstract protected static function decimalPlaces(): int;

    /**
     * The value for display, e.g. "1,234.5" or "$50.00".
     */
    abstract public function format(): string;

    public static function fromUnits(int $units): static
    {
        return new static($units);
    }

    public static function fromInteger(int $value): static
    {
        return new static($value * static::unitsPerWhole());
    }

    /**
     * Create a value from text such as "100", "0.5", "$52.25" or "1,234.5".
     *
     * @throws InvalidArgumentException
     */
    public static function fromDecimal(string $value): static
    {
        $normalized = str_replace([',', '$', ' '], '', trim($value));
        $pattern = '/^-?\d+(\.\d{1,'.static::decimalPlaces().'})?$/';

        if (! is_numeric($normalized) || preg_match($pattern, $normalized) !== 1) {
            throw new InvalidArgumentException("[{$value}] is not a number with at most ".static::decimalPlaces().' decimal places.');
        }

        return new static((int) (new Number($normalized))->mul(static::unitsPerWhole())->value);
    }

    /**
     * @param  array<mixed>  $arguments
     * @return FixedDecimalCast<static>
     */
    public static function castUsing(array $arguments): FixedDecimalCast
    {
        return new FixedDecimalCast(static::class);
    }

    /**
     * The exact value as a BCMath number, for calculations.
     */
    public function toNumber(): Number
    {
        return (new Number($this->units))->div(static::unitsPerWhole(), static::decimalPlaces());
    }

    /**
     * The value as plain text without trailing zeros, e.g. "100" or "0.2425".
     */
    public function toDecimal(): string
    {
        return $this->formatWith(minimumDecimals: 0, prefix: '', groupThousands: false);
    }

    public function isZero(): bool
    {
        return $this->units === 0;
    }

    public function isNegative(): bool
    {
        return $this->units < 0;
    }

    public function equals(self $other): bool
    {
        return $other instanceof static && $this->units === $other->units;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    protected static function unitsPerWhole(): int
    {
        return 10 ** static::decimalPlaces();
    }

    protected function formatWith(int $minimumDecimals, string $prefix, bool $groupThousands = true): string
    {
        $absoluteUnits = abs($this->units);
        $whole = intdiv($absoluteUnits, static::unitsPerWhole());

        $fraction = str_pad((string) ($absoluteUnits % static::unitsPerWhole()), static::decimalPlaces(), '0', STR_PAD_LEFT);
        $fraction = str_pad(rtrim($fraction, '0'), $minimumDecimals, '0');

        return ($this->isNegative() ? '-' : '')
            .$prefix
            .($groupThousands ? number_format($whole) : (string) $whole)
            .($fraction !== '' ? '.'.$fraction : '');
    }
}
