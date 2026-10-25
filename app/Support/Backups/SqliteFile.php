<?php

namespace App\Support\Backups;

use App\Exceptions\BackupException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Reads a SQLite database file without changing it: whether it passes
 * SQLite's integrity check, how many rows each table holds, and which
 * migrations it has run.
 */
final class SqliteFile
{
    /**
     * Tables whose contents change on their own (logins, caches, queues), so
     * they are not compared between a database and its backup.
     */
    public const array VOLATILE_TABLES = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    private function __construct(private PDO $pdo) {}

    /**
     * @throws BackupException
     */
    public static function open(string $path): self
    {
        if (! is_file($path)) {
            throw new BackupException("No file was found at {$path}.");
        }

        try {
            $pdo = new PDO('sqlite:'.$path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);
            $pdo->query('select count(*) from sqlite_master');
        } catch (PDOException) {
            throw new BackupException("{$path} is not a SQLite database.");
        }

        return new self($pdo);
    }

    /**
     * SQLite's own check of every page and index: "ok" when the file is sound.
     */
    public function integrity(): string
    {
        return (string) $this->query('pragma integrity_check')->fetchColumn();
    }

    /**
     * Row counts of every table except SQLite's own and the volatile ones.
     *
     * @return array<string, int>
     */
    public function tableCounts(): array
    {
        $tables = $this->query("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name")->fetchAll(PDO::FETCH_COLUMN);
        $counts = [];

        foreach ($tables as $table) {
            if (is_string($table) && ! in_array($table, self::VOLATILE_TABLES, true)) {
                $counts[$table] = (int) $this->query('select count(*) from "'.str_replace('"', '""', $table).'"')->fetchColumn();
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    public function migrations(): array
    {
        try {
            return array_values(array_filter($this->query('select migration from migrations order by id')->fetchAll(PDO::FETCH_COLUMN), is_string(...)));
        } catch (PDOException) {
            return [];
        }
    }

    /**
     * Run a query; the connection reports errors as exceptions.
     *
     * @throws PDOException
     */
    private function query(string $sql): PDOStatement
    {
        $statement = $this->pdo->query($sql);

        if ($statement === false) {
            throw new PDOException("The query could not be run: {$sql}");
        }

        return $statement;
    }

    /**
     * Check that the file is a sound Protracker database.
     *
     * @throws BackupException
     */
    public function ensureUsable(): void
    {
        $integrity = $this->integrity();

        if ($integrity !== 'ok') {
            throw new BackupException("The file failed SQLite's integrity check ({$integrity}). It may be damaged; do not restore it.");
        }

        if (! array_key_exists('accounts', $this->tableCounts()) || $this->migrations() === []) {
            throw new BackupException('The file is a SQLite database but not a Protracker database.');
        }
    }
}
