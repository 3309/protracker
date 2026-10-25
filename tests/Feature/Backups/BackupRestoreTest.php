<?php

use App\Actions\Backups\BackupDatabase;
use App\Actions\Backups\RestoreDatabase;
use App\Actions\Cash\BuildCashLedger;
use App\Actions\Imports\CommitImportBatch;
use App\Exceptions\BackupException;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\OptionEvent;
use App\Models\OptionPosition;
use App\Models\StockLot;
use App\Models\StockTrade;
use App\Support\Backups\SqliteFile;
use App\Support\RealizedProfit;
use App\Support\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'protracker-backup-test-'.uniqid();
    File::ensureDirectoryExists($this->folder);
    $this->app->useStoragePath($this->folder.DIRECTORY_SEPARATOR.'storage');

    /** Point the app at a SQLite file, as in real use: backups cannot be made inside the test's transaction. */
    $this->useDatabaseFile = function (string $name, string $path): void {
        config(['database.connections.'.$name => [...config('database.connections.sqlite'), 'database' => $path], 'database.default' => $name]);
        DB::purge($name);
        DB::setDefaultConnection($name);
    };

    touch($live = $this->folder.DIRECTORY_SEPARATOR.'live.sqlite');
    ($this->useDatabaseFile)('live', $live);
    $this->artisan('migrate', ['--database' => 'live']);

    $this->account = Account::factory()->create();
    app(CommitImportBatch::class)->handle(previewTemplateCsv($this->account, [
        '2026-01-05,DEPOSIT,,,,,5000.00,,,,,,,,',
        '2026-01-12,BUY,XYZ,100,40.00,0.65,-4000.65,,,,,,,,',
        '2026-02-02,SELL_TO_OPEN,XYZ,1,1.20,0.67,119.33,call,45,2026-03-20,,,,,',
        '2026-03-02,BUY_TO_CLOSE,XYZ,1,0.40,0.67,-40.67,call,45,2026-03-20,,,,,',
        '2026-03-17,DIVIDEND,XYZ,,0.50,,50.00,,,,,,2026-03-10,,',
        '2026-03-23,SELL,XYZ,50,45.00,0.65,2249.35,,,,,,,,',
    ]));

    /** Core figures read through the app's own models and engines on the default connection. */
    $this->figures = fn (): array => [
        'records' => [StockTrade::count(), StockLot::count(), OptionPosition::count(), OptionEvent::count(), Dividend::count(), CashMovement::count()],
        'cash' => app(BuildCashLedger::class)->handle($this->account->id)->totalOn(CarbonImmutable::parse('2026-12-31'))->format(),
        'realized' => RealizedProfit::for(ReportingPeriod::forYear(2026), null, null)->combinedNet()->format(),
    ];
});

afterEach(function () {
    config(['database.default' => 'sqlite']);
    DB::setDefaultConnection('sqlite');
    DB::purge('live');
    DB::purge('restored');
    gc_collect_cycles();
    File::deleteDirectory($this->folder);
});

it('backs up the database and restores it into a fresh file with every record and the same totals', function () {
    $before = ($this->figures)();
    $backup = app(BackupDatabase::class)->handle();

    expect($backup['path'])->toStartWith(BackupDatabase::directory())
        ->and($backup['sha256'])->toBe(hash_file('sha256', $backup['path']))
        ->and($backup['tables']['stock_trades'])->toBe(2);

    $restoredPath = $this->folder.DIRECTORY_SEPARATOR.'restored.sqlite';
    $result = app(RestoreDatabase::class)->handle($backup['path'], $restoredPath);
    ($this->useDatabaseFile)('restored', $restoredPath);

    expect($result['missingMigrations'])->toBe([])
        ->and(($this->figures)())->toBe($before)
        ->and($before['cash'])->toBe('$3,377.36');
});

it('refuses to restore a damaged file, a file that is not a Protracker database, or a backup from a newer version', function (Closure $makeBackup, string $message) {
    $target = $this->folder.DIRECTORY_SEPARATOR.'target.sqlite';
    file_put_contents($target, 'original');

    expect(fn () => app(RestoreDatabase::class)->handle($makeBackup($this->folder), $target))->toThrow(BackupException::class, $message)
        ->and(file_get_contents($target))->toBe('original');
})->with([
    'damaged' => [function (string $folder): string {
        file_put_contents($path = $folder.'/damaged.sqlite', str_repeat('not a database ', 100));

        return $path;
    }, 'is not a SQLite database'],
    'another database' => [function (string $folder): string {
        (new PDO('sqlite:'.($path = $folder.'/other.sqlite')))->exec('create table notes (body text)');

        return $path;
    }, 'not a Protracker database'],
    'newer version' => [function (string $folder): string {
        $path = app(BackupDatabase::class)->handle(directory: $folder)['path'];
        (new PDO('sqlite:'.$path))->exec("insert into migrations (migration, batch) values ('2099_01_01_000000_future_change', 9)");

        return $path;
    }, 'made by a newer version of the app'],
]);

describe('commands', function () {
    it('backs up with app:backup and test-restores into another file with app:restore --to', function () {
        $this->artisan('app:backup')
            ->expectsOutputToContain('Backup saved and checked')
            ->expectsOutputToContain('The backup is not encrypted')
            ->assertSuccessful();

        $backup = File::files(BackupDatabase::directory())[0];
        $target = $this->folder.DIRECTORY_SEPARATOR.'test-restore.sqlite';

        $this->artisan('app:restore', ['backup' => $backup->getFilename(), '--to' => $target])
            ->expectsOutputToContain("Restored into {$target} and checked")
            ->assertSuccessful();

        expect(SqliteFile::open($target)->tableCounts()['dividends'])->toBe(1);
    });

    it('restores over the app\'s database only after confirmation, backing up the current database first', function () {
        $backup = app(BackupDatabase::class)->handle()['path'];
        DB::table('dividends')->delete();

        $this->artisan('app:restore', ['backup' => $backup])
            ->expectsConfirmation('Restore this backup?', 'no')
            ->expectsOutput('Nothing was changed.')
            ->assertFailed();

        expect(Dividend::count())->toBe(0);

        $this->artisan('app:restore', ['backup' => $backup])
            ->expectsConfirmation('Restore this backup?', 'yes')
            ->expectsOutputToContain('The current database was backed up first')
            ->assertSuccessful();

        $safetyCopy = collect(File::files(BackupDatabase::directory()))->first(fn ($file) => str_starts_with($file->getFilename(), 'before-restore-'));

        expect(Dividend::count())->toBe(1)
            ->and(SqliteFile::open((string) $safetyCopy?->getPathname())->tableCounts()['dividends'])->toBe(0);
    });

    it('does not restore over an in-memory database', function () {
        $backup = app(BackupDatabase::class)->handle()['path'];
        config(['database.default' => 'sqlite']);
        DB::setDefaultConnection('sqlite');

        $this->artisan('app:restore', ['backup' => $backup, '--force' => true])
            ->expectsOutputToContain('Restoring is built for the SQLite database file')
            ->assertFailed();
    });
});
