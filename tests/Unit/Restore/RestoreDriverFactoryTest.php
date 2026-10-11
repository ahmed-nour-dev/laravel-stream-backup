<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Restore;

use Ahmednour\StreamBackup\Contracts\RestoreDriver;
use Ahmednour\StreamBackup\Exceptions\RestoreFailedException;
use Ahmednour\StreamBackup\Restore\MySqlRestoreDriver;
use Ahmednour\StreamBackup\Restore\RestoreDriverFactory;
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

    public function test_unsupported_driver_throws(): void
    {
        $factory = $this->app->make(RestoreDriverFactory::class);

        $this->assertFalse($factory->supports('sqlite3'));

        $this->expectException(RestoreFailedException::class);
        $factory->make('sqlite3');
    }

    public function test_extend_registers_a_custom_driver(): void
    {
        $custom  = $this->createMock(RestoreDriver::class);
        $factory = $this->app->make(RestoreDriverFactory::class);

        $factory->extend('sqlite3', fn () => $custom);

        $this->assertTrue($factory->supports('sqlite3'));
        $this->assertSame($custom, $factory->make('sqlite3'));
    }

    public function test_factory_is_a_singleton_so_extensions_persist(): void
    {
        $this->assertSame(
            $this->app->make(RestoreDriverFactory::class),
            $this->app->make(RestoreDriverFactory::class),
        );
    }
}
