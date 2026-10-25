<?php

use App\Actions\Cash\BuildCashLedger;
use App\Actions\Imports\AssessImportRows;
use App\Actions\Imports\CommitImportBatch;
use App\Actions\Imports\PreviewImport;
use App\Enums\ImportAction;
use App\Enums\ImportFormatType;
use App\Enums\ImportRowStatus;
use App\Enums\TradeSide;
use App\Exceptions\ImportFileException;
use App\Http\Controllers\ImportTemplateController;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Models\StockTrade;
use App\Support\Money;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->account = Account::factory()->create([
        'stock_fee_per_trade' => Money::fromDecimal('0.65'),
        'option_fee_per_contract' => Money::fromDecimal('0.67'),
        'assignment_fee' => Money::fromDecimal('0'),
    ]);
    $this->commit = fn (ImportBatch $batch) => app(CommitImportBatch::class)->handle($batch);
    $this->statuses = fn (ImportBatch $batch) => $batch->rows()->orderBy('row_number')->get()->map(fn (ImportRow $row) => $row->status)->all();
    $this->cash = fn () => app(BuildCashLedger::class)->handle($this->account->id)->totalOn(CarbonImmutable::parse('2026-12-31'))->format();
    $this->exampleJ = [
        '2026-01-05,DEPOSIT,,,,,5000.00,,,,,,,,',
        '2026-01-12,BUY,XYZ,100,40.00,0.65,-4000.65,,,,,,,,',
        '2026-02-02,SELL_TO_OPEN,XYZ,1,1.20,0.67,119.33,call,45,2026-03-20,,,,,',
        '2026-03-02,BUY_TO_CLOSE,XYZ,1,0.40,0.67,-40.67,call,45,2026-03-20,,,,,',
        '2026-03-17,DIVIDEND,XYZ,,0.50,,50.00,,,,,,2026-03-10,,',
        '2026-03-23,SELL,XYZ,50,45.00,0.65,2249.35,,,,,,,,',
    ];
});

it('Example J: importing the same file twice adds nothing the second time', function () {
    $first = previewTemplateCsv($this->account, $this->exampleJ);

    expect(($this->statuses)($first))->each->toBe(ImportRowStatus::Recognized)
        ->and(($this->commit)($first))->toBe(['imported' => 6, 'failed' => 0])
        ->and(($this->cash)())->toBe('$3,377.36');

    $second = previewTemplateCsv($this->account, $this->exampleJ, 'activity (1).csv');

    expect(($this->statuses)($second))->each->toBe(ImportRowStatus::Duplicate)
        ->and($second->rows()->first()?->messages[0])->toContain('Already imported from activity.csv')
        ->and(($this->commit)($second))->toBe(['imported' => 0, 'failed' => 0])
        ->and(($this->statuses)($second))->each->toBe(ImportRowStatus::Skipped)
        ->and([StockTrade::count(), OptionPosition::count(), OptionEvent::count(), Dividend::count(), CashMovement::count()])->toBe([2, 1, 1, 1, 1])
        ->and(($this->cash)())->toBe('$3,377.36');
});

it('recognizes a duplicate by broker transaction ID even when the values are written differently', function () {
    ($this->commit)(previewTemplateCsv($this->account, ['2026-01-05,DEPOSIT,,,,,5000.00,,,,,,,T-1,']));

    $again = previewTemplateCsv($this->account, ['01/05/2026,deposit,,,,,"$5,000",,,,,,,T-1,Same transfer']);

    expect(($this->statuses)($again))->toBe([ImportRowStatus::Duplicate]);
});

it('imports the downloadable template file cleanly, pairing nothing twice', function () {
    $response = (new ImportTemplateController)();
    ob_start();
    $response->sendContent();
    $contents = (string) ob_get_clean();

    $batch = app(PreviewImport::class)->handle($this->account, ImportFormatType::Template, $contents, 'template.csv', null);

    expect(($this->statuses)($batch))->each->toBe(ImportRowStatus::Recognized)
        ->and(($this->commit)($batch))->toBe(['imported' => 8, 'failed' => 0])
        ->and(($this->cash)())->toBe('$5,409.22');
});

