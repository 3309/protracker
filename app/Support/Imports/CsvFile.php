<?php

namespace App\Support\Imports;

use App\Exceptions\ImportFileException;

/**
 * Reads a CSV file into its header and rows. A quoted value may run over
 * several lines. Blank rows, rows with nothing under any header column
 * (such as a closing disclaimer) and repeats of the header (from exports
 * pasted together) are skipped. Each row is returned with the line number
 * it starts on and its values keyed by header.
 */
final class CsvFile
{
    public const int MAX_ROWS = 5000;

    /**
     * @return array{header: list<string>, rows: list<array{number: int, values: array<string, string>, columnCountMatches: bool}>}
     *
     * @throws ImportFileException
     */
    public static function read(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $header = null;
        $rows = [];

        foreach (self::records($contents) as $lineNumber => $record) {
            $values = array_map(fn (?string $value) => trim((string) $value), str_getcsv($record, ',', '"', ''));

            if ($header === null) {
                if (implode('', $values) === '') {
                    continue;
                }

                $header = $values;

                continue;
            }

            if ($values === $header) {
                continue;
            }

            $keyed = [];

            foreach ($header as $position => $column) {
                $keyed[$column] = $values[$position] ?? '';
            }

            if (implode('', $keyed) === '') {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw new ImportFileException('The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.');
            }

            $rows[] = ['number' => $lineNumber, 'values' => $keyed, 'columnCountMatches' => count($values) === count($header)];
        }

        if ($header === null) {
            throw new ImportFileException('The file is empty.');
        }

        return ['header' => $header, 'rows' => $rows];
    }

    /**
     * Split the text into CSV records, keeping line breaks inside quoted
     * values, keyed by the line number each record starts on.
     *
     * @return array<int, string>
     */
    private static function records(string $contents): array
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $records = [];
        $current = '';
        $startLine = 1;
        $line = 1;
        $inQuotes = false;

        foreach (explode("\n", $contents) as $physicalLine) {
            $current = $current === '' && ! $inQuotes ? $physicalLine : $current."\n".$physicalLine;
            $inQuotes = substr_count($current, '"') % 2 === 1;

            if (! $inQuotes) {
                if (trim($current) !== '') {
                    $records[$startLine] = $current;
                }

                $current = '';
                $startLine = $line + 1;
            }

            $line++;
        }

        if (trim($current) !== '') {
            $records[$startLine] = $current;
        }

        return $records;
    }
}
