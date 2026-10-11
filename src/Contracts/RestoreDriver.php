<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Contracts;

/**
 * Pairs a dump parser with a restorer for one database engine.
 *
 * Resolved from `backups.dump_driver` by RestoreDriverFactory.
 */
interface RestoreDriver
{
    /**
     * Dump driver name this restore driver handles (e.g. 'mysql').
     */
    public function name(): string;

    /**
     * The Laravel connection driver names this restore driver can target
     * (e.g. ['mysql', 'mariadb']).
     *
     * @return list<string>
     */
    public function connectionDrivers(): array;

    /**
     * @param list<string> $tables Tables to keep; empty means all.
     */
    public function makeParser(array $tables): DumpParser;

    public function restorer(): DumpRestorer;
}
