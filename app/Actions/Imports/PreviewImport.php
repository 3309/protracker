<?php

namespace App\Actions\Imports;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportFormatType;
use App\Enums\ImportRowStatus;
use App\Exceptions\ImportFileException;
use App\Models\Account;
use App\Models\ImportBatch;
use App\Support\Imports\CsvFile;
use App\Support\Imports\FidelityFormat;
use App\Support\Imports\ImportFormat;
use App\Support\Imports\RobinhoodFormat;
use App\Support\Imports\TemplateFormat;
use Illuminate\Support\Facades\DB;

/**
 * Reads an uploaded file into a batch of rows for preview. Nothing is
 * imported yet; the rows wait for the user to confirm.
 */
class PreviewImport
{
    public function __construct(private AssessImportRows $assessImportRows) {}

    /**
     * @throws ImportFileException
     */
    public function handle(Account $account, ImportFormatType $formatType, string $contents, string $filename, ?int $userId): ImportBatch
    {
        $format = $this->format($formatType);
        $file = CsvFile::read($contents);
        $missing = $format->missingColumns($file['header']);

        if ($missing !== []) {
            throw new ImportFileException("This file does not look like the {$formatType->label()} format. Missing columns: ".implode(', ', $missing).'.');
        }

        if ($file['rows'] === []) {
            throw new ImportFileException('The file has a header but no rows.');
        }

        return DB::transaction(function () use ($account, $formatType, $contents, $filename, $userId, $format, $file): ImportBatch {
            $batch = ImportBatch::create([
                'account_id' => $account->id,
                'user_id' => $userId,
                'format' => $formatType,
                'original_filename' => basename(str_replace('\\', '/', $filename)),
                'file_sha256' => hash('sha256', $contents),
                'status' => ImportBatchStatus::Previewed,
                'row_count' => count($file['rows']),
            ]);

            $normalizedRows = [];

            foreach ($file['rows'] as $line) {
                $normalizedRows[$line['number']] = $format->normalize($line['values']);
            }

            $normalizedRows = $format->combine($normalizedRows);

            foreach ($file['rows'] as $line) {
                $normalized = $normalizedRows[$line['number']];

                $batch->rows()->create([
                    'row_number' => $line['number'],
                    'raw' => $line['values'],
                    'action' => $normalized['action'],
                    'data' => $normalized['data'],
                    'status' => ImportRowStatus::Unrecognized,
                    'messages' => $line['columnCountMatches']
                        ? $normalized['messages']
                        : ['This line has a different number of columns than the header.', ...$normalized['messages']],
                ]);
            }

            $this->assessImportRows->batch($batch->rows()->with('batch')->orderBy('row_number')->get());

            return $batch;
        });
    }

    private function format(ImportFormatType $formatType): ImportFormat
    {
        return match ($formatType) {
            ImportFormatType::Template => new TemplateFormat,
            ImportFormatType::Fidelity => new FidelityFormat,
            ImportFormatType::Robinhood => new RobinhoodFormat,
        };
    }
}
