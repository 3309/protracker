<?php

namespace App\Support\Alpaca;

use App\Support\Money;
use App\Support\UnitPrice;
use BcMath\Number;
use Carbon\CarbonImmutable;
use RoundingMode;
use Throwable;

/**
 * Turns the service's values into what the Alpaca pages show. For display
 * only: Alpaca values can have more decimal places than the records allow, so
 * they are rounded here and never saved or used in a calculation. Anything
 * missing or unreadable comes back as null, so a page shows a dash, not zero.
 */
final class AlpacaFormat
{
    public static function money(mixed $value): ?Money
    {
        $number = self::number($value);

        return $number === null ? null : Money::fromDecimal($number->round(2, RoundingMode::HalfAwayFromZero)->value);
    }

    public static function price(mixed $value): ?UnitPrice
    {
        $number = self::number($value);

        if ($number === null || $number < 0) {
            return null;
        }

        return UnitPrice::fromDecimal($number->round(4, RoundingMode::HalfAwayFromZero)->value);
    }

    /**
     * A fraction as a percentage: "0.0331" becomes "3.31%".
     */
    public static function percent(mixed $value): ?string
    {
        $number = self::number($value);

        return $number === null ? null : $number->mul(100)->round(2, RoundingMode::HalfAwayFromZero)->value.'%';
    }

    /**
     * A plain number with no trailing zeros, such as a share count or a delta.
     */
    public static function decimal(mixed $value, int $places = 6): ?string
    {
        $number = self::number($value);

        if ($number === null) {
            return null;
        }

        $text = $number->round($places, RoundingMode::HalfAwayFromZero)->value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * Alpaca times can carry nanoseconds; PHP reads at most microseconds.
     */
    public static function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) preg_replace('/(\.\d{6})\d+/', '$1', $value));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The parts of a standard option symbol such as SPY261120C00750000, or
     * null when the symbol is in some other form.
     *
     * @return array{underlying: string, type: string, expiration: CarbonImmutable, strike: UnitPrice}|null
     */
    public static function optionSymbol(string $symbol): ?array
    {
        if (preg_match('/^([A-Z][A-Z0-9.]{0,5})(\d{6})([CP])(\d{8})$/', $symbol, $parts) !== 1) {
            return null;
        }

        $expiration = CarbonImmutable::createFromFormat('!ymd', $parts[2]);

        // A date such as 261345 would otherwise roll over into another real date.
        if (! $expiration instanceof CarbonImmutable || $expiration->format('ymd') !== $parts[2] || (int) $parts[4] === 0) {
            return null;
        }

        return [
            'underlying' => $parts[1],
            'type' => $parts[3] === 'C' ? 'Call' : 'Put',
            'expiration' => $expiration,
            'strike' => UnitPrice::fromDecimal((new Number($parts[4]))->div(1000, 3)->value),
        ];
    }

    /**
     * A readable name for a symbol: "AAPL $300 Put · Nov 20, 2026" for a
     * standard option symbol, and the symbol itself for anything else, since
     * an incomplete symbol cannot be read reliably.
     */
    public static function symbolLabel(string $symbol): string
    {
        $contract = self::optionSymbol($symbol);

        if ($contract === null) {
            return $symbol;
        }

        $strike = (string) preg_replace('/\.00$/', '', $contract['strike']->format());

        return "{$contract['underlying']} {$strike} {$contract['type']} · {$contract['expiration']->format('M j, Y')}";
    }

    /**
     * The side and size of an option order or position from its own data, such
     * as "Sell 1 contract" or "Short 2 contracts" (short positions arrive as a
     * negative quantity). Null when neither is known.
     */
    public static function contractsLabel(mixed $side, mixed $quantity): ?string
    {
        $sideText = is_string($side) && $side !== '' ? ucfirst($side) : null;
        $count = self::decimal($quantity);

        if ($count === null) {
            return $sideText === null ? null : "{$sideText}, number of contracts not given";
        }

        $count = ltrim($count, '-');
        $contracts = $count.($count === '1' ? ' contract' : ' contracts');

        return $sideText === null ? $contracts : "{$sideText} {$contracts}";
    }

    /**
     * Every figure the service gives for an option contract, formatted for
     * display. A missing figure is null, never zero.
     *
     * @param  array<mixed>  $contract
     * @return array{Bid: string|null, Ask: string|null, Last: string|null, IV: string|null, Delta: string|null, Gamma: string|null, Theta: string|null, Vega: string|null, Rho: string|null}
     */
    public static function contractFigures(array $contract): array
    {
        $greeks = is_array($contract['greeks'] ?? null) ? $contract['greeks'] : [];

        return [
            'Bid' => self::price($contract['bid'] ?? null)?->format(),
            'Ask' => self::price($contract['ask'] ?? null)?->format(),
            'Last' => self::price($contract['last'] ?? null)?->format(),
            'IV' => self::percent($contract['impliedVolatility'] ?? null),
            'Delta' => self::decimal($greeks['delta'] ?? null, 3),
            'Gamma' => self::decimal($greeks['gamma'] ?? null, 3),
            'Theta' => self::decimal($greeks['theta'] ?? null, 3),
            'Vega' => self::decimal($greeks['vega'] ?? null, 3),
            'Rho' => self::decimal($greeks['rho'] ?? null, 3),
        ];
    }

    /**
     * JSON numbers arrive as floats; they are turned into exact decimal text
     * here, once. Plain decimals only: no exponents or infinity.
     */
    private static function number(mixed $value): ?Number
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }

        $text = is_float($value) ? sprintf('%.6F', $value) : (string) $value;

        return is_numeric($text) && preg_match('/^-?\d+(\.\d+)?$/', $text) === 1 ? new Number($text) : null;
    }
}
