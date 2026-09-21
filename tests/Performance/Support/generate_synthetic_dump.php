#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Streams a deterministic, quasi-random mysqldump-shaped SQL fixture to
 * stdout until at least the requested number of bytes has been produced.
 *
 * Run as its own child process by SyntheticStreamDumper (via proc_open,
 * exactly like a real mysqldump invocation) so that:
 *
 *  - generating a large fixture never touches the memory of the PHPUnit
 *    process the performance tests measure;
 *  - no multi-gigabyte fixture file needs to be checked into the repo —
 *    the data is produced on the fly, one small INSERT batch at a time,
 *    at O(1) memory cost in this process too;
 *  - the same (targetBytes, seed) pair always produces byte-identical
 *    output, so test runs are reproducible.
 *
 * Usage: php generate_synthetic_dump.php <targetBytes> <seed> [<table>]
 */

$targetBytes = isset($argv[1]) ? (int) $argv[1] : 8 * 1024 * 1024;
$seed        = isset($argv[2]) ? (int) $argv[2] : 42;
$table       = $argv[3] ?? 'perf_bigtable';

$out = fopen('php://stdout', 'wb');

fwrite($out, "-- Table structure for table `{$table}`\n");
fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n");
fwrite($out, "CREATE TABLE `{$table}` (`id` INT UNSIGNED NOT NULL, `payload` VARCHAR(64) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB;\n");
fwrite($out, "-- Dumping data for table `{$table}`\n");

$batchSize = 500;
$written   = 0;
$id        = 1;

while ($written < $targetBytes) {
    $tuples = [];

    for ($i = 0; $i < $batchSize; $i++) {
        // Quasi-random, high-entropy hex payload derived from the row id so
        // the fixture doesn't compress to an unrealistic, trivial ratio —
        // mirrors tests/Support/BuildsRestoreFixtures::generateRowTuples().
        $payload = substr(
            bin2hex(md5($seed . ':' . $id, true)) . bin2hex(md5($seed . ':' . ($id * 7919), true)),
            0,
            40,
        );
        $tuples[] = "({$id},'{$payload}')";
        $id++;
    }

    $line = 'INSERT INTO `' . $table . '` VALUES ' . implode(',', $tuples) . ";\n";
    fwrite($out, $line);
    $written += strlen($line);
}

fclose($out);
