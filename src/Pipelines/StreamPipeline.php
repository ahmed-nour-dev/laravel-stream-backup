<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Pipelines;

use Ahmednour\StreamBackup\Contracts\CompressionDriver;
use Ahmednour\StreamBackup\Contracts\UploadDriver;
use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use Ahmednour\StreamBackup\DTOs\UploadResult;
use Ahmednour\StreamBackup\Dumpers\DumperFactory;
use Ahmednour\StreamBackup\Encryption\EncryptionFactory;
use Ahmednour\StreamBackup\Events\CompressionCompleted;
use Ahmednour\StreamBackup\Events\DatabaseDumped;
use Ahmednour\StreamBackup\Events\UploadFinished;
use Ahmednour\StreamBackup\Exceptions\PipelineCancelledException;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Streams\ChecksumStream;
use Ahmednour\StreamBackup\Streams\ProcessBackupStream;
use Ahmednour\StreamBackup\Support\EncryptionKeyResolver;
use Ahmednour\StreamBackup\Uploaders\Sessions\WriteSession;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Non-blocking streaming pipeline:
 *
 *   dump stdout -> compress stdin -> compress stdout -> S3 multipart upload
 *
 * Key design choices (ported from the hardened backup.php prototype):
 *
 * 1. Non-blocking pipes + stream_select: no process ever blocks waiting
 *    for another, which eliminates the classic 3-process deadlock.
 *
 * 2. Write-side stream_select: pigz stdin is added to the $write array
 *    only when there is pending data, letting the kernel notify us when
 *    the pipe is writable instead of polling with usleep(1_000).
 *
 * 3. php://temp part buffer: spills to disk after 2 MB, passed directly
 *    to uploadPart as a stream resource so no 32 MB string copy.
 *
 * 4. Exit-code check BEFORE completeMultipartUpload: otherwise a
 *    mid-stream dump failure would produce a completed-but-corrupt
 *    gzip on S3 that we can no longer abort.
 *
 * 5. Per-context dumper resolution via DumperFactory: in multi-tenant
 *    setups, different tenants may use different databases. The dumper
 *    is resolved per-run from BackupContext::$driver.
 */
final class StreamPipeline
{
    public function __construct(
        private readonly DumperFactory $dumperFactory,
        private readonly CompressionDriver $compression,
        private readonly EncryptionFactory $encryptionFactory,
        private readonly EncryptionKeyResolver $keyResolver,
        private readonly UploadDriver $uploader,
        private readonly Config $config,
    ) {
    }

