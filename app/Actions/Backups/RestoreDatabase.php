<?php

namespace App\Actions\Backups;

use App\Exceptions\BackupException;
use App\Support\Backups\SqliteFile;
use Illuminate\Support\Facades\DB;

/**
 * Restores a backup file over a SQLite database file. The backup is checked
 * first; a backup made by a newer version of the app (with migrations this
 * version does not have) is refused. The file is copied beside the target
 * and then moved into place, so a failed copy never leaves a half-written
 * database. The restored file is checked again afterwards.
 */
class RestoreDatabase
{
    /**
     * @return array{tables: array<string, int>, missingMigrations: list<string>}
     *
     * @throws BackupException
     */
    public function handle(string $backupPath, string $targetPath): array
    {
        $backup = SqliteFile::open($backupPath);
        $backup->ensureUsable();

        $knownMigrations = array_map(fn (string $file) => basename($file, '.php'), glob(database_path('migrations/*.php')) ?: []);
        $unknown = array_diff($backup->migrations(), $knownMigrations);

        if ($unknown !== []) {
            throw new BackupException('The backup was made by a newer version of the app (unknown migrations: '.implode(', ', $unknown).'). Update the app before restoring it.');
        }

        if (realpath($backupPath) === realpath($targetPath)) {
            throw new BackupException('The backup and the database are the same file.');
        }

        $this->releaseConnectionsTo($targetPath);

        $temporaryPath = $targetPath.'.restoring';

        if (! copy($backupPath, $temporaryPath)) {
            throw new BackupException("The backup could not be copied next to {$targetPath}. Nothing was changed.");
        }

        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            if (file_exists($targetPath.$suffix)) {
                unlink($targetPath.$suffix);
            }
        }

        if (! rename($temporaryPath, $targetPath)) {
            @unlink($temporaryPath);

            throw new BackupException("The database file {$targetPath} could not be replaced. Close anything using it (such as the app in a browser or a database viewer) and try again.");
        }

        $restored = SqliteFile::open($targetPath);
        $restored->ensureUsable();

        return [
            'tables' => $restored->tableCounts(),
            'missingMigrations' => array_values(array_diff($knownMigrations, $restored->migrations())),
        ];
    }

    /**
     * Close the app's connection when it uses the file being replaced.
     */
    private function releaseConnectionsTo(string $targetPath): void
    {
        foreach ((array) config('database.connections') as $name => $settings) {
            $database = is_array($settings) ? ($settings['database'] ?? null) : null;

            if (is_string($name) && is_string($database) && ($settings['driver'] ?? null) === 'sqlite'
                && $database !== ':memory:' && realpath($database) !== false && realpath($database) === realpath($targetPath)) {
                DB::purge($name);
            }
        }
    }
}
