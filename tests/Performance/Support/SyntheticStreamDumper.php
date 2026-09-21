<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Performance\Support;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Dumpers\AbstractProcessDumper;

/**
 * Fake DatabaseDumper for the large-stream performance suite.
 *
 * Instead of shelling out to a real mysqldump against a real database, it
 * spawns generate_synthetic_dump.php as a child process, which streams a
 * deterministic amount of quasi-random SQL text to stdout.
 *
 * It still goes through the exact same proc_open + non-blocking pipe
 * machinery a real dumper would (AbstractProcessDumper / ProcessBackupStream),
 * so StreamPipeline's real stream_select loop is exercised end-to-end
 * without needing mysqldump, a live database, or a checked-in fixture.
 */
final class SyntheticStreamDumper extends AbstractProcessDumper
{
    public function __construct(
        \Ahmednour\StreamBackup\Support\BinaryLocator $locator,
        \Illuminate\Contracts\Config\Repository $config,
        private readonly int $targetBytes,
        private readonly int $seed = 42,
    ) {
        parent::__construct($locator, $config);
    }

    protected function buildCommand(BackupContext $context): array
    {
        return [
            PHP_BINARY,
            self::scriptPath(),
            (string) $this->targetBytes,
            (string) $this->seed,
        ];
    }

    public function name(): string
    {
        return 'synthetic-perf-dump';
    }

    public static function scriptPath(): string
    {
        return __DIR__ . '/generate_synthetic_dump.php';
    }
}
