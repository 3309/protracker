<?php

namespace App\Support;

use BcMath\Number;
use InvalidArgumentException;
use RoundingMode;
use Stringable;

/**
 * An exact amount of US dollars, stored as a whole number of cents.
 *
 * Floating-point numbers are never accepted. Calculations use PHP's BCMath
 * decimal arithmetic, and any result with fractions of a cent is rounded to
 * the nearest cent, with exact halves rounded away from zero
 * ($0.005 becomes $0.01 and -$0.005 becomes -$0.01).
 */
final readonly class Money implements Stringable
{
    private function __construct(public int $cents) {}

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Create an amount from text such as "1.20", "$1,234.56" or "-0.67".
     *
     * Amounts with more than two decimal places are rejected rather than
     * silently rounded.
     *
     * @throws InvalidArgumentException
     */
    public static function fromDecimal(string $amount): self
    {
        $normalized = str_replace([',', '$', ' '], '', trim($amount));

        if (! is_numeric($normalized) || preg_match('/^-?\d+(\.\d{1,2})?$/', $normalized) !== 1) {
            throw new InvalidArgumentException("[{$amount}] is not a valid dollar amount with at most two decimal places.");
        }

        return new self((int) (new Number($normalized))->mul(100)->value);
    }

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function minus(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    /**
     * Multiply by a whole number or an exact decimal string such as "0.2425".
     *
     * Floats are accepted by the signature only so they can be rejected
     * explicitly; otherwise PHP would silently convert 0.1 to 0.
     *
     * @throws InvalidArgumentException
     */
    public function times(int|string|float $factor): self
    {
        if (is_float($factor)) {
            throw new InvalidArgumentException('Pass a whole number or a decimal string such as "0.2425", not a floating-point number.');
        }

        if (is_string($factor) && ! is_numeric($factor)) {
            throw new InvalidArgumentException("[{$factor}] is not a number.");
        }

        $result = (new Number($this->cents))
            ->mul(new Number($factor))
            ->round(0, RoundingMode::HalfAwayFromZero);

        return new self((int) $result->value);
    }

    /**
     * The part of this amount that belongs to $part out of $whole, e.g. the
     * fee for 50 of 150 shares, rounded to the cent half away from zero.
     *
     * To split an amount into pieces that add up exactly, take each piece as
     * share(running total after it) minus share(running total before it).
     *
     * @throws InvalidArgumentException
     */
    public function share(int $part, int $whole): self
    {
        if ($whole <= 0) {
            throw new InvalidArgumentException('The whole must be more than zero.');
        }

        $result = (new Number($this->cents))
            ->mul($part)
            ->div($whole, 20)
            ->round(0, RoundingMode::HalfAwayFromZero);

        return new self((int) $result->value);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    /**
     * The amount as a plain decimal string, e.g. "1.20" or "-71.34".
     */
    public function toDecimal(): string
    {
        return (new Number($this->cents))->div(100, 2)->value;
    }

    /**
     * The amount for display, e.g. "$1,234.56" or "-$71.34".
     */
    public function format(): string
    {
        $dollars = intdiv(abs($this->cents), 100);
        $remainingCents = abs($this->cents) % 100;

        return sprintf('%s$%s.%02d', $this->isNegative() ? '-' : '', number_format($dollars), $remainingCents);
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
