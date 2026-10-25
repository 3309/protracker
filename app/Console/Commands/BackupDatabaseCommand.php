<?php

namespace App\Console\Commands;

use App\Actions\Backups\BackupDatabase;
use App\Exceptions\BackupException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:backup')]
#[Description('Back up the database to storage/app/private/backups and check the copy')]
class BackupDatabaseCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BackupDatabase $backupDatabase): int
    {
        try {
            $backup = $backupDatabase->handle();
        } catch (BackupException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup saved and checked: it passes SQLite\'s integrity check and holds the same records as the database.');
        $this->line("File: {$backup['path']}");
        $this->line('Size: '.number_format($backup['bytes'] / 1024, 1).' KB');
        $this->line("SHA-256: {$backup['sha256']}");
        $this->line('Records: '.implode(', ', array_map(
            fn (string $table, int $count) => "{$table} {$count}",
            array_keys($backup['tables']),
            $backup['tables'],
        )));
        $this->newLine();
        $this->warn('The backup is not encrypted. Keep copies on an encrypted drive or private storage; never in Git, email or shared folders.');

        return self::SUCCESS;
    }
}
