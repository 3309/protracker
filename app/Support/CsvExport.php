<?php

namespace App\Support;

use App\Models\Setting;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams records as a CSV download, with values written the same way in
 * every export:
 * - dates as YYYY-MM-DD;
 * - money as plain numbers with two decimals and a minus sign for money
 *   paid out ("-654.93"), never "$" or thousands separators;
 * - per-share prices with all their decimals ("26.1972"), share
 *   quantities without trailing zeros ("100");
 * - text that a spreadsheet would run as a formula (starting with an equals,
 *   plus, minus or at sign, a tab or a carriage return) is prefixed with an
 *   apostrophe.
 */
final class CsvExport
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $name, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fputcsv($output, $header, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($output, array_map(self::cell(...), array_values($row)), ',', '"', '');
            }

            fclose($output);
        }, self::filename($name), ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * "stock-trades" → "protracker-stock-trades-2026-10-25.csv".
     */
    public static function filename(string $name): string
    {
        return 'protracker-'.$name.'-'.CarbonImmutable::now(Setting::current()->display_timezone)->format('Y-m-d').'.csv';
    }

    public static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof Money => $value->toDecimal(),
            $value instanceof Quantity => $value->toDecimal(),
            $value instanceof FixedDecimal => $value->toNumber()->value,
            $value instanceof CarbonInterface => $value->format('Y-m-d'),
            $value instanceof BackedEnum => (string) $value->value,
            is_bool($value) => $value ? 'yes' : 'no',
            is_int($value) => (string) $value,
            default => self::text(is_scalar($value) ? (string) $value : ''),
        };
    }

    private static function text(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
