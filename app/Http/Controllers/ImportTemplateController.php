<?php

namespace App\Http\Controllers;

use App\Support\Imports\TemplateFormat;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportTemplateController extends Controller
{
    /**
     * Download the Protracker template CSV with one example row per action.
     */
    public function __invoke(): StreamedResponse
    {
        $rows = [
            [...TemplateFormat::REQUIRED_COLUMNS, ...TemplateFormat::OPTIONAL_COLUMNS],
            ['2026-01-05', 'DEPOSIT', '', '', '', '', '5000.00', '', '', '', '', '', '', '', 'Transfer in'],
            ['2026-01-12', 'SELL_TO_OPEN', 'XYZ', '1', '1.20', '0.67', '119.33', 'put', '50', '2026-02-20', '100', '', '', '', ''],
            ['2026-02-20', 'ASSIGNED', 'XYZ', '1', '', '0.00', '', 'put', '50', '2026-02-20', '100', '', '', '', 'Stock bought at the strike'],
            ['2026-02-23', 'SELL_TO_OPEN', 'XYZ', '1', '0.80', '0.67', '79.33', 'call', '52', '2026-03-20', '100', '', '', '', ''],
            ['2026-03-17', 'DIVIDEND', 'XYZ', '', '0.50', '', '50.00', '', '', '', '', '', '2026-03-10', '', ''],
            ['2026-03-20', 'BUY_TO_CLOSE', 'XYZ', '1', '0.40', '0.67', '-40.67', 'call', '52', '2026-03-20', '100', '', '', '', ''],
            ['2026-03-23', 'SELL', 'XYZ', '100', '52.00', '0.00', '5200.00', '', '', '', '', '', '', '', ''],
            ['2026-03-31', 'INTEREST', '', '', '', '', '1.23', '', '', '', '', '', '', '', ''],
        ];

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            foreach ($rows as $row) {
                fputcsv($output, $row, ',', '"', '');
            }

            fclose($output);
        }, 'protracker-import-template.csv', ['Content-Type' => 'text/csv']);
    }
}
