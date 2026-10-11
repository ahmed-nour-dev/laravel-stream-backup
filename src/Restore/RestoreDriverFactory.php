<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

use Ahmednour\StreamBackup\Contracts\RestoreDriver;
use Ahmednour\StreamBackup\Exceptions\RestoreFailedException;
use Closure;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves the RestoreDriver for a backup's `dump_driver`.
 *
 * Mirrors DumperFactory: third-party packages call extend() in their
 * ServiceProvider::boot() to register restore support for other engines.
 */
final class RestoreDriverFactory
{
    /** @var array<string, Closure(Application): RestoreDriver> */
    private array $customDrivers = [];

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * @param Closure(Application): RestoreDriver $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->customDrivers[$name] = $factory;
    }

    public function supports(string $dumpDriver): bool
    {
        return isset($this->customDrivers[$dumpDriver]) || in_array($dumpDriver, ['mysqldump', 'pg_dump', 'sqlite3'], true);
    }

    /**
     * @throws RestoreFailedException If no restore driver handles $dumpDriver.
     */
    public function make(string $dumpDriver): RestoreDriver
    {
        if (isset($this->customDrivers[$dumpDriver])) {
            return ($this->customDrivers[$dumpDriver])($this->app);
        }

        return match ($dumpDriver) {
            'mysqldump' => $this->app->make(MySqlRestoreDriver::class),
            'pg_dump'   => $this->app->make(PgSqlRestoreDriver::class),
            'sqlite3'   => $this->app->make(SqliteRestoreDriver::class),
            default => throw new RestoreFailedException(
                "Restore is not supported for '{$dumpDriver}' backups."
            ),
        };
    }
}
