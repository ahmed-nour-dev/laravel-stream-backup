<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Contracts;

/**
 * Streaming parser for one dump format.
 *
 * Fed decompressed dump text chunk by chunk, it routes each table's
 * statements into a bounded per-table stream.
 */
interface DumpParser
{
    /**
     * Feed the next chunk of decompressed dump text.
     */
    public function feed(string $chunk): void;

    /**
     * Finish parsing and return the table blocks, keyed by table name.
     *
     * @return array<string, resource>
     */
    public function finish(): array;

    /**
     * Foreign-key edges declared in the dump, as [parent, child] pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function getForeignKeys(): array;
}
