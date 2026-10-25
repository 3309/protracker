<?php

use App\Actions\Imports\CommitImportBatch;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\StockTrade;
use App\Models\User;
use App\Support\CsvExport;
use App\Support\Money;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-06-01 12:00:00');
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create(['nickname' => 'Main']);
    $this->name = $this->account->displayName();

    app(CommitImportBatch::class)->handle(previewTemplateCsv($this->account, [
        '2026-01-05,DEPOSIT,,,,,5000.00,,,,,,,,',
        '2026-01-12,BUY,XYZ,100,40.00,0.65,-4000.65,,,,,,,,',
        '2026-02-02,SELL_TO_OPEN,XYZ,1,1.20,0.67,119.33,call,45,2026-03-20,,,,,',
        '2026-03-02,BUY_TO_CLOSE,XYZ,1,0.40,0.67,-40.67,call,45,2026-03-20,,,,,',
        '2026-03-17,DIVIDEND,XYZ,,0.50,,50.00,,,,,,2026-03-10,,',
        '2026-03-23,SELL,XYZ,50,45.00,0.65,2249.35,,,,,,,,',
    ]));

    $this->csv = function (Testable $page, string $filename): array {
        $page->assertFileDownloaded($filename);
        $lines = preg_split('/\r\n|\n/', trim(base64_decode((string) data_get($page->effects, 'download.content')))) ?: [];

        return array_map(fn (string $line) => str_getcsv($line, ',', '"', ''), $lines);
    };
});

it('exports stock trades with plain dates, signed amounts and exact prices', function () {
    expect(($this->csv)(Livewire::test('pages::stock-trades.index')->call('export'), 'protracker-stock-trades-2026-06-01.csv'))->toBe([
        ['trade_date', 'settlement_date', 'account', 'side', 'ticker', 'shares', 'price_per_share', 'gross_amount', 'fees', 'cash_amount', 'from_assignment', 'voided', 'note'],
        ['2026-01-12', '2026-01-13', $this->name, 'buy', 'XYZ', '100', '40.0000', '4000.00', '0.65', '-4000.65', 'no', 'no', 'Imported from activity.csv, row 3.'],
        ['2026-03-23', '2026-03-24', $this->name, 'sell', 'XYZ', '50', '45.0000', '2250.00', '0.65', '2249.35', 'no', 'no', 'Imported from activity.csv, row 7.'],
    ]);
});

it('exports only what the filters show', function () {
    $other = Account::factory()->create(['nickname' => 'Other']);
    recordStockTrade($other, TradeSide::Buy, 'ABC', '2026-02-02', '10', '5');

    $rows = ($this->csv)(Livewire::withQueryParams(['account' => (string) $other->id])->test('pages::stock-trades.index')->call('export'), 'protracker-stock-trades-2026-06-01.csv');

    expect($rows)->toHaveCount(2)
        ->and($rows[1][4])->toBe('ABC');
});

it('exports the cash ledger with the balance brought forward and the running balance', function () {
    $rows = ($this->csv)(Livewire::test('pages::cash.index')->call('export'), 'protracker-cash-ledger-2026-06-01.csv');

    expect($rows[0])->toBe(['date', 'settlement_date', 'account', 'type', 'description', 'gross', 'fees', 'cash_amount', 'balance'])
        ->and($rows[1])->toBe(['2026-01-01', '', '', 'Balance brought forward', '', '', '', '', '0.00'])
        ->and(array_column(array_slice($rows, 2), 7))->toBe(['5000.00', '-4000.65', '119.33', '-40.67', '50.00', '2249.35'])
        ->and(end($rows)[8])->toBe('3377.36');
});

it('exports open lots, option positions and events, and dividends', function () {
    expect(($this->csv)(Livewire::test('pages::positions')->call('export'), 'protracker-stock-lots-2026-06-01.csv')[1])
        ->toBe(['2026-06-01', $this->name, 'XYZ', '2026-01-12', 'Purchase', '50', '40.0000', '2000.00'])
        ->and(($this->csv)(Livewire::withQueryParams(['status' => 'all'])->test('pages::options.index')->call('export'), 'protracker-option-positions-2026-06-01.csv')[1])
        ->toMatchArray([2 => 'XYZ', 3 => 'call', 4 => '45.0000', 5 => '2026-03-20', 6 => '2026-02-02', 8 => '1', 9 => '0', 12 => '120.00', 13 => '0.67'])
        ->and(($this->csv)(Livewire::withQueryParams(['status' => 'all'])->test('pages::options.index')->call('exportEvents'), 'protracker-option-events-2026-06-01.csv')[1])
        ->toMatchArray([0 => '2026-03-02', 4 => 'Bought to close', 5 => '1', 6 => '0.4000', 7 => '0.67', 8 => '120.00', 9 => '40.00', 10 => '80.00', 11 => '78.66'])
        ->and(($this->csv)(Livewire::test('pages::dividends.index')->call('export'), 'protracker-dividends-2026-06-01.csv')[1])
        ->toBe(['2026-03-17', '2026-03-10', $this->name, 'XYZ', '0.500000', '100', '50.00', '50.00', 'yes', 'no', 'Imported from activity.csv, row 6.']);
});

it('exports each section of the realized P/L report with its total', function (string $section, array $total) {
    $rows = ($this->csv)(Livewire::test('pages::realized.index')->call('export', $section), "protracker-realized-{$section}-2026-06-01.csv");

    expect(end($rows))->toBe($total);
})->with([
    'stocks' => ['stocks', ['Total', '', '', '', '', '', '', '250.00', '0.98', '249.02']],
    'options' => ['options', ['Total', '', '', '', '', '', '', '80.00', '1.34', '78.66']],
    'dividends' => ['dividends', ['Total', '', '', '', '', '50.00']],
]);

it('exports the fees paid in the period', function () {
    $rows = ($this->csv)(Livewire::test('pages::dashboard')->call('exportFees'), 'protracker-fees-2026-06-01.csv');

    expect(array_column(array_slice($rows, 1), 3))->toBe(['Stock trades', 'Option opening', 'Option closing', 'Stock trades', ''])
        ->and(end($rows))->toBe(['Total', '', '', '', '2.64']);
});

it('stops spreadsheet formulas in text from running', function () {
    StockTrade::query()->first()?->forceFill(['reference_note' => '=HYPERLINK("http://example.test")'])->saveQuietly();

    $rows = ($this->csv)(Livewire::test('pages::stock-trades.index')->call('export'), 'protracker-stock-trades-2026-06-01.csv');

    expect($rows[1][12])->toBe("'=HYPERLINK(\"http://example.test\")")
        ->and(CsvExport::cell(Money::fromDecimal('-4.50')))->toBe('-4.50');
});
