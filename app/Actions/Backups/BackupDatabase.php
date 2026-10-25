<?php

namespace App\Actions\Backups;

use App\Exceptions\BackupException;
use App\Support\Backups\SqliteFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Copies the SQLite database into a single backup file with SQLite's
 * VACUUM INTO, which gives a consistent copy even while the app is in use.
 * The copy is then checked: it must pass SQLite's integrity check and hold
 * the same number of rows in every table as the database.
 */
class BackupDatabase
{
    /**
     * Where backups are kept: private storage, which Git ignores and the
     * web server never serves.
     */
    public static function directory(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'backups');
    }

    /**
     * @return array{path: string, bytes: int, sha256: string, tables: array<string, int>}
     *
     * @throws BackupException
     */
    public function handle(string $label = 'protracker', ?string $directory = null): array
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            throw new BackupException('Backups are built for the SQLite database this app uses. The configured database is '.$connection->getDriverName().'.');
        }

        $directory ??= self::directory();

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new BackupException("The backup folder {$directory} could not be created.");
        }

        $path = $this->availablePath($directory, $label.'-'.CarbonImmutable::now()->format('Y-m-d-His'));

        $connection->statement('vacuum into '.$connection->getPdo()->quote($path));

        try {
            $backup = SqliteFile::open($path);
            $backup->ensureUsable();
            $differences = $this->differences($this->liveCounts(), $backup->tableCounts());

            if ($differences !== []) {
                throw new BackupException('The backup does not hold the same records as the database ('.implode('; ', $differences).'). Try again when nothing is being saved.');
            }
        } catch (BackupException $exception) {
            @unlink($path);

            throw $exception;
        }

        return [
            'path' => $path,
            'bytes' => (int) filesize($path),
            'sha256' => (string) hash_file('sha256', $path),
            'tables' => $backup->tableCounts(),
        ];
    }

    /**
     * Row counts of the live database's tables, volatile ones left out.
     *
     * @return array<string, int>
     */
    private function liveCounts(): array
    {
        $tables = array_column(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"), 'name');
        $counts = [];

        foreach ($tables as $table) {
            if (! in_array($table, SqliteFile::VOLATILE_TABLES, true)) {
                $counts[(string) $table] = DB::table((string) $table)->count();
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<string, int>  $expected
     * @param  array<string, int>  $actual
     * @return list<string>
     */
    private function differences(array $expected, array $actual): array
    {
        $differences = [];

        foreach ($expected as $table => $count) {
            if (($actual[$table] ?? null) !== $count) {
                $differences[] = "{$table}: {$count} in the database, ".($actual[$table] ?? 'none').' in the backup';
            }
        }

        return $differences;
    }

    private function availablePath(string $directory, string $name): string
    {
        $path = $directory.DIRECTORY_SEPARATOR.$name.'.sqlite';

        for ($suffix = 2; file_exists($path); $suffix++) {
            $path = $directory.DIRECTORY_SEPARATOR."{$name}-{$suffix}.sqlite";
        }

        return $path;
    }
}