describe('rows that cannot be imported as they are', function () {
    it('flags an unknown action rather than guessing', function () {
        $batch = previewTemplateCsv($this->account, ['2026-01-05,JOURNAL,XYZ,10,,,,,,,,,,,']);

        $row = $batch->rows()->sole();

        expect($row->status)->toBe(ImportRowStatus::Unrecognized)
            ->and($row->messages[0])->toBe('The action "JOURNAL" is not one this format understands. It was not guessed.');
    });

    it('flags missing and invalid values', function (string $line, ImportRowStatus $status, string $message) {
        $row = previewTemplateCsv($this->account, [$line])->rows()->sole();

        expect($row->status)->toBe($status)
            ->and(implode(' ', $row->messages))->toContain($message);
    })->with([
        'no price' => ['2026-01-12,BUY,XYZ,100,,,,,,,,,,,', ImportRowStatus::Incomplete, 'Missing: price.'],
        'bad date' => ['2026-02-30,BUY,XYZ,100,40,,,,,,,,,,', ImportRowStatus::Invalid, 'is not a date'],
        'before the reporting start' => ['2025-12-31,BUY,XYZ,100,40,,,,,,,,,,', ImportRowStatus::Invalid, 'before the reporting start date'],
        'part of a contract' => ['2026-02-02,SELL_TO_OPEN,XYZ,1.5,1.20,,,call,45,2026-03-20,,,,,', ImportRowStatus::Invalid, 'whole number of contracts'],
        'not put or call' => ['2026-02-02,SELL_TO_OPEN,XYZ,1,1.20,,,straddle,45,2026-03-20,,,,,', ImportRowStatus::Invalid, 'is not put or call'],
    ]);

    it('flags an amount that does not match price, quantity and fees', function () {
        $row = previewTemplateCsv($this->account, ['2026-01-12,BUY,XYZ,100,40.00,0.65,-4000.00,,,,,,,,'])->rows()->sole();

        expect($row->status)->toBe(ImportRowStatus::Ambiguous)
            ->and($row->messages[0])->toContain('does not equal price × quantity plus fees ($4,000.65)');
    });

    it('flags a close for an option that is not open', function () {
        $row = previewTemplateCsv($this->account, ['2026-03-02,BUY_TO_CLOSE,XYZ,1,0.40,0.67,,call,45,2026-03-20,,,,,'])->rows()->sole();

        expect($row->status)->toBe(ImportRowStatus::Ambiguous)
            ->and($row->messages[0])->toContain('No open option matches this contract');
    });

    it('flags identical rows in one file for a decision and imports both when chosen', function () {
        $batch = previewTemplateCsv($this->account, [
            '2026-01-12,BUY,XYZ,10,40.00,0,,,,,,,,,',
            '2026-01-12,BUY,XYZ,10,40.00,0,,,,,,,,,',
        ]);

        expect(($this->statuses)($batch))->toBe([ImportRowStatus::Recognized, ImportRowStatus::Ambiguous]);

        $batch->rows()->where('row_number', 3)->update(['import_anyway' => true]);

        expect(($this->commit)($batch->fresh()))->toBe(['imported' => 2, 'failed' => 0])
            ->and(StockTrade::count())->toBe(2);
    });

    it('skips a possible duplicate of an entry made by hand unless chosen', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '40');

        $batch = previewTemplateCsv($this->account, ['2026-01-12,BUY,XYZ,100,40.00,0,,,,,,,,,']);

        expect(($this->statuses)($batch))->toBe([ImportRowStatus::PossibleDuplicate])
            ->and(($this->commit)($batch))->toBe(['imported' => 0, 'failed' => 0])
            ->and(StockTrade::count())->toBe(1)
            ->and($batch->rows()->sole()->skip_reason)->toContain('skipped by default');
    });
});

it('imports what it can, keeps a failed row for review, and retries it after a correction without duplicating', function () {
    $batch = previewTemplateCsv($this->account, [
        '2026-01-12,BUY,XYZ,100,40.00,,,,,,,,,,',
        '2026-02-12,SELL,XYZ,150,42.00,,,,,,,,,,',
        '2026-02-13,DEPOSIT,,,,,100,,,,,,,,',
    ]);

    expect(($this->commit)($batch))->toBe(['imported' => 2, 'failed' => 1]);

    $failed = $batch->rows()->where('status', ImportRowStatus::Failed)->sole();

    expect($failed->messages[0])->toContain('150 needed, 100 available');

    $failed->data = [...$failed->data, 'quantity' => '100'];
    app(AssessImportRows::class)->row($failed);

    expect($failed->status)->toBe(ImportRowStatus::Recognized)
        ->and(app(CommitImportBatch::class)->importRow($failed->load('batch.account')))->toBeTrue()
        ->and(StockTrade::count())->toBe(2)
        ->and(CashMovement::count())->toBe(1);

    $again = previewTemplateCsv($this->account, [
        '2026-01-12,BUY,XYZ,100,40.00,,,,,,,,,,',
        '2026-02-13,DEPOSIT,,,,,100,,,,,,,,',
    ]);

    expect(($this->statuses)($again))->each->toBe(ImportRowStatus::Duplicate);
});

