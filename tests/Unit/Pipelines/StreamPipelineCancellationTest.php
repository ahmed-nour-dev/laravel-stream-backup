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
use Ahmednour\StreamBackup\Exceptions\PipelineCancelledException;
use Ahmednour\StreamBackup\Pipelines\StreamPipeline;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use Ahmednour\StreamBackup\Support\EncryptionKeyResolver;
use Ahmednour\StreamBackup\Tests\TestCase;
use Ahmednour\StreamBackup\Uploaders\Sessions\WriteSession;
use Carbon\CarbonImmutable;

/**
 * Regression test for the SIGTERM-doesn't-cancel-the-pipeline issue.
 *
 * Uses a fake dumper that streams for ~5s if left uninterrupted (250 chunks
 * at 20ms apart) — long enough that "cancellation was ignored and the
 * pipeline ran to completion" and "cancellation was honored immediately"
 * are trivially distinguishable by elapsed time, without ever hanging the
 * test suite if the fix regresses.
 */
final class StreamPipelineCancellationTest extends TestCase
{
    public function test_tripped_cancellation_callback_aborts_the_upload_instead_of_draining_the_pipeline(): void
    {
        $uploader = new RecordingUploadDriver();

        $pipeline = new StreamPipeline(
            dumperFactory:    $this->fakeDumperFactory(),
            compression:      new GzipDriver(new BinaryLocator()),
            encryptionFactory: $this->app->make(EncryptionFactory::class),
            keyResolver:      $this->app->make(EncryptionKeyResolver::class),
            uploader:         $uploader,
            config:           $this->app->make('config'),
        );

        $context = new BackupContext(
            tenantId:       null,
            databaseName:   'fake',
            connectionName: 'testing',
            disk:           's3',
            driver:         'fake-slow-dump',
        );

        $metadata = new BackupMetadata(
            backupId:  1,
            tenantId:  null,
            bucket:    'test-bucket',
            path:      'backups/fake.sql.gz',
            disk:      's3',
            startedAt: CarbonImmutable::now(),
        );

        $callCount = 0;
        $cancellationRequested = static function () use (&$callCount): bool {
            // Simulate SIGTERM landing almost immediately — well before the
            // ~5s the fake dump needs to drain naturally.
            return ++$callCount > 3;
        };

        $startedAt = microtime(true);

        try {
            $pipeline->run($context, $metadata, $cancellationRequested);
            self::fail('Expected PipelineCancelledException was not thrown.');
        } catch (PipelineCancelledException) {
            // expected
        }

        $elapsed = microtime(true) - $startedAt;

        self::assertLessThan(
            1.0,
            $elapsed,
            'Cancellation should trip within ~one polling interval, not after the pipeline drains naturally.',
        );
        self::assertTrue($uploader->aborted, 'Multipart upload should be aborted on cancellation.');
        self::assertFalse($uploader->completed, 'complete() must never be called once cancelled.');
    }

    private function fakeDumperFactory(): DumperFactory
    {
        $factory = $this->app->make(DumperFactory::class);
        $factory->extend('fake-slow-dump', fn () => new SlowFakeDumper(new BinaryLocator(), $this->app->make('config')));

        return $factory;
    }
}

/**
 * Streams 250 single-byte chunks 20ms apart (~5s total) so an un-cancelled
 * run is unambiguously distinguishable from a cancelled one by elapsed time.
 */
final class SlowFakeDumper extends AbstractProcessDumper
{
    protected function buildCommand(BackupContext $context): array
    {
        return ['sh', '-c', 'i=0; while [ $i -lt 250 ]; do printf x; i=$((i+1)); sleep 0.02; done'];
    }

    public function name(): string
    {
        return 'fake-slow-dump';
    }
}

final class RecordingUploadDriver implements UploadDriver
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
