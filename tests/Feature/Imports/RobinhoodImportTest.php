<?php

use App\Actions\Cash\BuildCashLedger;
use App\Actions\Imports\CommitImportBatch;
use App\Enums\CashMovementType;
use App\Enums\ImportAction;
use App\Enums\ImportRowStatus;
use App\Enums\OptionEventType;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\OptionEvent;
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
    $this->cash = fn () => app(BuildCashLedger::class)->handle($this->account->id)->totalOn(CarbonImmutable::parse('2026-12-31'))->format();
    $this->activity = [
        "\"3/23/2026\",\"3/23/2026\",\"3/24/2026\",\"XYZ\",\"Xyz Corp\nCUSIP: 000000000\",\"Sell\",\"50\",\"\$45.00\",\"\$2,249.88\"",
        '"3/2/2026","3/2/2026","3/3/2026","XYZ","XYZ 3/20/2026 Call $45.00","BTC","1","$0.40","($40.03)"',
        '"2/2/2026","2/2/2026","2/3/2026","XYZ","XYZ 3/20/2026 Call $45.00","STO","1","$1.20","$119.95"',
        "\"1/12/2026\",\"1/12/2026\",\"1/13/2026\",\"XYZ\",\"Xyz Corp\nCUSIP: 000000000\",\"Buy\",\"100\",\"\$40.00\",\"(\$4,000.50)\"",
        '"1/7/2026","1/7/2026","1/7/2026","XYZ","Stock Lending","SLIP","","","$0.01"',
        '"1/6/2026","1/6/2026","1/6/2026","","Interest on Contribution (IRA Match)","MTCH","","","$15.00"',
        '"1/5/2026","1/5/2026","1/6/2026","","Current Year Contribution","CFIR","","","$10,000.00"',
    ];
});

it('imports trades, options, contributions, stock lending and IRA matches with cash matching the report, and adds nothing when imported again', function () {
    $first = previewRobinhoodCsv($this->account, $this->activity);

    expect(($this->rows)($first)->map(fn (ImportRow $row) => [$row->row_number, $row->action, $row->status])->all())->toBe([
        [2, ImportAction::Sell, ImportRowStatus::Recognized],
        [4, ImportAction::BuyToClose, ImportRowStatus::Recognized],
        [5, ImportAction::SellToOpen, ImportRowStatus::Recognized],
        [6, ImportAction::Buy, ImportRowStatus::Recognized],
        [8, ImportAction::OtherCredit, ImportRowStatus::Recognized],
        [9, ImportAction::OtherCredit, ImportRowStatus::Recognized],
        [10, ImportAction::Deposit, ImportRowStatus::Recognized],
    ])
        ->and(($this->commit)($first))->toBe(['imported' => 7, 'failed' => 0])
        ->and(($this->cash)())->toBe('$8,344.31')
        ->and(OptionEvent::sole()->fees->format())->toBe('$0.03')
        ->and(CashMovement::query()->where('movement_type', CashMovementType::OtherCredit)->orderBy('movement_date')->pluck('reference_note')->all())->toBe([
            'Imported from robinhood.csv, row 9. IRA match: Interest on Contribution (IRA Match)',
            'Imported from robinhood.csv, row 8. Stock lending income (XYZ).',
        ]);

    $second = previewRobinhoodCsv($this->account, $this->activity, 'robinhood (1).csv');

    expect(($this->rows)($second)->map(fn (ImportRow $row) => $row->status)->all())->each->toBe(ImportRowStatus::Duplicate)
        ->and(($this->commit)($second))->toBe(['imported' => 0, 'failed' => 0])
        ->and(($this->cash)())->toBe('$8,344.31');
});

