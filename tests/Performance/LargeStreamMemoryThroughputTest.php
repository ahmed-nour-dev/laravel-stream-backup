<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Performance;

use Ahmednour\StreamBackup\Compression\GzipDriver;
use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use Ahmednour\StreamBackup\DTOs\UploadResult;
use Ahmednour\StreamBackup\Dumpers\DumperFactory;
use Ahmednour\StreamBackup\Encryption\EncryptionFactory;
use Ahmednour\StreamBackup\Pipelines\StreamPipeline;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use Ahmednour\StreamBackup\Support\EncryptionKeyResolver;
use Ahmednour\StreamBackup\Tests\Performance\Support\MeasuresProcessMemory;
use Ahmednour\StreamBackup\Tests\Performance\Support\PassthroughCompressionDriver;
use Ahmednour\StreamBackup\Tests\Performance\Support\SyntheticStreamDumper;
use Ahmednour\StreamBackup\Tests\TestCase;
use Ahmednour\StreamBackup\Uploaders\LocalDiskUploader;
use Carbon\CarbonImmutable;

/**
 * Memory and throughput regression suite for large streams.
 *
 * Constant-memory streaming is one of the package's primary architectural
 * guarantees (dump -> compress -> encrypt -> checksum -> multipart upload,
 * all chunk-by-chunk). Functional tests elsewhere prove the pipeline
 * produces correct output; none of them prove that peak memory stays flat
 * as input size grows — a regression in buffering could silently make the
 * pipeline O(n) in memory without breaking a single functional assertion.
 *
 * This suite drives the REAL StreamPipeline end-to-end (real proc_open
 * subprocesses for the "dump" and the compressor, real in-process
 * encryption/checksum stream decorators, real LocalDiskUploader chunking)
 * against a deterministic, quasi-random synthetic dump generated on the fly
 * by tests/Performance/Support/generate_synthetic_dump.php — so no
 * multi-gigabyte fixture is ever checked into the repository.
 *
 * It is intentionally kept out of the Unit/Feature testsuites (see
 * phpunit.xml's "Performance" testsuite and .github/workflows/performance.yml)
 * so it never slows down the fast day-to-day test loop.
 */
final class LargeStreamMemoryThroughputTest extends TestCase
{
    use MeasuresProcessMemory;

    /** Small synthetic dump: establishes a baseline memory footprint. */
    private const SMALL_TARGET_BYTES = 2 * 1024 * 1024;

    /** ~24x SMALL — large enough to make an O(n) memory regression obvious. */
    private const LARGE_TARGET_BYTES = 48 * 1024 * 1024;

    /**
     * Ceiling on the ADDITIONAL PHP process memory a single pipeline run may
     * add, regardless of how many bytes it streams. This is deliberately
     * NOT scaled to stream size (per the acceptance criteria: "the primary
     * assertion should be that peak memory remains bounded as input size
     * increases, rather than asserting an overly rigid absolute memory
     * number"): every scenario below, including the 48 MB run, must fit
     * under this SAME ceiling. If the pipeline ever regressed to buffering
     * whole streams, the 48 MB run alone would blow far past it.
     */
    private const PEAK_MEMORY_CEILING_BYTES = 24 * 1024 * 1024;

    /**
     * Best-effort ceiling on whole-process RSS growth (kB) for the largest
     * run. Looser than the PHP heap ceiling above because RSS also reflects
     * engine/opcache pages and pipe buffers that memory_get_peak_usage()
     * doesn't see — this is a coarse "did RSS blow up" tripwire, not a tight
     * bound. Skipped entirely on hosts without /proc/self/status.
     */
    private const RSS_GROWTH_CEILING_KB = 131072; // 128 MB

    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDir = sys_get_temp_dir() . '/stream-backup-perf-' . bin2hex(random_bytes(6));
        mkdir($this->outputDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->outputDir);

