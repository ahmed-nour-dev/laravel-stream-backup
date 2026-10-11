<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

use Ahmednour\StreamBackup\Contracts\DumpParser;
use Ahmednour\StreamBackup\Contracts\DumpRestorer;
use Ahmednour\StreamBackup\Contracts\RestoreDriver;

/**
 * Restore driver for mysqldump backups.
 */
final class MySqlRestoreDriver implements RestoreDriver
{
    public function __construct(private readonly TableRestorer $restorer)
    {
    }

    public function name(): string
    {
        return 'mysqldump';
    }

    public function connectionDrivers(): array
    {
        return ['mysql', 'mariadb'];
    }

    public function makeParser(array $tables): DumpParser
    {
        return new SqlDumpParser($tables);
    }

    public function restorer(): DumpRestorer
    {
        return $this->restorer;
    }
}
