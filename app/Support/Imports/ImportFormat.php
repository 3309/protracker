<?php

namespace App\Support\Imports;

use App\Enums\ImportAction;

/**
 * Maps one CSV layout's columns and transaction types to the common fields
 * of an import row. A format must never guess: an unfamiliar transaction
 * type gives no action, so the row is flagged as not recognized.
 */
interface ImportFormat
{
    /**
     * Required columns missing from the file's header.
     *
     * @param  list<string>  $header
     * @return list<string>
     */
    public function missingColumns(array $header): array;

    /**
     * Turn the original values of a row into the common fields.
     *
     * @param  array<string, string>  $raw
     * @return array{action: ImportAction|null, data: array<string, string>, messages: list<string>}
     */
    public function normalize(array $raw): array;

    /**
     * Adjust rows that can only be understood together, once every row of
     * the file has been normalized.
     *
     * @param  array<int, array{action: ImportAction|null, data: array<string, string>, messages: list<string>}>  $rows  keyed by line number
     * @return array<int, array{action: ImportAction|null, data: array<string, string>, messages: list<string>}>
     */
    public function combine(array $rows): array;
}