it('imports a dividend once its ex-date is entered, working out the amount per share', function () {
    ($this->commit)(previewTemplateCsv($this->account, ['2026-03-02,BUY,XYZ,40,50.00,,,,,,,,,,']));

    $batch = previewTemplateCsv($this->account, ['2026-03-31,DIVIDEND,XYZ,,,,10.00,,,,,,,,']);
    $row = $batch->rows()->sole();

    expect($row->status)->toBe(ImportRowStatus::Incomplete)
        ->and($row->messages[0])->toContain('Needs the ex-dividend date');

    ($this->commit)($batch);
    $row = $row->fresh();
    $row->data = [...$row->data, 'ex_date' => '2026-03-10'];
    app(AssessImportRows::class)->row($row);
    app(CommitImportBatch::class)->importRow($row->load('batch.account'));

    expect(Dividend::sole())
        ->amount_per_share->format()->toBe('$0.25')
        ->actual_amount->format()->toBe('$10.00')
        ->hasDifference()->toBeFalse();
});

it('pairs an assignment with the stock row for it, so the stock trade is recorded once', function () {
    $batch = previewTemplateCsv($this->account, [
        '2026-01-12,SELL_TO_OPEN,XYZ,1,1.20,0.67,,put,50,2026-02-20,,,,,',
        '2026-02-20,ASSIGNED,XYZ,1,,0,,put,50,2026-02-20,,,,,',
        '2026-02-20,BUY,XYZ,100,50.00,0,-5000.00,,,,,,,,Bought by assignment',
    ]);

    $stockRow = $batch->rows()->where('row_number', 4)->sole();

    expect($stockRow->status)->toBe(ImportRowStatus::Skipped)
        ->and($stockRow->messages[0])->toContain('Part of the assignment in row 3');

    ($this->commit)($batch);

    expect(StockTrade::sole()->isFromAssignment())->toBeTrue()
        ->and(($this->cash)())->toBe('-$4,880.67');
});

it('uses account default fees when the file has none, and keeps fees it gives', function () {
    ($this->commit)(previewTemplateCsv($this->account, [
        '2026-01-12,BUY,XYZ,100,40.00,,,,,,,,,,',
        '2026-01-13,BUY,ABC,10,20.00,1.00,,,,,,,,,',
        '2026-02-02,SELL_TO_OPEN,XYZ,3,1.20,,,call,45,2026-03-20,,,,,',
    ]));

    expect(StockTrade::query()->orderBy('id')->get()->map(fn ($trade) => $trade->fees->format())->all())->toBe(['$0.65', '$1.00'])
        ->and(OptionPosition::sole()->opening_fees->format())->toBe('$2.01');
});

it('keeps the original row, the file name, its fingerprint and a link to the record created', function () {
    $contents = "date,action,symbol,quantity,price,fees,amount,option_type,strike,expiration\n2026-01-12,BUY,XYZ,100,40.00,0.65,,,,";
    $batch = app(PreviewImport::class)->handle($this->account, ImportFormatType::Template, $contents, 'C:\\fakepath\\statement.csv', null);
    ($this->commit)($batch);

    $row = $batch->rows()->sole();

    expect($batch->original_filename)->toBe('statement.csv')
        ->and($batch->file_sha256)->toBe(hash('sha256', $contents))
        ->and($row->raw)->toBe(['date' => '2026-01-12', 'action' => 'BUY', 'symbol' => 'XYZ', 'quantity' => '100', 'price' => '40.00', 'fees' => '0.65', 'amount' => '', 'option_type' => '', 'strike' => '', 'expiration' => ''])
        ->and($row->imported)->toBeInstanceOf(StockTrade::class)
        ->and($row->imported?->reference_note)->toBe('Imported from statement.csv, row 2.');
});

it('imports rows in date order even when the file lists the newest first', function () {
    $batch = previewTemplateCsv($this->account, [
        '2026-02-12,SELL,XYZ,100,42.00,0,,,,,,,,,',
        '2026-01-12,BUY,XYZ,100,40.00,0,,,,,,,,,',
    ]);

    expect(($this->commit)($batch))->toBe(['imported' => 2, 'failed' => 0])
        ->and(ImportRow::query()->where('action', ImportAction::Sell)->sole()->status)->toBe(ImportRowStatus::Imported);
});

it('refuses a file without the required columns, or with no rows', function (string $contents, string $message) {
    expect(fn () => app(PreviewImport::class)->handle($this->account, ImportFormatType::Template, $contents, 'bad.csv', null))
        ->toThrow(ImportFileException::class, $message);

    expect(ImportBatch::count())->toBe(0);
})->with([
    'wrong columns' => ["Run Date,Action,Symbol\n01/12/2026,YOU BOUGHT,XYZ", 'Missing columns: date, quantity, price'],
    'empty' => ['', 'The file is empty.'],
    'header only' => ['date,action,symbol,quantity,price,fees,amount,option_type,strike,expiration', 'no rows'],
]);
