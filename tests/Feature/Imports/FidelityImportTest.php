<?php

use App\Actions\Cash\BuildCashLedger;
use App\Actions\Imports\AssessImportRows;
use App\Actions\Imports\CommitImportBatch;
use App\Actions\Imports\PreviewImport;
use App\Enums\CashMovementType;
use App\Enums\ImportAction;
use App\Enums\ImportFormatType;
use App\Enums\ImportRowStatus;
use App\Enums\OptionEventType;
use App\Exceptions\ImportFileException;
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
    $this->rows = fn (ImportBatch $batch) => $batch->rows()->orderBy('row_number')->get();
    $this->statuses = fn (ImportBatch $batch) => ($this->rows)($batch)->map(fn (ImportRow $row) => $row->status)->all();
    $this->cash = fn () => app(BuildCashLedger::class)->handle($this->account->id)->totalOn(CarbonImmutable::parse('2026-12-31'))->format();
    $this->activity = [
        '2026-01-05,$CASH-USD,CASH,10000,DEPOSIT,1.00,USD,0.00,10000.00,,me',
        '2026-01-12,XYZ,EQUITY,100,BUY,40.00,USD,0.00,,,me',
        '2026-02-02,XYZ260320C00045000,OPTION,1,SELL,1.20,USD,0.67,,STO,me',
        '2026-03-02,XYZ260320C00045000,OPTION,1,BUY,0.40,USD,0.03,,BTC,me',
        '2026-03-23,XYZ,EQUITY,50,SELL,45.00,USD,0.12,,,me',
        '2026-03-31,FDRXX,EQUITY,12.34,DIVIDEND,1.00,USD,0.00,12.34,DRIP,me',
        '2026-04-01,$CASH-USD,CASH,1000,WITHDRAWAL,1.00,USD,0.00,1000.00,,me',
    ];
});

it('imports stock trades, sold options, buy-backs, cash sweep income and cash movements, and adds nothing when imported again', function () {
    $first = previewFidelityCsv($this->account, $this->activity);

    expect(($this->rows)($first)->map(fn (ImportRow $row) => $row->action)->all())->toBe([
        ImportAction::Deposit, ImportAction::Buy, ImportAction::SellToOpen, ImportAction::BuyToClose,
        ImportAction::Sell, ImportAction::Interest, ImportAction::Withdrawal,
    ])
        ->and(($this->statuses)($first))->each->toBe(ImportRowStatus::Recognized)
        ->and(($this->commit)($first))->toBe(['imported' => 7, 'failed' => 0])
        ->and(($this->cash)())->toBe('$7,341.52')
        ->and(CashMovement::query()->where('movement_type', CashMovementType::Interest)->sole()->reference_note)
        ->toBe('Imported from fidelity.csv, row 7. FDRXX cash sweep income, imported as interest on cash.');

    $second = previewFidelityCsv($this->account, $this->activity, 'fidelity (1).csv');

    expect(($this->statuses)($second))->each->toBe(ImportRowStatus::Duplicate)
        ->and(($this->commit)($second))->toBe(['imported' => 0, 'failed' => 0])
        ->and([StockTrade::count(), OptionPosition::count(), OptionEvent::count(), CashMovement::count()])->toBe([2, 1, 1, 3])
        ->and(($this->cash)())->toBe('$7,341.52');
});

it('reads the contract from the option symbol', function (string $symbol, array $contract) {
    $batch = previewFidelityCsv($this->account, ["2026-02-02,{$symbol},OPTION,1,SELL,1.20,USD,0.67,,STO,me"]);

    expect($batch->rows()->sole()->data)->toMatchArray($contract);
})->with([
    'whole strike' => ['XYZ260320C00045000', ['symbol' => 'XYZ', 'option_type' => 'call', 'strike' => '45', 'expiration' => '2026-03-20']],
    'half-dollar strike' => ['TSLA260717P00272500', ['symbol' => 'TSLA', 'option_type' => 'put', 'strike' => '272.5', 'expiration' => '2026-07-17']],
    'four-figure strike' => ['SPXW260320P05540000', ['symbol' => 'SPXW', 'option_type' => 'put', 'strike' => '5540', 'expiration' => '2026-03-20']],
]);

