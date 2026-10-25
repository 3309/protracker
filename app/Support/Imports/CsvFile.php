<?php

namespace App\Support\Imports;

use App\Exceptions\ImportFileException;

/**
 * Reads a CSV file into its header and rows. Blank lines and repeats of the
 * header (from exports pasted together) are skipped. Each
 * row is returned with its line number and its values keyed by header.
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
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];

        $header = null;
        $rows = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = array_map(fn (?string $value) => trim((string) $value), str_getcsv($line, ',', '"', ''));

            if ($header === null) {
                $header = array_map(fn (string $column) => trim($column), $values);

                continue;
            }

            if ($values === $header) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw new ImportFileException('The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.');
            }

            $keyed = [];

            foreach ($header as $position => $column) {
                $keyed[$column] = $values[$position] ?? '';
            }

            $rows[] = ['number' => $index + 1, 'values' => $keyed, 'columnCountMatches' => count($values) === count($header)];
        }

        if ($header === null) {
            throw new ImportFileException('The file is empty.');
        }

        return ['header' => $header, 'rows' => $rows];
    }
}
