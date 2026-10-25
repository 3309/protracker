<?php

namespace App\Actions\Imports;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Exceptions\ImportRowException;
use App\Exceptions\InsufficientSharesException;
use App\Exceptions\OptionPositionException;
use App\Models\ImportBatch;
use App\Models\ImportRow;

/**
 * Imports the rows of a previewed batch that are ready, row by row.
 *
 * Rows are imported in date order, opening positions and buying shares
 * before closing or selling them on the same day. A row that fails is kept
 * in the review queue with the reason; the other rows are still imported.
 * Rows already imported before, and possible duplicates of entries made by
 * hand (unless chosen), are skipped. Rows needing a correction or decision
 * stay in the review queue.
 */
class CommitImportBatch
{
    public function __construct(private CommitImportRow $commitImportRow) {}

    /**
     * @return array{imported: int, failed: int}
     */
    public function handle(ImportBatch $batch): array
    {
        $rows = $batch->rows()->with('batch.account')->get();

        foreach ($rows as $row) {
            if ($row->status === ImportRowStatus::Duplicate) {
                $this->skip($row, 'Already imported before.');
            } elseif ($row->status === ImportRowStatus::PossibleDuplicate && ! $row->import_anyway) {
                $this->skip($row, 'Possible duplicate of an entry made by hand; skipped by default.');
            }
        }

        $toImport = $rows
            ->filter(fn (ImportRow $row) => $row->status === ImportRowStatus::Recognized
                || (in_array($row->status, [ImportRowStatus::PossibleDuplicate, ImportRowStatus::Ambiguous], true) && $row->import_anyway))
            ->sortBy(fn (ImportRow $row) => sprintf('%s|%02d|%08d', $row->value('date'), $row->action?->importOrder() ?? 99, $row->row_number));

        $imported = 0;
        $failed = 0;

        foreach ($toImport as $row) {
            if ($this->importRow($row)) {
                $imported++;
            } else {
                $failed++;
            }
        }

        $batch->forceFill(['status' => ImportBatchStatus::Committed, 'committed_at' => now()])->save();

        return ['imported' => $imported, 'failed' => $failed];
    }

    /**
     * Import one row, keeping it in the review queue with the reason if it fails.
     */
    public function importRow(ImportRow $row): bool
    {
        try {
            $this->commitImportRow->handle($row);

            return true;
        } catch (ImportRowException|InsufficientSharesException|OptionPositionException $exception) {
            $row->refresh();
            $row->status = ImportRowStatus::Failed;
            $row->messages = [$exception->getMessage()];
            $row->save();

            return false;
        }
    }

    private function skip(ImportRow $row, string $reason): void
    {
        $row->status = ImportRowStatus::Skipped;
        $row->skip_reason = $reason;
        $row->save();
    }
}
