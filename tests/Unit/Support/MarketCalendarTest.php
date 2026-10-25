<?php

use App\Support\MarketCalendar;
use Carbon\CarbonImmutable;

it('lists the 2026 NYSE holidays', function () {
    expect(MarketCalendar::holidays(2026))->toBe([
        '2026-01-01',
        '2026-01-19',
        '2026-02-16',
        '2026-04-03',
        '2026-05-25',
        '2026-06-19',
        '2026-07-03',
        '2026-09-07',
        '2026-11-26',
        '2026-12-25',
    ]);
});

it('works out Good Friday from Easter', function (int $year, string $goodFriday) {
    expect(MarketCalendar::holidays($year))->toContain($goodFriday);
})->with([
    [2025, '2025-04-18'],
    [2026, '2026-04-03'],
    [2027, '2027-03-26'],
]);

it('does not close on the Friday before a Saturday New Year', function () {
    expect(MarketCalendar::holidays(2022))->not->toContain('2021-12-31')
        ->and(MarketCalendar::isTradingDay(CarbonImmutable::parse('2021-12-31')))->toBeTrue();
});

it('moves a Sunday holiday to Monday', function () {
    expect(MarketCalendar::holidays(2027))->toContain('2027-07-05');
});

it('settles stock trades on the next trading day', function (string $tradeDate, string $expectedSettlement) {
    expect(MarketCalendar::stockSettlementDate(CarbonImmutable::parse($tradeDate))->format('Y-m-d'))->toBe($expectedSettlement);
})->with([
    'ordinary weekday' => ['2026-03-10', '2026-03-11'],
    'Friday settles Monday' => ['2026-03-13', '2026-03-16'],
    'skips Independence Day observed on Friday July 3' => ['2026-07-02', '2026-07-06'],
    'skips Good Friday' => ['2026-04-02', '2026-04-06'],
    'skips New Year\'s Day' => ['2025-12-31', '2026-01-02'],
    'a weekend date settles on the next trading day' => ['2026-03-14', '2026-03-16'],
]);
