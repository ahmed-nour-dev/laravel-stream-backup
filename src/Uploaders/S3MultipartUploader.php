<?php
declare(strict_types=1);

namespace Ahmednour\StreamBackup\Uploaders;

use Ahmednour\StreamBackup\Contracts\UploadDriver;
use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use Ahmednour\StreamBackup\DTOs\UploadResult;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Models\BackupAttempt;
use Ahmednour\StreamBackup\Uploaders\Sessions\S3MultipartSession;
use Ahmednour\StreamBackup\Uploaders\Sessions\WriteSession;
use Aws\S3\S3ClientInterface;

use Illuminate\Support\Str;

final class S3MultipartUploader implements UploadDriver
{
    public function __construct(
        private readonly S3ClientInterface $s3,
        private readonly string $bucket = '',
    ) {}

    public function preflight(): void
    {
        $testKey = '.stream-backup-preflight-' . Str::random(16);

        try {
            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $testKey,
                'Body'   => 'pre-flight check',
            ]);

            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $testKey,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Pre-flight check failed for S3 bucket '{$this->bucket}'. The bucket may be unreachable, read-only, or lack delete permissions.", 0, $e);
        }
    }

    public function initiate(BackupMetadata $metadata): WriteSession
    {
        $result   = $this->s3->createMultipartUpload([
            'Bucket'      => $metadata->bucket,
            'Key'         => $metadata->path,
            'ContentType' => $metadata->contentType,
        ]);
        $uploadId = $result['UploadId'] ?? null;

        if (! is_string($uploadId) || $uploadId === '') {
            throw new PipelineException('S3 createMultipartUpload did not return an UploadId.');
        }

        Backup::query()->whereKey($metadata->backupId)->update(['upload_id' => $uploadId]);
        if ($metadata->attemptId !== null) {
            BackupAttempt::query()->whereKey($metadata->attemptId)->update(['upload_id' => $uploadId]);
        }

        return new S3MultipartSession($uploadId, $metadata); // ← subclass, not MultipartSession
    }

    public function uploadChunk(WriteSession $session, int $chunkNumber, $body, int $size): void
    {
        assert($session instanceof S3MultipartSession); // only S3MultipartUploader creates these

        $result = $this->s3->uploadPart([
            'Bucket'        => $session->metadata->bucket,
            'Key'           => $session->metadata->path,
            'UploadId'      => $session->uploadId,
            'PartNumber'    => $chunkNumber,
            'Body'          => $body,
            'ContentLength' => $size,
        ]);

        $etag = $result['ETag'] ?? null;
        if (! is_string($etag) || $etag === '') {
            throw new PipelineException("uploadPart returned no ETag for part {$chunkNumber}.");
        }

        $session->recordPart($chunkNumber, $etag, $size);

        Backup::query()->whereKey($session->metadata->backupId)->update([
            'parts_uploaded' => $session->partCount(),
        ]);
        if ($session->metadata->attemptId !== null) {
            BackupAttempt::query()->whereKey($session->metadata->attemptId)->update([
                'parts_uploaded' => $session->partCount(),
            ]);
        }
    }

    public function complete(WriteSession $session): UploadResult
    {
        assert($session instanceof S3MultipartSession);

        if ($session->parts() === []) {
            throw new PipelineException('Refusing to complete: zero parts uploaded.');
        }

        $this->s3->completeMultipartUpload([
            'Bucket'          => $session->metadata->bucket,
            'Key'             => $session->metadata->path,
            'UploadId'        => $session->uploadId,
            'MultipartUpload' => ['Parts' => $session->parts()],
        ]);

        return $this->buildResult($session);
    }

    public function abort(WriteSession $session): void
    {
        assert($session instanceof S3MultipartSession);

        try {
            $this->s3->abortMultipartUpload([
                'Bucket'   => $session->metadata->bucket,
                'Key'      => $session->metadata->path,
                'UploadId' => $session->uploadId,
            ]);
        } catch (\Throwable) {
            // best-effort — stale upload caught by cleanup job
        }
    }

    private function buildResult(S3MultipartSession $session): UploadResult
    {
        $duration = max(0.0, microtime(true) - $session->metadata->startedAt->getTimestamp());

        return new UploadResult(
            bucket:          $session->metadata->bucket,
            key:             $session->metadata->path,
            sizeBytes:       $session->totalBytes(),
            partCount:       $session->partCount(),
            durationSeconds: $duration,
            checksum:        '',
        );
    }
}