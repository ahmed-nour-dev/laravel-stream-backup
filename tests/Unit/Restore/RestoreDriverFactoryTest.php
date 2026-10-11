<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Restore;

use Ahmednour\StreamBackup\Contracts\RestoreDriver;
use Ahmednour\StreamBackup\Exceptions\RestoreFailedException;
use Ahmednour\StreamBackup\Restore\MySqlRestoreDriver;
use Ahmednour\StreamBackup\Restore\PgSqlDumpParser;
use Ahmednour\StreamBackup\Restore\PgSqlRestoreDriver;
use Ahmednour\StreamBackup\Restore\PgSqlRestorer;
use Ahmednour\StreamBackup\Restore\RestoreDriverFactory;
use Ahmednour\StreamBackup\Restore\SqliteDumpParser;
use Ahmednour\StreamBackup\Restore\SqliteRestoreDriver;
use Ahmednour\StreamBackup\Restore\SqliteRestorer;
use Ahmednour\StreamBackup\Restore\SqlDumpParser;
use Ahmednour\StreamBackup\Restore\TableRestorer;
use Ahmednour\StreamBackup\Tests\TestCase;

class RestoreDriverFactoryTest extends TestCase
{
    public function test_resolves_mysql_driver(): void
    {
        $driver = $this->app->make(RestoreDriverFactory::class)->make('mysqldump');

        $this->assertInstanceOf(MySqlRestoreDriver::class, $driver);
        $this->assertSame('mysqldump', $driver->name());
        $this->assertSame(['mysql', 'mariadb'], $driver->connectionDrivers());
        $this->assertInstanceOf(SqlDumpParser::class, $driver->makeParser([]));
        $this->assertInstanceOf(TableRestorer::class, $driver->restorer());
    }

    public function test_resolves_pg_dump_driver(): void
    {
        $driver = $this->app->make(RestoreDriverFactory::class)->make('pg_dump');

        $this->assertInstanceOf(PgSqlRestoreDriver::class, $driver);
        $this->assertSame('pg_dump', $driver->name());
        $this->assertSame(['pgsql'], $driver->connectionDrivers());
        $this->assertInstanceOf(PgSqlDumpParser::class, $driver->makeParser([]));
        $this->assertInstanceOf(PgSqlRestorer::class, $driver->restorer());
    }

    public function test_resolves_sqlite3_driver(): void
    {
        $driver = $this->app->make(RestoreDriverFactory::class)->make('sqlite3');

        $this->assertInstanceOf(SqliteRestoreDriver::class, $driver);
        $this->assertSame('sqlite3', $driver->name());
        $this->assertSame(['sqlite'], $driver->connectionDrivers());
        $this->assertInstanceOf(SqliteDumpParser::class, $driver->makeParser([]));
        $this->assertInstanceOf(SqliteRestorer::class, $driver->restorer());
    }

    public function test_unsupported_driver_throws(): void
    {
        $factory = $this->app->make(RestoreDriverFactory::class);

        $this->assertFalse($factory->supports('oracle'));

        $this->expectException(RestoreFailedException::class);
        $factory->make('oracle');
    }

    public function test_extend_registers_a_custom_driver(): void
    {
        $custom  = $this->createMock(RestoreDriver::class);
        $factory = $this->app->make(RestoreDriverFactory::class);

        $factory->extend('oracle', fn () => $custom);

        $this->assertTrue($factory->supports('oracle'));
        $this->assertSame($custom, $factory->make('oracle'));
    }

    public function test_factory_is_a_singleton_so_extensions_persist(): void
    {
        $this->assertSame(
            $this->app->make(RestoreDriverFactory::class),
            $this->app->make(RestoreDriverFactory::class),
        );
    }
}
