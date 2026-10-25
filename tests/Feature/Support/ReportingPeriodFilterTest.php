<?php

use App\Models\StockLot;
use App\Support\ReportingPeriod;

it('filters records by a calendar-date column, including both boundary days', function () {
    foreach (['2024-12-31', '2025-01-01', '2025-06-15', '2025-12-31'] as $date) {
        StockLot::factory()->create(['acquired_on' => $date]);
    }

    $dates = ReportingPeriod::forYear(2025)
        ->applyTo(StockLot::query(), 'acquired_on')
        ->orderBy('acquired_on')
        ->pluck('acquired_on')
        ->map->format('Y-m-d')
        ->all();

    expect($dates)->toBe(['2025-01-01', '2025-06-15', '2025-12-31']);
});

it('filters records by a custom date range', function () {
    foreach (['2025-03-31', '2025-04-01', '2025-04-30', '2025-05-01'] as $date) {
        StockLot::factory()->create(['acquired_on' => $date]);
    }

    expect(ReportingPeriod::between('2025-04-01', '2025-04-30')->applyTo(StockLot::query(), 'acquired_on')->count())->toBe(2);
});