        parent::tearDown();
    }

    /**
     * Primary acceptance criterion: run the pipeline at two input sizes 24x
     * apart and prove the ADDITIONAL peak PHP memory each run needs stays
     * under the same fixed ceiling — i.e. memory does not grow with input
     * size. Also takes a best-effort process-RSS reading around the large
     * run ("process RSS where practical").
     */
    public function test_peak_memory_stays_bounded_as_stream_size_increases(): void
    {
        $baseline = $this->peakMemoryBytes();
        $rssBeforeKb = $this->currentRssKb();

        $small = $this->runPipeline(self::SMALL_TARGET_BYTES, compression: 'gzip', encryption: 'none');
        $afterSmall = $this->peakMemoryBytes();
        $deltaSmall = $afterSmall - $baseline;

        $large = $this->runPipeline(self::LARGE_TARGET_BYTES, compression: 'gzip', encryption: 'none');
        $afterLarge = $this->peakMemoryBytes();
        $deltaLarge = $afterLarge - $afterSmall;

        $rssAfterKb = $this->currentRssKb();

        // Sanity: the large run really did stream substantially more bytes
        // than the small one — otherwise a bounded-memory assertion would be
        // vacuous. Compressed output should scale with input at a roughly
        // consistent ratio; require at least 10x (vs. the 24x input ratio)
        // to leave headroom for compression-ratio noise on tiny inputs
        // without weakening the check that the fixture actually scaled.
        self::assertGreaterThan(
            $small->sizeBytes * 10,
            $large->sizeBytes,
            'Synthetic fixture did not scale with the configured target size; the bounded-memory '
            . 'assertion below would not be testing anything meaningful.',
        );

        self::assertLessThan(
            self::PEAK_MEMORY_CEILING_BYTES,
            $deltaSmall,
            sprintf(
                'Streaming a %.1f MB dump increased peak PHP memory by %s, past the %s ceiling.',
                self::SMALL_TARGET_BYTES / 1024 / 1024,
                $this->formatMb($deltaSmall),
                $this->formatMb(self::PEAK_MEMORY_CEILING_BYTES),
            ),
        );

        self::assertLessThan(
            self::PEAK_MEMORY_CEILING_BYTES,
            $deltaLarge,
            sprintf(
                'Streaming a %.1f MB dump (24x the %.1f MB run above) increased peak PHP memory by %s; '
                . 'expected it to stay under the SAME %s ceiling as the small run, proving memory use '
                . 'does not grow with input size.',
                self::LARGE_TARGET_BYTES / 1024 / 1024,
                self::SMALL_TARGET_BYTES / 1024 / 1024,
                $this->formatMb($deltaLarge),
                $this->formatMb(self::PEAK_MEMORY_CEILING_BYTES),
            ),
        );

        if ($rssBeforeKb !== null && $rssAfterKb !== null) {
            self::assertLessThan(
                self::RSS_GROWTH_CEILING_KB,
                $rssAfterKb - $rssBeforeKb,
                sprintf(
                    'Whole-process RSS grew by %d kB across both runs, past the %d kB tripwire.',
                    $rssAfterKb - $rssBeforeKb,
                    self::RSS_GROWTH_CEILING_KB,
                ),
            );
        } else {
            self::markTestIncomplete('Skipping RSS growth check: /proc/self/status is not available on this host.');
        }
    }

    /**
     * The encrypted path (compress -> encrypt -> checksum, all chunk-by-chunk
     * decorators per EncryptionDriver's contract) must stay just as bounded
     * as the unencrypted path.
     */
    public function test_peak_memory_stays_bounded_with_encryption_enabled(): void
    {
        $baseline = $this->peakMemoryBytes();

        $result = $this->runPipeline(self::LARGE_TARGET_BYTES, compression: 'gzip', encryption: 'openssl-aes-256-gcm');

        $delta = $this->peakMemoryBytes() - $baseline;

        self::assertGreaterThan(0, $result->sizeBytes);
        self::assertNotSame('', $result->checksum);
        self::assertLessThan(
            self::PEAK_MEMORY_CEILING_BYTES,
            $delta,
            sprintf(
                'Streaming a %.1f MB dump through AES-256-GCM encryption increased peak PHP memory by %s, '
                . 'past the %s ceiling.',
                self::LARGE_TARGET_BYTES / 1024 / 1024,
                $this->formatMb($delta),
                $this->formatMb(self::PEAK_MEMORY_CEILING_BYTES),
            ),
        );
    }

    /**
     * With compression swapped for an identity passthrough (`cat`), the
     * uploaded byte count should track the uncompressed input almost
     * exactly, AND peak memory must remain just as bounded — isolating the
     * encryption/upload stages' behavior on uncompressed bytes.
     */
    public function test_peak_memory_stays_bounded_with_compression_disabled(): void
    {
        $target = 16 * 1024 * 1024;

        $baseline = $this->peakMemoryBytes();

        $result = $this->runPipeline($target, compression: 'none', encryption: 'none');

        $delta = $this->peakMemoryBytes() - $baseline;

        // The synthetic generator only stops once it has written AT LEAST
        // $target bytes, so it may overshoot by up to one INSERT batch.
        self::assertGreaterThanOrEqual($target, $result->sizeBytes);
        self::assertLessThan($target + 100_000, $result->sizeBytes);

        self::assertLessThan(
            self::PEAK_MEMORY_CEILING_BYTES,
            $delta,
            sprintf(
                'Streaming a %.1f MB dump with compression disabled increased peak PHP memory by %s, '
                . 'past the %s ceiling.',
                $target / 1024 / 1024,
                $this->formatMb($delta),
                $this->formatMb(self::PEAK_MEMORY_CEILING_BYTES),
            ),
        );
    }

    /**
     * Records total bytes streamed, throughput, and part count for a run
     * forced (via a small multipart.part_size override) to split across
     * several parts — proving chunking behaves correctly at scale without
     * needing a dump large enough to hit the real 32 MB default threshold.
     *
     * The throughput floor is intentionally generous (a few hundred KB/s)
     * so the assertion catches a catastrophic regression (e.g. an
     * accidental O(n^2) pass) without flaking on slower/shared CI runners.
     */
    public function test_throughput_and_part_count_are_recorded(): void
    {
        $target = 20 * 1024 * 1024;

        $result = $this->runPipeline($target, compression: 'gzip', encryption: 'none', partSizeBytes: 1024 * 1024);

        self::assertGreaterThan(0, $result->sizeBytes, 'Total bytes streamed must be recorded.');
        self::assertGreaterThan(
            1,
            $result->partCount,
            'With a 1 MB part size, a multi-MB compressed stream must be split into more than one part.',
        );
        self::assertGreaterThan(0.0, $result->durationSeconds, 'Duration must be recorded.');
        self::assertLessThan(
            30.0,
            $result->durationSeconds,
            'Pipeline run took far longer than expected for a 20 MB synthetic stream.',
        );

        $throughputBytesPerSecond = $target / max($result->durationSeconds, 0.001);

        self::assertGreaterThan(
            256 * 1024,
            $throughputBytesPerSecond,
            sprintf(
                'Throughput of %.1f KB/s is far below the flake-tolerant floor; likely a catastrophic '
                . 'performance regression rather than normal CI-runner variance.',
                $throughputBytesPerSecond / 1024,
            ),
        );
    }

    private function runPipeline(
        int $targetBytes,
        string $compression,
        string $encryption,
        ?int $partSizeBytes = null,
    ): UploadResult {
        $driverKey = 'synthetic-perf-' . bin2hex(random_bytes(4));

        $config = $this->app->make('config');

        $factory = $this->app->make(DumperFactory::class);
        $factory->extend(
            $driverKey,
            fn () => new SyntheticStreamDumper(new BinaryLocator(), $config, $targetBytes),
        );

        $compressionDriver = match ($compression) {
            'gzip' => new GzipDriver(new BinaryLocator(), 4),
            'none' => new PassthroughCompressionDriver(),
            default => throw new \InvalidArgumentException("Unknown compression scenario '{$compression}'."),
        };

        $config->set('stream-backup.encryption.driver', $encryption);
        if ($encryption === 'openssl-aes-256-gcm') {
            $config->set('stream-backup.encryption.key', base64_encode(random_bytes(32)));
        }

        $config->set('stream-backup.multipart.part_size', $partSizeBytes ?? (32 * 1024 * 1024));

        $pipeline = new StreamPipeline(
            dumperFactory: $factory,
            compression: $compressionDriver,
            encryptionFactory: $this->app->make(EncryptionFactory::class),
            keyResolver: $this->app->make(EncryptionKeyResolver::class),
            uploader: new LocalDiskUploader($this->outputDir),
            config: $config,
        );

        $context = new BackupContext(
            tenantId: null,
            databaseName: 'perf',
            connectionName: 'testing',
            disk: 'local',
            driver: $driverKey,
        );

        $metadata = new BackupMetadata(
            backupId: random_int(1, PHP_INT_MAX),
            tenantId: null,
            bucket: '',
            path: 'perf-' . bin2hex(random_bytes(4)) . '.bin',
            disk: 'local',
            startedAt: CarbonImmutable::now(),
        );

        return $pipeline->run($context, $metadata);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