it('dates an expiry on the option\'s expiration rather than the day the broker posted it', function () {
    $batch = previewFidelityCsv($this->account, [
        '2026-02-02,XYZ260320P00040000,OPTION,1,SELL,1.20,USD,0.67,,STO,me',
        '2026-03-23,XYZ260320P00040000,OPTION,1,ADJUSTMENT,0.00,USD,0.00,,OPTION_EXPIRY,me',
    ]);

    expect(($this->commit)($batch))->toBe(['imported' => 2, 'failed' => 0])
        ->and(OptionEvent::sole())
        ->event_type->toBe(OptionEventType::Expired)
        ->event_date->format('Y-m-d')->toBe('2026-03-20')
        ->reference_note->toBe('Imported from fidelity.csv, row 3. Posted by the broker on 2026-03-23.');
});

describe('expiries that were assignments', function () {
    it('records an expiry as an assignment when the file has the stock trade at the strike', function (string $type, array $stockLines, string $cash) {
        $option = $type === 'put' ? 'XYZ260320P00040000' : 'XYZ260320C00040000';
        $batch = previewFidelityCsv($this->account, [
            '2026-01-05,$CASH-USD,CASH,10000,DEPOSIT,1.00,USD,0.00,10000.00,,me',
            ...$stockLines,
            "2026-02-02,{$option},OPTION,1,SELL,1.20,USD,0.67,,STO,me",
            "2026-03-23,{$option},OPTION,1,ADJUSTMENT,0.00,USD,0.00,,OPTION_EXPIRY,me",
            '2026-03-23,XYZ,EQUITY,100,'.($type === 'put' ? 'BUY' : 'SELL').',40.00,USD,0.17,,,me',
        ]);
        $rows = ($this->rows)($batch);

        expect($rows[$rows->count() - 2]->action)->toBe(ImportAction::Assigned)
            ->and($rows->last()->status)->toBe(ImportRowStatus::Skipped)
            ->and(($this->commit)($batch)['failed'])->toBe(0);

        $assignment = OptionEvent::query()->where('event_type', OptionEventType::Assigned)->sole();

        expect($assignment->event_date->format('Y-m-d'))->toBe('2026-03-20')
            ->and(StockTrade::query()->where('option_event_id', $assignment->id)->sole())
            ->shares->toDecimal()->toBe('100')
            ->price_per_share->format()->toBe('$40.00')
            ->fees->format()->toBe('$0.17')
            ->and(($this->cash)())->toBe($cash);
    })->with([
        'put, with the shares bought' => ['put', [], '$6,119.16'],
        'call, with the shares sold' => ['call', ['2026-01-12,XYZ,EQUITY,100,BUY,38.00,USD,0.00,,,me'], '$10,319.16'],
    ]);

    it('keeps an expiry as expired when the stock trade is not at the strike', function () {
        $batch = previewFidelityCsv($this->account, [
            '2026-01-05,$CASH-USD,CASH,10000,DEPOSIT,1.00,USD,0.00,10000.00,,me',
            '2026-02-02,XYZ260320P00040000,OPTION,1,SELL,1.20,USD,0.67,,STO,me',
            '2026-03-23,XYZ260320P00040000,OPTION,1,ADJUSTMENT,0.00,USD,0.00,,OPTION_EXPIRY,me',
            '2026-03-23,XYZ,EQUITY,100,BUY,38.00,USD,0.00,,,me',
        ]);

        expect(($this->commit)($batch))->toBe(['imported' => 4, 'failed' => 0])
            ->and(OptionEvent::sole()->event_type)->toBe(OptionEventType::Expired)
            ->and(StockTrade::sole()->option_event_id)->toBeNull();
    });
});

it('closes identical contracts oldest first, sharing the fees, when one row closes several positions', function () {
    $batch = previewFidelityCsv($this->account, [
        '2026-02-02,XYZ260320P00040000,OPTION,1,SELL,1.20,USD,0.67,,STO,me',
        '2026-02-03,XYZ260320P00040000,OPTION,9,SELL,1.10,USD,6.03,,STO,me',
        '2026-03-02,XYZ260320P00040000,OPTION,10,BUY,0.10,USD,6.70,,BTC,me',
    ]);

    expect(($this->commit)($batch))->toBe(['imported' => 3, 'failed' => 0])
        ->and(OptionEvent::query()->orderBy('id')->get()->map(fn (OptionEvent $event) => [$event->contracts, $event->fees->format(), $event->position->opened_on->format('Y-m-d')])->all())
        ->toBe([[1, '$0.67', '2026-02-02'], [9, '$6.03', '2026-02-03']])
        ->and(OptionPosition::query()->open()->count())->toBe(0)
        ->and(OptionEvent::query()->orderBy('id')->first()?->reference_note)->toEndWith('(part 1 of 2)');
});

