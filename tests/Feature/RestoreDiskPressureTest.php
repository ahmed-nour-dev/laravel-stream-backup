<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Feature;

use Ahmednour\StreamBackup\Tests\TestCase;

/**
 * Simulates disk pressure while the streaming restore parser's bounded
 * per-table temp buffers are in use — the acceptance scenario "Disk pressure
 * while bounded temporary buffers are in use" from the large-scale restore
 * integration test issue.
 *
 * Faking a genuinely full disk isn't practical (or safe) to do against a
 * shared machine's real filesystem, and there's no portable, non-root way to
 * mount a small tmpfs from a test. The available, deterministic, root-free
 * proxy is a POSIX file-size rlimit (`ulimit -f`): SqlDumpParser's per-table
 * `php://temp/maxmemory:2MB` buffer transparently spills to a real temp file
 * once a table's dump text exceeds 2 MB (see SqlDumpParserBoundedMemoryTest),
 * and once that file grows past the process's RLIMIT_FSIZE, the kernel sends
 * SIGXFSZ exactly as it would on a filesystem that has actually run out of
 * space on write() — the OS-level failure mode is the same either way.
 *
 * This runs the real SqlDumpParser in an isolated child process (a crash
 * must not take the test runner down with it) and asserts the *shape* of the
 * failure: the parser must not silently swallow the write failure and
 * report a truncated table as complete — it must fail loudly enough that a
 * caller (or the OS) stops the restore, never producing "DONE" output.
 *
 * Environment requirements (documented per the issue's acceptance criteria):
 *   - Linux or macOS (RLIMIT_FSIZE / `ulimit -f`); skipped elsewhere.
 *   - `bash` and `php` on PATH, invoked via proc_open.
 */
final class RestoreDiskPressureTest extends TestCase
{
    public function test_exceeding_the_disk_quota_mid_spill_fails_loudly_instead_of_silently_truncating(): void
    {
        $this->skipUnlessPlatformSupportsFileSizeLimits();

        [$exitCode, $stdout, $stderr] = $this->runProbe(ulimitBlocks: 6000); // ~3 MB cap

        self::assertNotSame(0, $exitCode, 'Expected the child process to be killed once the spilled temp file exceeded the file-size limit.');
        self::assertStringNotContainsString('DONE', $stdout, 'The parser must not report completion on a write it could not actually make.');
        self::assertStringNotContainsString(
            'COMPLETED_WITHOUT_CRASH',
            $stderr,
            'The parser must not silently finish once the underlying temp-file write starts failing.',
        );
    }

    /**
     * Control case proving the crash above is caused by the imposed limit,
     * not some unrelated flakiness in the probe script itself.
     */
    public function test_the_same_workload_completes_cleanly_without_a_file_size_limit(): void
    {
        $this->skipUnlessPlatformSupportsFileSizeLimits();

        [$exitCode, $stdout, $stderr] = $this->runProbe(ulimitBlocks: null);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('DONE', $stdout);
        self::assertStringContainsString('COMPLETED_WITHOUT_CRASH', $stderr);
    }

    /**
     * @return array{0: int, 1: string, 2: string} [exitCode, stdout, stderr]
     */
    private function runProbe(?int $ulimitBlocks): array
    {
        $script = $this->writeProbeScript();
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        $ulimitCmd = $ulimitBlocks === null ? 'ulimit -f unlimited' : "ulimit -f {$ulimitBlocks}";
        $command   = sprintf(
            '%s; exec php %s %s',
            $ulimitCmd,
            escapeshellarg($script),
            escapeshellarg($autoload),
        );

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(['bash', '-c', $command], $descriptors, $pipes);

        self::assertIsResource($proc, 'Failed to spawn the disk-pressure probe subprocess.');

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);

        @unlink($script);

        return [$exitCode, $stdout, $stderr];
    }

    /**
     * Writes a standalone PHP script that feeds a ~13 MB single-table
     * mysqldump-shaped dump through the REAL SqlDumpParser (not a
     * reimplementation), matching the 64 KB chunk size RestorePipeline uses
     * from its decompressor pipe. Bootstraps just enough of a Laravel
     * container for the Log facade calls inside SqlDumpParser to resolve
     * without pulling in the full framework.
     */
    private function writeProbeScript(): string
    {
        $stub = tempnam(sys_get_temp_dir(), 'sbr_disk_pressure_');
        $path = $stub . '.php';
        @unlink($stub); // tempnam() itself creates $stub; only $path is used.

        file_put_contents($path, <<<'PHP'
<?php
require $argv[1];

use Ahmednour\StreamBackup\Restore\SqlDumpParser;

$container = new \Illuminate\Container\Container();
\Illuminate\Container\Container::setInstance($container);
\Illuminate\Support\Facades\Facade::setFacadeApplication($container);
$container->singleton('log', function () {
    return new class {
        public function __call($name, $args) { return null; }
    };
});

function sbr_probe_row_tuples(int $count): array {
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $payload = substr(bin2hex(md5((string) $i, true)), 0, 40);
        $rows[] = "({$i},'{$payload}')";
    }
    return $rows;
}

$rows = sbr_probe_row_tuples(300000);
$sql = "-- Table structure for table `sbr_pressure`\n"
     . "DROP TABLE IF EXISTS `sbr_pressure`;\n"
     . "CREATE TABLE `sbr_pressure` (`id` INT, `payload` VARCHAR(64));\n"
     . "-- Dumping data for table `sbr_pressure`\n";
foreach (array_chunk($rows, 500) as $batch) {
    $sql .= "INSERT INTO `sbr_pressure` VALUES " . implode(',', $batch) . ";\n";
}

$parser = new SqlDumpParser(['sbr_pressure']);
foreach (str_split($sql, 65536) as $chunk) {
    $parser->feed($chunk);
}
$parser->finish();

fwrite(STDERR, "COMPLETED_WITHOUT_CRASH\n");
echo "DONE\n";
PHP
        );

        return $path;
    }

    private function skipUnlessPlatformSupportsFileSizeLimits(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('RLIMIT_FSIZE (`ulimit -f`) is a POSIX facility; not applicable on Windows.');
        }

        if (trim((string) @shell_exec('command -v bash 2>/dev/null')) === '') {
            self::markTestSkipped('bash is required to apply ulimit around the probe process.');
        }
    }
}
