<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Contracts;

use Ahmednour\StreamBackup\DTOs\RestoreResult;
use Ahmednour\StreamBackup\Exceptions\RestoreFailedException;

/**
 * Replays parsed table blocks into a live database connection.
 */
interface DumpRestorer
{
    /**
     * @param  array<string, resource>          $tableBlocks
     * @param  list<array{0: string, 1: string}> $dumpFkEdges  [parent, child] edges from the dump
     * @param  bool                             $selective    true when only some tables are restored
     *
     * @throws RestoreFailedException
     */
    public function restore(array $tableBlocks, string $connection, float $startTime, array $dumpFkEdges = [], bool $selective = false): RestoreResult;
}