describe('dividends', function () {
    beforeEach(function () {
        ($this->commit)(previewFidelityCsv($this->account, ['2026-01-12,XYZ,EQUITY,50,BUY,40.00,USD,0.00,,,me']));
        $this->importWithExDate = function (string $amount): ImportRow {
            $batch = previewFidelityCsv($this->account, ["2026-03-31,XYZ,EQUITY,1,DIVIDEND,1.00,USD,0.00,{$amount},,me"], 'dividends.csv');
            ($this->commit)($batch);
            $row = $batch->rows()->sole();
            $row->data = [...$row->data, 'ex_date' => '2026-03-10'];
            app(AssessImportRows::class)->row($row);
            app(CommitImportBatch::class)->importRow($row->load('batch.account'));

            return $row->fresh();
        };
    });

    it('waits for the ex-date, then works out the amount per share from the cash received', function () {
        $row = ($this->importWithExDate)('25.00');

        expect($row->status)->toBe(ImportRowStatus::Imported)
            ->and(Dividend::sole())
            ->amount_per_share->format()->toBe('$0.50')
            ->actual_amount->format()->toBe('$25.00');
    });

    it('does not import a "dividend" too large to be one, such as a split listed as a dividend', function () {
        $row = ($this->importWithExDate)('1427.00');

        expect($row->status)->toBe(ImportRowStatus::Failed)
            ->and($row->messages[0])->toContain('more than 10% of the last price paid for XYZ ($40.00)')
            ->and($row->messages[0])->toContain('stock split')
            ->and(Dividend::count())->toBe(0);
    });
});

it('flags rows it does not understand instead of guessing', function (string $line, string $message) {
    $row = previewFidelityCsv($this->account, [$line])->rows()->sole();

    expect($row->status)->toBe(ImportRowStatus::Unrecognized)
        ->and($row->action)->toBeNull()
        ->and($row->messages[0])->toContain($message);
})->with([
    'a cash adjustment' => ['2026-01-22,$CASH-USD,CASH,11.03,ADJUSTMENT,1.00,USD,0.00,11.03,,me', 'A cash adjustment'],
    'an option bought to open' => ['2026-01-22,XYZ260320P00040000,OPTION,1,BUY,0.85,USD,0.68,,BTO,me', 'Protracker tracks only options you sell'],
    'an option sold to close' => ['2026-01-22,XYZ260320P00040000,OPTION,1,SELL,0.65,USD,0.68,,STC,me', 'Protracker tracks only options you sell'],
    'a reinvested stock dividend' => ['2026-01-22,XYZ,EQUITY,1.5,DIVIDEND,40.00,USD,0.00,60.00,DRIP,me', 'A reinvested dividend (DRIP) of XYZ'],
    'another currency' => ['2026-01-22,XYZ,EQUITY,100,BUY,40.00,CAD,0.00,,,me', 'Only US dollars'],
    'an unfamiliar activity' => ['2026-01-22,XYZ,EQUITY,100,TRANSFER,40.00,USD,0.00,,,me', 'not one this format understands'],
    'an unreadable option symbol' => ['2026-01-22,XYZ-MAR-40-P,OPTION,1,SELL,1.20,USD,0.67,,STO,me', 'not in the expected form'],
]);

it('reads exports pasted together, skipping the repeated header and importing in date order', function () {
    $batch = previewFidelityCsv($this->account, [
        '2026-03-23,XYZ,EQUITY,100,SELL,45.00,USD,0.12,,,me',
        'date,symbol,instrumentType,quantity,activityType,unitPrice,currency,fee,amount,subtype,account',
        '2026-01-12,XYZ,EQUITY,100,BUY,40.00,USD,0.00,,,me',
    ]);

    expect($batch->row_count)->toBe(2)
        ->and(($this->rows)($batch)->pluck('row_number')->all())->toBe([2, 4])
        ->and(($this->commit)($batch))->toBe(['imported' => 2, 'failed' => 0]);
});

it('refuses a file in another layout', function () {
    expect(fn () => app(PreviewImport::class)->handle($this->account, ImportFormatType::Fidelity, "Run Date,Action,Symbol,Quantity\n01/12/2026,YOU BOUGHT,XYZ,100", 'history.csv', null))
        ->toThrow(ImportFileException::class, 'does not look like the Fidelity activity format. Missing columns: date, instrumentType, activityType, unitPrice, currency, fee, amount, subtype.');
});
