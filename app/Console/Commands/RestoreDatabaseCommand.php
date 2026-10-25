<?php

namespace App\Console\Commands;

use App\Actions\Backups\BackupDatabase;
use App\Actions\Backups\RestoreDatabase;
use App\Exceptions\BackupException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:restore
    {backup : The backup file, or its name in storage/app/private/backups}
    {--to= : Restore into this file instead of the app\'s database, for a test restore}
    {--force : Do not ask for confirmation}')]
#[Description('Restore the database from a backup, backing up the current database first')]
class RestoreDatabaseCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BackupDatabase $backupDatabase, RestoreDatabase $restoreDatabase): int
    {
        $backupPath = $this->backupPath((string) $this->argument('backup'));
        $testTarget = $this->option('to');

        try {
            if (is_string($testTarget) && $testTarget !== '') {
                return $this->report($restoreDatabase->handle($backupPath, $testTarget), $testTarget, live: false);
            }

            $databasePath = (string) config('database.connections.'.config('database.default').'.database');

            if (DB::connection()->getDriverName() !== 'sqlite' || $databasePath === ':memory:') {
                throw new BackupException('Restoring is built for the SQLite database file this app uses.');
            }

            $this->warn("This replaces everything in {$databasePath} with the backup {$backupPath}.");

            if (! $this->option('force') && ! $this->confirm('Restore this backup?')) {
                $this->line('Nothing was changed.');

                return self::FAILURE;
            }

            $safetyCopy = $backupDatabase->handle('before-restore');
            $this->info("The current database was backed up first: {$safetyCopy['path']}");

            return $this->report($restoreDatabase->handle($backupPath, $databasePath), $databasePath, live: true);
        } catch (BackupException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array{tables: array<string, int>, missingMigrations: list<string>}  $result
     */
    private function report(array $result, string $target, bool $live): int
    {
        $this->info("Restored into {$target} and checked: it passes SQLite's integrity check.");
        $this->line('Records: '.implode(', ', array_map(
            fn (string $table, int $count) => "{$table} {$count}",
            array_keys($result['tables']),
            $result['tables'],
        )));

        if ($result['missingMigrations'] !== []) {
            if ($live) {
                $this->line('The backup is from an earlier version of the app; updating its tables now.');
                $this->call('migrate', ['--force' => true]);
            } else {
                $this->warn('The backup is from an earlier version of the app. Run "php artisan migrate" after restoring it as the app\'s database.');
            }
        }

        return self::SUCCESS;
    }

    private function backupPath(string $backup): string
    {
        $inBackupFolder = BackupDatabase::directory().DIRECTORY_SEPARATOR.$backup;

        return ! is_file($backup) && is_file($inBackupFolder) ? $inBackupFolder : $backup;
    }
}
