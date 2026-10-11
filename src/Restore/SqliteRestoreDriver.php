<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

use Ahmednour\StreamBackup\Contracts\DumpParser;
use Ahmednour\StreamBackup\Contracts\DumpRestorer;
use Ahmednour\StreamBackup\Contracts\RestoreDriver;

/**
 * Restore driver for `sqlite3 .dump` backups.
 */
final class SqliteRestoreDriver implements RestoreDriver
{
    public function __construct(private readonly SqliteRestorer $restorer)
    {
    }

    public function name(): string
    {
        return 'sqlite3';
    }

    public function connectionDrivers(): array
    {
        return ['sqlite'];
    }

    public function makeParser(array $tables): DumpParser
    {
        return new SqliteDumpParser($tables);
    }

    public function restorer(): DumpRestorer
    {
        return $this->restorer;
    }
}