it('takes stock prices and fees from the Amount, which includes fees', function (string $line, string $price, string $fees) {
    if (str_contains($line, '"Sell"')) {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-02', '100', '20');
    }

    ($this->commit)(previewRobinhoodCsv($this->account, [$line]));

    expect(StockTrade::query()->latest('id')->first())
        ->price_per_share->toDecimal()->toBe($price)
        ->fees->toDecimal()->toBe($fees);
})->with([
    'a buy at a rounded price' => ['"1/12/2026","1/12/2026","1/13/2026","XYZ","Xyz","Buy","25","$26.20","($654.93)"', '26.1972', '0.00'],
    'a buy that does not divide evenly' => ['"1/12/2026","1/12/2026","1/13/2026","XYZ","Xyz","Buy","3","$3.33","($10.00)"', '3.3333', '0.00'],
    'a sale with fees taken off' => ['"1/12/2026","1/12/2026","1/13/2026","XYZ","Xyz","Sell","25","$26.44","$660.86"', '26.44', '0.14'],
    'a sale above the price shown' => ['"1/12/2026","1/12/2026","1/13/2026","XYZ","Xyz","Sell","25","$26.44","$661.10"', '26.444', '0.00'],
]);

it('records an option expiration', function () {
    $batch = previewRobinhoodCsv($this->account, [
        '"3/20/2026","3/20/2026","3/23/2026","XYZ","Option Expiration for XYZ 3/20/2026 Put $32.00","OEXP","1","",""',
        '"2/2/2026","2/2/2026","2/3/2026","XYZ","XYZ 3/20/2026 Put $32.00","STO","1","$0.37","$36.95"',
    ]);

    expect(($this->commit)($batch))->toBe(['imported' => 2, 'failed' => 0])
        ->and(OptionEvent::sole())
        ->event_type->toBe(OptionEventType::Expired)
        ->event_date->format('Y-m-d')->toBe('2026-03-20')
        ->position->strike->toDecimal()->toBe('32');
});

describe('cash dividends', function () {
    it('uses the record date as the ex-date and the amount per share from the description', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-02', '30', '50');

        $batch = previewRobinhoodCsv($this->account, ['"3/31/2026","3/31/2026","3/31/2026","XYZ","Cash Div: R/D 2026-03-10 P/D 2026-03-31 - 30 shares at 0.2635","CDIV","","","$7.91"']);

        expect(($this->commit)($batch))->toBe(['imported' => 1, 'failed' => 0])
            ->and(Dividend::sole())
            ->ex_date->format('Y-m-d')->toBe('2026-03-10')
            ->amount_per_share->toDecimal()->toBe('0.2635')
            ->actual_amount->format()->toBe('$7.91');
    });

    it('leaves the ex-date for you to enter when the record date is before T+1 settlement', function () {
        $row = previewRobinhoodCsv($this->account, ['"5/15/2024","5/15/2024","5/15/2024","XYZ","Cash Div: R/D 2024-05-01 P/D 2024-05-15 - 30 shares at 0.2635","CDIV","","","$7.91"'])->rows()->sole();

        expect($row->action)->toBe(ImportAction::Dividend)
            ->and($row->data['ex_date'])->toBe('')
            ->and($row->data['price'])->toBe('0.2635');
    });
});

it('flags transaction codes it has not seen instead of guessing', function (string $line, string $message) {
    $row = previewRobinhoodCsv($this->account, [$line])->rows()->sole();

    expect($row->status)->toBe(ImportRowStatus::Unrecognized)
        ->and($row->messages[0])->toContain($message);
})->with([
    'an assignment' => ['"3/20/2026","3/20/2026","3/23/2026","XYZ","Option Assigned for XYZ 3/20/2026 Put $32.00","OASGN","1","",""', 'The transaction code "OASGN"'],
    'an option bought to open' => ['"3/2/2026","3/2/2026","3/3/2026","XYZ","XYZ 3/20/2026 Put $32.00","BTO","1","$0.40","($40.03)"', 'Protracker tracks only options you sell'],
    'a bank transfer' => ['"1/5/2026","1/5/2026","1/5/2026","","ACH Deposit","ACH","","","$500.00"', 'The transaction code "ACH"'],
    'an unreadable option' => ['"2/2/2026","2/2/2026","2/3/2026","XYZ","XYZ March Put","STO","1","$0.37","$36.95"', 'not in the expected form'],
]);

it('skips the blank line and the disclaimer at the end of the report', function () {
    $batch = previewRobinhoodCsv($this->account, [
        '"1/5/2026","1/5/2026","1/6/2026","","Current Year Contribution","CFIR","","","$500.00"',
        '""',
        '"","","","","","","","","","The data provided is for informational purposes only."',
    ]);

    expect($batch->row_count)->toBe(1);
});
