<?php

use App\Actions\Imports\CommitImportBatch;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Enums\TradeSide;
use App\Models\Account;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\StockTrade;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->account = Account::factory()->create(['nickname' => 'Main']);
    $this->header = 'date,action,symbol,quantity,price,fees,amount,option_type,strike,expiration';
});

it('sends guests to the login page', function (string $url) {
    $this->app['auth']->logout();

    $this->get($url)->assertRedirect(route('login'));
})->with(['/imports', '/imports/create', '/imports/review', '/imports/template']);

it('downloads the template', function () {
    $this->get(route('imports.template'))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=utf-8')
        ->assertDownload('protracker-import-template.csv');
});

describe('uploading', function () {
    it('previews a file without importing anything', function () {
        $file = UploadedFile::fake()->createWithContent('activity.csv', "{$this->header}\n2026-01-12,BUY,XYZ,100,40.00,0,,,,");

        Livewire::test('pages::imports.create')
            ->set('account_id', (string) $this->account->id)
            ->set('file', $file)
            ->call('preview')
            ->assertHasNoErrors()
            ->assertRedirect(route('imports.show', ImportBatch::sole()));

        expect(ImportBatch::sole()->status)->toBe(ImportBatchStatus::Previewed)
            ->and(StockTrade::count())->toBe(0);
    });

    it('explains a file in the wrong format', function () {
        $file = UploadedFile::fake()->createWithContent('history.csv', "Run Date,Action\n01/12/2026,YOU BOUGHT");

        Livewire::test('pages::imports.create')
            ->set('account_id', (string) $this->account->id)
            ->set('file', $file)
            ->call('preview')
            ->assertHasErrors('file')
            ->assertSee('does not look like the Protracker template format');

        expect(ImportBatch::count())->toBe(0);
    });

    it('refuses formats not built yet, files that are not CSV, and missing choices', function (string $field, mixed $value) {
        $page = Livewire::test('pages::imports.create')
            ->set('account_id', (string) $this->account->id)
            ->set('file', UploadedFile::fake()->createWithContent('activity.csv', "{$this->header}\n"));

        $page->set($field, $value)->call('preview')->assertHasErrors($field);
    })->with([
        'Fidelity before a sample exists' => ['format', 'fidelity'],
        'a PDF' => ['file', fn () => UploadedFile::fake()->create('statement.pdf', 10, 'application/pdf')],
        'no account' => ['account_id', ''],
    ]);
});

describe('preview page', function () {
    it('shows each row with its status and imports the ready rows when confirmed', function () {
        $batch = previewTemplateCsv($this->account, [
            '2026-01-12,BUY,XYZ,100,40.00,0,,,,,,,,,',
            '2026-01-13,JOURNAL,XYZ,1,,,,,,,,,,,',
        ]);

        Livewire::test('pages::imports.show', ['importBatch' => $batch])
            ->assertSeeInOrder(['Stock buy', 'XYZ', 'Ready to import', 'JOURNAL', 'Not recognized'])
            ->assertSee('Import 1 row')
            ->call('commit')
            ->assertSee('1 row imported.');

        expect(StockTrade::count())->toBe(1)
            ->and($batch->fresh()->status)->toBe(ImportBatchStatus::Committed);
    });

    it('imports a row needing a decision only when "import anyway" is ticked', function () {
        recordStockTrade($this->account, TradeSide::Buy, 'XYZ', '2026-01-12', '100', '40');
        $batch = previewTemplateCsv($this->account, ['2026-01-12,BUY,XYZ,100,40.00,0,,,,,,,,,']);
        $row = $batch->rows()->sole();

        Livewire::test('pages::imports.show', ['importBatch' => $batch])
            ->assertSee('Possible duplicate of an entry made by hand')
            ->assertSee('Import 0 rows')
            ->call('toggleImportAnyway', $row->id)
            ->assertSee('Import 1 row')
            ->call('commit');

        expect(StockTrade::count())->toBe(2);
    });

    it('discards a preview, removing its rows', function () {
        $batch = previewTemplateCsv($this->account, ['2026-01-12,BUY,XYZ,100,40.00,0,,,,,,,,,']);

        Livewire::test('pages::imports.show', ['importBatch' => $batch])
            ->call('discard')
            ->assertRedirect(route('imports.index'));

        expect(ImportBatch::count())->toBe(0)
            ->and(ImportRow::count())->toBe(0);
    });

    it('does not let an imported file be imported or discarded again', function () {
        $batch = previewTemplateCsv($this->account, ['2026-01-12,BUY,XYZ,100,40.00,0,,,,,,,,,']);
        app(CommitImportBatch::class)->handle($batch);

        Livewire::test('pages::imports.show', ['importBatch' => $batch->fresh()])
            ->call('commit')
            ->assertForbidden();

        expect(StockTrade::count())->toBe(1);
    });
});

describe('review queue', function () {
    beforeEach(function () {
        $this->batch = previewTemplateCsv($this->account, [
            '2026-01-12,BUY,XYZ,100,,0,,,,,,,,,',
            '2026-01-13,JOURNAL,XYZ,1,,,,,,,,,,,',
        ]);
        app(CommitImportBatch::class)->handle($this->batch);
    });

    it('lists rows that were not imported, with the reason and the original values', function () {
        Livewire::test('pages::imports.review')
            ->assertSeeInOrder(['line 2', 'Incomplete', 'Missing: price.', 'line 3', 'Not recognized', 'JOURNAL']);
    });

    it('corrects a row, checks it again and imports it', function () {
        $row = $this->batch->rows()->where('row_number', 2)->sole();

        Livewire::test('pages::imports.review')
            ->call('edit', $row->id)
            ->assertSet('action', 'buy')
            ->set('fields.price', '40.00')
            ->call('saveCorrection')
            ->assertSee('Row 2 checked again: Ready to import.')
            ->call('import', $row->id)
            ->assertSee('Row 2 imported.');

        expect(StockTrade::count())->toBe(1)
            ->and($row->fresh()->status)->toBe(ImportRowStatus::Imported);
    });

    it('skips a row with a reason', function () {
        $row = $this->batch->rows()->where('row_number', 3)->sole();

        Livewire::test('pages::imports.review')
            ->call('edit', $row->id)
            ->call('skip', $row->id)
            ->assertHasErrors('skipReason')
            ->set('skipReason', 'Internal journal, not a trade')
            ->call('skip', $row->id)
            ->assertSee('Row 3 skipped.');

        expect($row->fresh())
            ->status->toBe(ImportRowStatus::Skipped)
            ->skip_reason->toBe('Internal journal, not a trade');
    });

    it('shows waiting rows on the dashboard', function () {
        $this->get(route('dashboard'))->assertSee('2 imported rows need review before they can be imported or skipped.');
    });
});
