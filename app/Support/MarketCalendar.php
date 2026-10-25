<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The U.S. stock market (NYSE) trading-day calendar, used to work out
 * settlement dates.
 *
 * Regular holidays are calculated from their published rules. Unplanned
 * closures (for example a national day of mourning) cannot be predicted,
 * so every calculated settlement date can be corrected by hand.
 */
final class MarketCalendar
{
    /**
     * U.S. stock trades settle one business day after the trade date (T+1).
     */
    public const int STOCK_SETTLEMENT_DAYS = 1;

    public static function stockSettlementDate(DateTimeInterface $tradeDate): CarbonImmutable
    {
        return self::addTradingDays($tradeDate, self::STOCK_SETTLEMENT_DAYS);
    }

    /**
     * The date that is the given number of trading days after a date.
     */
    public static function addTradingDays(DateTimeInterface $date, int $tradingDays): CarbonImmutable
    {
        $day = CarbonImmutable::parse($date->format('Y-m-d'));

        while ($tradingDays > 0) {
            $day = $day->addDay();

            if (self::isTradingDay($day)) {
                $tradingDays--;
            }
        }

        return $day;
    }

    public static function isTradingDay(DateTimeInterface $date): bool
    {
        $day = CarbonImmutable::parse($date->format('Y-m-d'));

        return ! $day->isWeekend() && ! in_array($day->format('Y-m-d'), self::holidays($day->year), true);
    }

    /**
     * The full-day market holidays of a year, as "YYYY-MM-DD" dates.
     *
     * @return list<string>
     */
    public static function holidays(int $year): array
    {
        $holidays = [
            self::observedNewYearsDay($year),
            self::nthWeekday($year, 1, CarbonImmutable::MONDAY, 3),
            self::nthWeekday($year, 2, CarbonImmutable::MONDAY, 3),
            self::easterSunday($year)->subDays(2),
            self::lastWeekday($year, 5, CarbonImmutable::MONDAY),
            $year >= 2022 ? self::observed(CarbonImmutable::create($year, 6, 19)) : null,
            self::observed(CarbonImmutable::create($year, 7, 4)),
            self::nthWeekday($year, 9, CarbonImmutable::MONDAY, 1),
            self::nthWeekday($year, 11, CarbonImmutable::THURSDAY, 4),
            self::observed(CarbonImmutable::create($year, 12, 25)),
        ];

        return array_values(array_map(
            fn (CarbonImmutable $holiday): string => $holiday->format('Y-m-d'),
            array_filter($holidays),
        ));
    }

    /**
     * New Year's Day moves to Monday when it falls on a Sunday. When it falls
     * on a Saturday the market does not close on the Friday before.
     */
    private static function observedNewYearsDay(int $year): ?CarbonImmutable
    {
        $day = CarbonImmutable::create($year, 1, 1);

        if ($day->isSaturday()) {
            return null;
        }

        return $day->isSunday() ? $day->addDay() : $day;
    }

    /**
     * A holiday on a Saturday is observed on Friday, and on a Sunday on Monday.
     */
    private static function observed(CarbonImmutable $day): CarbonImmutable
    {
        return match (true) {
            $day->isSaturday() => $day->subDay(),
            $day->isSunday() => $day->addDay(),
            default => $day,
        };
    }

    private static function nthWeekday(int $year, int $month, int $weekday, int $occurrence): CarbonImmutable
    {
        $day = CarbonImmutable::create($year, $month, 1);

        while ($day->dayOfWeek !== $weekday) {
            $day = $day->addDay();
        }

        return $day->addWeeks($occurrence - 1);
    }

    private static function lastWeekday(int $year, int $month, int $weekday): CarbonImmutable
    {
        $day = CarbonImmutable::create($year, $month, 1)->endOfMonth()->startOfDay();

        while ($day->dayOfWeek !== $weekday) {
            $day = $day->subDay();
        }

        return $day;
    }

    /**
     * Easter Sunday in the Gregorian calendar (anonymous Gregorian algorithm).
     */
    private static function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}
