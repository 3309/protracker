<?php

use App\Support\ReportingPeriod;
use Carbon\CarbonImmutable;

it('covers a whole calendar year', function () {
    $period = ReportingPeriod::forYear(2026);

    expect($period->start->format('Y-m-d'))->toBe('2026-01-01')
        ->and($period->end->format('Y-m-d'))->toBe('2026-12-31')
        ->and($period->isCalendarYear())->toBeTrue()
        ->and($period->label())->toBe('2026');
});

it('starts with the calendar year of the reporting start date', function (string $startDate, int $expectedYear) {
    expect(ReportingPeriod::startingYear(CarbonImmutable::parse($startDate))->label())->toBe((string) $expectedYear);
})->with([
    'January 1, 2026' => ['2026-01-01', 2026],
    'a later year' => ['2027-01-01', 2027],
]);

it('includes the first and last day and nothing outside them', function (string $date, bool $expected) {
    expect(ReportingPeriod::forYear(2026)->contains(CarbonImmutable::parse($date)))->toBe($expected);
})->with([
    'day before the start' => ['2025-12-31', false],
    'first day' => ['2026-01-01', true],
    'last day' => ['2026-12-31', true],
    'last moment of the last day' => ['2026-12-31 23:59:59', true],
    'day after the end' => ['2027-01-01', false],
]);

it('supports a custom date range', function () {
    $period = ReportingPeriod::between('2026-01-05', '2026-03-01');

    expect($period->isCalendarYear())->toBeFalse()
        ->and($period->label())->toBe('Jan 5, 2026 – Mar 1, 2026')
        ->and($period->contains(CarbonImmutable::parse('2026-03-01')))->toBeTrue()
        ->and($period->contains(CarbonImmutable::parse('2026-03-02')))->toBeFalse();
});

it('allows a single-day range', function () {
    expect(ReportingPeriod::between('2026-02-02', '2026-02-02')->contains(CarbonImmutable::parse('2026-02-02')))->toBeTrue();
});

it('rejects a range that ends before it starts', function () {
    ReportingPeriod::between('2026-03-01', '2026-02-28');
})->throws(InvalidArgumentException::class, 'cannot be before its start date');

it('rejects dates that are not real dates in YYYY-MM-DD format', function (string $date) {
    ReportingPeriod::between($date, '2026-12-31');
})->throws(InvalidArgumentException::class)->with([
    'impossible date' => ['2026-02-30'],
    'US format' => ['01/05/2026'],
    'text' => ['soon'],
]);
