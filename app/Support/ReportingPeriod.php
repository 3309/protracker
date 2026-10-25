<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Builder;
use InvalidArgumentException;

/**
 * A range of calendar dates used to filter reports, such as the calendar
 * year 2026 or a custom range. Both the first and last day are included.
 */
final readonly class ReportingPeriod
{
    private function __construct(public CarbonImmutable $start, public CarbonImmutable $end) {}

    public static function forYear(int $year): self
    {
        return new self(
            CarbonImmutable::create($year, 1, 1)->startOfDay(),
            CarbonImmutable::create($year, 12, 31)->startOfDay(),
        );
    }

    /**
     * The calendar year that contains the reporting start date.
     */
    public static function startingYear(DateTimeInterface $reportingStartDate): self
    {
        return self::forYear((int) $reportingStartDate->format('Y'));
    }

    /**
     * A custom range from a date or "YYYY-MM-DD" text to another.
     *
     * @throws InvalidArgumentException
     */
    public static function between(DateTimeInterface|string $start, DateTimeInterface|string $end): self
    {
        $startDate = self::calendarDate($start);
        $endDate = self::calendarDate($end);

        if ($endDate->lessThan($startDate)) {
            throw new InvalidArgumentException('The end date of a reporting period cannot be before its start date.');
        }

        return new self($startDate, $endDate);
    }

    public function contains(DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= $this->start->format('Y-m-d') && $day <= $this->end->format('Y-m-d');
    }

    public function isCalendarYear(): bool
    {
        return $this->start->format('m-d') === '01-01'
            && $this->end->format('m-d') === '12-31'
            && $this->start->year === $this->end->year;
    }

    /**
     * A label such as "2026" or "Jan 5, 2026 – Mar 1, 2026".
     */
    public function label(): string
    {
        if ($this->isCalendarYear()) {
            return (string) $this->start->year;
        }

        return $this->start->format('M j, Y').' – '.$this->end->format('M j, Y');
    }

    /**
     * Limit a query to rows whose calendar-date column falls in this period.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function applyTo(Builder $query, string $column): Builder
    {
        $query->whereBetween($column, [$this->start->format('Y-m-d'), $this->end->format('Y-m-d')]);

        return $query;
    }

    private static function calendarDate(DateTimeInterface|string $date): CarbonImmutable
    {
        if ($date instanceof DateTimeInterface) {
            return CarbonImmutable::parse($date->format('Y-m-d'))->startOfDay();
        }

        $parsed = CarbonImmutable::canBeCreatedFromFormat($date, 'Y-m-d') ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : null;

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("[{$date}] is not a valid date in YYYY-MM-DD format.");
        }

        return $parsed;
    }
}
