<?php

declare(strict_types=1);

// src/Uploaders/SftpChunkedUploader.php
namespace Ahmednour\StreamBackup\Uploaders;

use Ahmednour\StreamBackup\Contracts\UploadDriver;
use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use Ahmednour\StreamBackup\DTOs\UploadResult;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Models\BackupAttempt;
use Ahmednour\StreamBackup\Uploaders\Sessions\SftpWriteSession;
use Ahmednour\StreamBackup\Uploaders\Sessions\WriteSession;
use phpseclib3\Net\SFTP;

final class SftpChunkedUploader implements UploadDriver
{
    /**
     * @param  int  $fileMode  chmod mode applied to every uploaded file (e.g. 0600).
     *                         Resolve this via SftpPermissionResolver rather than
     *                         hand-computing it from a visibility string.
     * @param  int  $directoryMode  chmod mode applied to directories created on
     *                              demand (e.g. 0700).
     */
    public function __construct(
        private readonly SFTP $sftp,
        private readonly string $root = '',
        private readonly int $fileMode = 0600,
        private readonly int $directoryMode = 0700
    ) {}

    private function resolvePath(string $path): string
    {
        $path = ltrim($path, '/');
        return $this->root !== '' ? rtrim($this->root, '/') . '/' . $path : $path;
    }

    private function ensureDirectoryExists(string $dir): void
    {
        if ($dir === '.' || $dir === '/' || $dir === '') {
            return;
        }

        if (! $this->sftp->is_dir($dir)) {
            $this->sftp->mkdir($dir, $this->directoryMode, true);
        }
    }

    public function preflight(): void
    {
        $testPath = $this->resolvePath('.stream-backup-preflight-' . \Illuminate\Support\Str::random(16));

        try {
            if (! $this->sftp->put($testPath, 'pre-flight check')) {
                throw new \RuntimeException('put() returned false.');
            }

            $this->sftp->delete($testPath);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Pre-flight check failed for SFTP root '{$this->root}'. The path may be unreachable, read-only, or lack delete permissions.", 0, $e);
        }
    }

    public function initiate(BackupMetadata $metadata): WriteSession
    {
        $remotePath = $this->resolvePath($metadata->path);
        
        $this->ensureDirectoryExists(dirname($remotePath));

        // Create or truncate the remote file once
        if (! $this->sftp->put($remotePath, '', SFTP::RESUME)) {
            throw new PipelineException("Cannot open remote path for writing: {$remotePath}");
        }

        $this->sftp->chmod($this->fileMode, $remotePath);

        // No uploadId — the open SSH channel IS the session
        return new SftpWriteSession($this->sftp, $remotePath, $metadata);
    }

    public function uploadChunk(WriteSession $session, int $chunkNumber, $body, int $size): void
    {
        assert($session instanceof SftpWriteSession);

        // Read the chunk content from the resource the pipeline passed us
        $data = stream_get_contents($body);
        if ($data === false) {
            throw new PipelineException("Failed to read chunk {$chunkNumber} from stream.");
        }

        // RESUME|STRING appends at current EOF — no offset tracking needed
        if (! $this->sftp->put($session->remotePath, $data, SFTP::RESUME | SFTP::SOURCE_STRING)) {
            throw new PipelineException("SFTP write failed on chunk {$chunkNumber}.");
        }

        $session->recordChunk($size);

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
        assert($session instanceof SftpWriteSession);
        // Nothing to commit — every uploadChunk() already landed on disk
        return $this->buildResult($session);
    }

    public function abort(WriteSession $session): void
    {
        assert($session instanceof SftpWriteSession);

        try {
            $this->sftp->delete($session->remotePath);
        } catch (\Throwable) {}
    }

    private function buildResult(SftpWriteSession $session): UploadResult
    {
        $duration = max(0.0, microtime(true) - $session->metadata->startedAt->getTimestamp());

        return new UploadResult(
            bucket:          '',
            key:             $session->remotePath,
            sizeBytes:       $session->totalBytes(),
            partCount:       $session->partCount(),
            durationSeconds: $duration,
            checksum:        '',
        );
    }
}