    /**
     * @param (\Closure(): bool)|null $cancellationRequested Polled once per
     *  stream_select() iteration (at most every 200ms). When it returns true,
     *  the pipeline aborts the in-flight multipart upload, terminates the
     *  dump/compressor processes, and throws PipelineCancelledException —
     *  instead of running to completion before honoring the cancellation.
     */
    public function run(BackupContext $context, BackupMetadata $metadata, ?\Closure $cancellationRequested = null): UploadResult
    {
        $readChunk = (int) $this->config->get('stream-backup.read_chunk', 64 * 1024);
        $partSize  = (int) $this->config->get('stream-backup.multipart.part_size', 32 * 1024 * 1024);

        // 1. Resolve the correct dumper for this context's driver.
        $dumper = $this->dumperFactory->make($context->driver);

        // 2. Start the dump and extract raw pipes for stream_select().
        $dumpStream = $dumper->dump($context);
        if (! $dumpStream instanceof ProcessBackupStream) {
            throw new PipelineException(
                sprintf('%s must return a ProcessBackupStream.', $dumper->name())
            );
        }
        $pipes = $dumpStream->pipes();
        $dumpProc   = $pipes->process;
        $dumpStdout = $pipes->stdout;

        // 3. Start the compressor.
        [$pigzProc, $pigzStdin, $pigzStdout, $pigzStderr] = $this->spawnCompressor();

        // 4. Create a ProcessBackupStream wrapper for the compressor so close()
        //    can validate its exit code; wrap it in an optional EncryptionStream
        //    (no-op when driver = 'none'); then tee through a ChecksumStream so
        //    the SHA-256 is computed over the final ciphertext during upload.
        $pigzBackupStream = new ProcessBackupStream($pigzProc, $pigzStdout, $pigzStderr, $this->compression->name());
        $encryption       = $this->encryptionFactory->make();
        $key              = $this->keyResolver->resolve($encryption);
        $encryptedStream  = $encryption->spawn($pigzBackupStream, $key);
        $compressedStream = new ChecksumStream($encryptedStream);

        // 5. Initiate the multipart upload (persists UploadId to the backups row).
        $session = $this->uploader->initiate($metadata);

        // 6. Allocate the streaming part buffer once.
        $partBuffer = fopen('php://temp', 'r+b');
        $bufferSize = 0;
        $partNumber = 1;

        // 7. Pipeline state.
        $pendingChunk    = '';    // bytes waiting to be written into compressor stdin
        $dumpDone        = false; // dump stdout closed
        $pigzInputClosed = false; // compressor stdin closed (EOF signalled)
        $pigzDone        = false; // compressor stdout closed

        $startedAt = microtime(true);

        try {
            while (true) {
                if ($cancellationRequested !== null && $cancellationRequested()) {
                    throw new PipelineCancelledException('Backup pipeline cancelled.');
                }

                $read  = [];
                $write = [];
                $except = null;

                // Read from dump only when we have room to buffer.
                if (! $dumpDone && $pendingChunk === '') {
                    $read[] = $dumpStdout;
                }
                if (! $pigzDone) {
                    $read[] = $pigzStdout;
                }

                // Write side: only register compressor stdin when there's data queued.
                if ($pendingChunk !== '') {
                    $write[] = $pigzStdin;
                }

                if ($read === [] && $write === []) {
                    break;
                }

                $changed = @stream_select($read, $write, $except, 0, 200_000);

                if ($changed === false) {
                    // EINTR from signal handlers is normal; retry.
                    $err = error_get_last();
                    if ($err !== null && isset($err['message']) && str_contains($err['message'], 'Interrupted system call')) {
                        continue;
                    }
                    throw new PipelineException('stream_select failed.');
                }

                // ---- dump stdout ----
                if (! $dumpDone && in_array($dumpStdout, $read, true)) {
                    $chunk = $dumpStream->read($readChunk);

                    if ($chunk === null) {
                        $dumpDone = true;
                    } elseif ($chunk !== '') {
                        $pendingChunk .= $chunk;
                    }
                }

                // ---- compressor stdin (write side) ----
                if ($pendingChunk !== '' && in_array($pigzStdin, $write, true)) {
                    $written = @fwrite($pigzStdin, $pendingChunk);
                    if ($written === false) {
                        throw new PipelineException('Failed to write to compressor stdin.');
                    }
                    if ($written > 0) {
                        $pendingChunk = substr($pendingChunk, $written);
                    }
                }

                // If the dump is fully drained AND the pending buffer has been
                // flushed, signal EOF to the compressor by closing its stdin.
                if ($dumpDone && $pendingChunk === '' && ! $pigzInputClosed) {
                    @fclose($pigzStdin);
                    $pigzInputClosed = true;
                }

                // ---- compressor stdout ----
                if (! $pigzDone && in_array($pigzStdout, $read, true)) {
                    $compressed = $compressedStream->read($readChunk);

                    if ($compressed === null) {
                        $pigzDone = true;
                    } elseif ($compressed !== '') {
                        fwrite($partBuffer, $compressed);
                        $bufferSize += strlen($compressed);

                        if ($bufferSize >= $partSize) {
                            $this->flushPart($session, $partBuffer, $bufferSize, $partNumber);
                            $partNumber++;
                            $bufferSize = 0;
                        }
                    }
                }
            }

            // Flush the (smaller) tail part.
            if ($bufferSize > 0) {
                $this->flushPart($session, $partBuffer, $bufferSize, $partNumber);
            }

            // Validate BOTH processes BEFORE completing the upload so a
            // mid-stream failure never produces a completed object.
            $dumpStream->close();       // throws on non-zero exit / stderr errors
            DatabaseDumped::dispatch($context);

            $compressedStream->close(); // closes compressor stream and validates exit
            CompressionCompleted::dispatch($context);

            $result = $this->uploader->complete($session);
            
            $duration = microtime(true) - $startedAt;
            $uploadResult = new UploadResult(
                bucket:          $result->bucket,
                key:             $result->key,
                sizeBytes:       $result->sizeBytes,
                partCount:       $result->partCount,
                durationSeconds: $duration,
                checksum:        $compressedStream->checksum(),
            );

            UploadFinished::dispatch($context, $uploadResult);

            return $uploadResult;
        } catch (\Throwable $e) {
            // Best-effort cleanup: abort multipart and terminate processes.
            $this->uploader->abort($session);
            $this->killProcess($dumpProc);
            $this->killProcess($pigzProc);
            $this->closeIfOpen($partBuffer);
            // Best-effort: close the encryption stream to wipe key material
            // even if the pipeline failed mid-stream.
            try {
                $compressedStream->close();
            } catch (\Throwable) {
                // Suppress — we're already handling an exception.
            }
            throw $e instanceof PipelineException ? $e : new PipelineException($e->getMessage(), 0, $e);
        } finally {
            $this->closeIfOpen($partBuffer);
        }
    }

    /**
     * @param resource $buffer
     */
    private function flushPart(WriteSession $session, $buffer, int $size, int $partNumber): void
    {
        rewind($buffer);

        $this->uploader->uploadChunk($session, $partNumber, $buffer, $size);

        // Reuse the same stream instead of re-opening: O(1), no allocation.
        ftruncate($buffer, 0);
        rewind($buffer);
    }

    /**
     * @return array{0: resource, 1: resource, 2: resource, 3: resource}
     */
    private function spawnCompressor(): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($this->compression->buildCommand(), $descriptors, $pipes);

        if (! is_resource($proc)) {
            throw new PipelineException('Failed to start compression process.');
        }

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$proc, $pipes[0], $pipes[1], $pipes[2]];
    }

    /**
     * @param resource|null $proc
     */
    private function killProcess($proc): void
    {
        if (is_resource($proc)) {
            @proc_terminate($proc);
            @proc_close($proc);
        }
    }

    /**
     * @param mixed $handle
     */
    private function closeIfOpen($handle): void
    {
        if (is_resource($handle)) {
            @fclose($handle);
        }
    }
}
