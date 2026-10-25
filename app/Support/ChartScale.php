<?php

namespace App\Support;

/**
 * The vertical scale of a chart: rounded gridline values that cover the
 * data and always include zero, and where a value sits on the screen.
 *
 * Values are whole numbers (cents or contracts), so amounts stay exact;
 * only the screen positions are fractions.
 */
final readonly class ChartScale
{
    /**
     * @param  list<int>  $ticks  gridline values, lowest first
     */
    private function __construct(public int $min, public int $max, public array $ticks, private float $top, private float $bottom) {}

    /**
     * @param  list<int>  $values
     * @param  float  $top  screen position of the highest gridline
     * @param  float  $bottom  screen position of the lowest gridline
     */
    public static function for(array $values, float $top, float $bottom, int $gridlines = 4): self
    {
        $low = min(0, ...$values);
        $high = max(0, ...$values);

        if ($low === $high) {
            $high = 1;
        }

        $step = self::niceStep(intdiv($high - $low + $gridlines - 1, $gridlines));
        $min = $low % $step === 0 ? $low : -(intdiv(-$low, $step) + 1) * $step;
        $max = $high % $step === 0 ? $high : (intdiv($high, $step) + 1) * $step;

        return new self($min, $max, range($min, $max, $step), $top, $bottom);
    }

    /**
     * The screen position of a value.
     */
    public function y(int $value): float
    {
        return $this->bottom - ($value - $this->min) / ($this->max - $this->min) * ($this->bottom - $this->top);
    }

    /**
     * A whole-dollar gridline label such as "$1,500" or "-$250"; cents are
     * shown only when the gridline is not a whole dollar.
     */
    public static function dollars(int $cents): string
    {
        if ($cents % 100 !== 0) {
            return Money::fromCents($cents)->format();
        }

        return ($cents < 0 ? '-' : '').'$'.number_format(intdiv(abs($cents), 100));
    }

    /**
     * The smallest of 1, 2 or 5 times a power of ten that is at least the
     * given step.
     */
    private static function niceStep(int $rough): int
    {
        $magnitude = 1;

        while ($magnitude * 10 <= $rough) {
            $magnitude *= 10;
        }

        foreach ([1, 2, 5, 10] as $multiple) {
            if ($multiple * $magnitude >= $rough) {
                return $multiple * $magnitude;
            }
        }

        return 10 * $magnitude;
    }
}
