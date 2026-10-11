<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

use Ahmednour\StreamBackup\Contracts\DumpParser;
use Ahmednour\StreamBackup\Contracts\DumpRestorer;
use Ahmednour\StreamBackup\Contracts\RestoreDriver;

/**
 * Restore driver for plain-format pg_dump backups.
 */
final class PgSqlRestoreDriver implements RestoreDriver
{
    public function __construct(private readonly PgSqlRestorer $restorer)
    {
    }

    public function name(): string
    {
        return 'pg_dump';
    }

    public function connectionDrivers(): array
    {
        return ['pgsql'];
    }

    public function makeParser(array $tables): DumpParser
    {
        return new PgSqlDumpParser($tables);
    }

    public function restorer(): DumpRestorer
    {
        return $this->restorer;
    }
}
