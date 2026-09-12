<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Pipelines;

use Ahmednour\StreamBackup\Compression\GzipDriver;
use Ahmednour\StreamBackup\Contracts\UploadDriver;
use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use Ahmednour\StreamBackup\DTOs\UploadResult;
use Ahmednour\StreamBackup\Dumpers\AbstractProcessDumper;
use Ahmednour\StreamBackup\Dumpers\DumperFactory;
use Ahmednour\StreamBackup\Encryption\EncryptionFactory;
use Ahmednour\StreamBackup\Exceptions\IdleTimeoutExceededException;
use Ahmednour\StreamBackup\Exceptions\MaxRuntimeExceededException;
use Ahmednour\StreamBackup\Pipelines\StreamPipeline;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use Ahmednour\StreamBackup\Support\EncryptionKeyResolver;
use Ahmednour\StreamBackup\Support\TimeoutGuard;
use Ahmednour\StreamBackup\Tests\TestCase;
use Ahmednour\StreamBackup\Uploaders\Sessions\WriteSession;
use Carbon\CarbonImmutable;

/**
 * Covers the acceptance criteria "idle timeout detects a stalled pipeline
 * even when the job itself is still alive" and "maximum runtime is
 * configurable independently of Laravel's queue timeout", at the level
 * StreamPipeline itself enforces them.
 *
 * Both fixtures below run for far longer than the configured budgets if the
 * safeguard fails to trip, so a regression manifests as the test hanging /
 * timing out rather than silently passing.
 */
final class StreamPipelineTimeoutTest extends TestCase
{
    public function test_idle_timeout_aborts_a_pipeline_whose_dump_stalls_after_first_byte(): void
    {
        $uploader = new RecordingUploadDriverForTimeoutTest();
        $pipeline = $this->makePipeline($uploader, 'fake-stall-after-first-byte');

        $context = $this->makeContext('fake-stall-after-first-byte');
        $metadata = $this->makeMetadata();

        // idle_timeout = 1s, max_runtime disabled: only a stall should trip it.
        $timeoutGuard = new TimeoutGuard(maxRuntimeSeconds: 0, idleTimeoutSeconds: 1);

        $startedAt = microtime(true);

        try {
            $pipeline->run($context, $metadata, timeoutGuard: $timeoutGuard);
            self::fail('Expected IdleTimeoutExceededException was not thrown.');
        } catch (IdleTimeoutExceededException) {
            // expected
        }

        $elapsed = microtime(true) - $startedAt;

        self::assertLessThan(
            10.0,
            $elapsed,
            'Idle timeout should trip within a couple of seconds, not run the fixture to completion (30s).',
        );
        self::assertTrue($uploader->aborted, 'Multipart upload should be aborted on idle timeout.');
        self::assertFalse($uploader->completed, 'complete() must never be called once timed out.');
    }

    public function test_max_runtime_aborts_a_pipeline_that_keeps_making_progress_but_runs_too_long(): void
    {
        $uploader = new RecordingUploadDriverForTimeoutTest();
        $pipeline = $this->makePipeline($uploader, 'fake-never-ending-stream');

        $context = $this->makeContext('fake-never-ending-stream');
        $metadata = $this->makeMetadata();

        // max_runtime = 1s, idle_timeout disabled: continuous small chunks
        // never let idle trip, so only the hard runtime ceiling should fire.
        $timeoutGuard = new TimeoutGuard(maxRuntimeSeconds: 1, idleTimeoutSeconds: 0);

        $startedAt = microtime(true);

        try {
            $pipeline->run($context, $metadata, timeoutGuard: $timeoutGuard);
            self::fail('Expected MaxRuntimeExceededException was not thrown.');
        } catch (MaxRuntimeExceededException) {
            // expected
        }

        $elapsed = microtime(true) - $startedAt;

        self::assertLessThan(
            10.0,
            $elapsed,
            'Max runtime should trip within a couple of seconds of the 1s budget, not run indefinitely.',
        );
        self::assertTrue($uploader->aborted, 'Multipart upload should be aborted on max-runtime timeout.');
        self::assertFalse($uploader->completed, 'complete() must never be called once timed out.');
    }

    private function makePipeline(UploadDriver $uploader, string $driver): StreamPipeline
    {
        $factory = $this->app->make(DumperFactory::class);
        $factory->extend($driver, fn () => match ($driver) {
            'fake-stall-after-first-byte' => new StallAfterFirstByteFakeDumper(new BinaryLocator(), $this->app->make('config')),
            'fake-never-ending-stream'    => new NeverEndingFakeDumper(new BinaryLocator(), $this->app->make('config')),
        });

        return new StreamPipeline(
            dumperFactory:     $factory,
            compression:       new GzipDriver(new BinaryLocator()),
            encryptionFactory: $this->app->make(EncryptionFactory::class),
            keyResolver:       $this->app->make(EncryptionKeyResolver::class),
            uploader:          $uploader,
            config:            $this->app->make('config'),
        );
    }

    private function makeContext(string $driver): BackupContext
    {
        return new BackupContext(
            tenantId:       null,
            databaseName:   'fake',
            connectionName: 'testing',
            disk:           's3',
            driver:         $driver,
        );
    }

    private function makeMetadata(): BackupMetadata
    {
        return new BackupMetadata(
            backupId:  1,
            tenantId:  null,
            bucket:    'test-bucket',
            path:      'backups/fake.sql.gz',
            disk:      's3',
            startedAt: CarbonImmutable::now(),
        );
    }
}

/**
 * Emits one byte, then sleeps for 30s (far longer than the 1s idle timeout
 * used above) before ever producing more output or exiting.
 */
final class StallAfterFirstByteFakeDumper extends AbstractProcessDumper
{
    protected function buildCommand(BackupContext $context): array
    {
        return ['sh', '-c', 'printf x; sleep 30'];
    }

    public function name(): string
    {
        return 'fake-stall-after-first-byte';
    }
}

/**
 * Streams a byte every 50ms indefinitely (well past the 1s max-runtime
 * budget used above) so idle timeout never trips — only max runtime should.
 */
final class NeverEndingFakeDumper extends AbstractProcessDumper
{
    protected function buildCommand(BackupContext $context): array
    {
        return ['sh', '-c', 'while :; do printf x; sleep 0.05; done'];
    }

    public function name(): string
    {
        return 'fake-never-ending-stream';
    }
}

final class RecordingUploadDriverForTimeoutTest implements UploadDriver
{
    public bool $aborted = false;

    public bool $completed = false;

    public function preflight(): void
    {
    }

    public function initiate(BackupMetadata $metadata): WriteSession
    {
        return new class ($metadata) extends WriteSession {
        };
    }

    public function uploadChunk(WriteSession $session, int $chunkNumber, $body, int $size): void
    {
    }

    public function complete(WriteSession $session): UploadResult
    {
        $this->completed = true;

        return new UploadResult(
            bucket:          $session->metadata->bucket,
            key:             $session->metadata->path,
            sizeBytes:       $session->totalBytes(),
            partCount:       $session->partCount(),
            durationSeconds: 0.0,
            checksum:        '',
        );
    }

    public function abort(WriteSession $session): void
    {
        $this->aborted = true;
    }
}
