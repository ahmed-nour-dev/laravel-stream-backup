<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Commands;

use Ahmednour\StreamBackup\BackupManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

class RestoreBackupCommand extends Command
{
    protected $signature = 'backup:restore {backup : The ID of the backup to restore}
                            {--tables= : Comma-separated list of tables to restore (omit for full restore)}
                            {--connection= : The database connection to restore into (default: the backup\'s original connection)}
                            {--force : Force the operation to run when in production}';

    protected $description = 'Restore a MySQL backup (full or specific tables) via a non-blocking stream. '
        . 'PostgreSQL and SQLite backups are not currently supported for restore.';

    public function handle(BackupManager $manager): int
    {
        if (App::environment('production') && ! $this->option('force')) {
            $this->error('Application is in production. Use --force to confirm this destructive operation.');
            return self::FAILURE;
        }

        $backupId = (int) $this->argument('backup');

        $tablesOption = $this->option('tables');
        $tables = [];

        if (is_string($tablesOption) && $tablesOption !== '') {
            $tables = array_filter(array_map('trim', explode(',', $tablesOption)));
        }

        /** @var string|null $connectionOption */
        $connectionOption = $this->option('connection');

        $connection = is_string($connectionOption) && $connectionOption !== '' ? $connectionOption : null;

        $manager->restore($backupId, $tables, $connection);

        $this->info("Restore job dispatched for backup ID {$backupId}.");

        if ($tables !== []) {
            $this->line('Requested tables: ' . implode(', ', $tables));
        } else {
            $this->line('Requested tables: [FULL RESTORE]');
        }

        if ($connection !== null) {
            $this->line("Target connection overridden to: {$connection}");
        }

        return self::SUCCESS;
    }
}
