<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Pipelines;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\CompressionDriver;
use Ahmednour\StreamBackup\Contracts\DownloadDriver;
use Ahmednour\StreamBackup\DTOs\RestoreContext;
use Ahmednour\StreamBackup\DTOs\RestoreResult;
use Ahmednour\StreamBackup\Encryption\EncryptionFactory;
use Ahmednour\StreamBackup\Enums\RestoreStatus;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Restore\SqlDumpParser;
use Ahmednour\StreamBackup\Restore\TableRestorer;
use Ahmednour\StreamBackup\Support\EncryptionKeyResolver;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;

/**
 * Streaming restore pipeline — the inverse of StreamPipeline.
 *
 *   Download (stream) → decrypt (if encrypted) → decompress (pigz -d)
 *       → SqlDumpParser (line-by-line) → TableRestorer (transaction)
 *
 * Key design choices:
 *
 * 1. Streaming download: The DownloadDriver returns a BackupStream that is
 *    consumed incrementally, never buffered entirely in memory.
 *
 * 2. Non-blocking decompression: pigz -d is spawned as a child process;
 *    the encrypted/plain stream is piped into its stdin via stream_select()
 *    while decompressed SQL is read from stdout — same pattern as the
 *    backup pipeline.
 *
 * 3. No full decompressed dump ever exists in one place. Each chunk read
 *    from the decompressor's stdout is fed directly into the SqlDumpParser
 *    as it arrives — the parser routes lines into bounded per-table
 *    php://temp buffers on the fly. There is no intermediate buffer holding
 *    the whole decompressed dump, on disk or in memory.
 *
 * 4. The TableRestorer executes inside a single DB transaction with
 *    FK checks disabled.
 */
final class RestorePipeline
{
    public function __construct(
        private readonly CompressionDriver $compression,
        private readonly EncryptionFactory $encryptionFactory,
        private readonly EncryptionKeyResolver $keyResolver,
        private readonly TableRestorer $restorer,
        private readonly DownloadDriver $downloader,
        private readonly Config $config,
    ) {
    }

    public function run(RestoreContext $context, Backup $backup, ?callable $onProgress = null): RestoreResult
    {
        Log::debug("[RestorePipeline] run() invoked for backup {$backup->id}.");
        $startTime = microtime(true);
        $readChunk = (int) $this->config->get('stream-backup.read_chunk', 64 * 1024);

        // 1. Verify the backup file exists on the configured storage.
        $this->downloader->assertExists($backup->path ?? '');
        Log::debug("[RestorePipeline] File exists on storage: {$backup->path}");

        // 2. Download the backup as a stream.
        if ($onProgress) {
            $onProgress(RestoreStatus::Downloading);
        }
        Log::debug("[RestorePipeline] Requesting download stream...");
        $downloadStream = $this->downloader->download($backup->path);
        Log::debug("[RestorePipeline] Download stream initialized.");

        // 3. Decrypt if the backup was encrypted.
        $isEncrypted = $backup->encryption_driver !== null && $backup->encryption_driver !== '' && $backup->encryption_driver !== 'none';
        if ($isEncrypted && $onProgress) {
            $onProgress(RestoreStatus::Decrypting);
        }
        $decryptedStream = $this->applyDecryption($downloadStream, $backup);
        Log::debug("[RestorePipeline] Decryption stream initialized (Driver: " . ($backup->encryption_driver ?: 'none') . ").");

        // 4. Spawn decompressor (pigz -d -c) and pipe the stream through it.
        if ($onProgress) {
            $onProgress(RestoreStatus::Decompressing);
        }
        Log::debug("[RestorePipeline] Spawning decompressor process...");
        [$decompProc, $decompStdin, $decompStdout, $decompStderr] = $this->spawnDecompressor();
        Log::debug("[RestorePipeline] Decompressor process spawned successfully.");

        try {
            // 5. Pipe the decrypted stream into the decompressor's stdin,
            //    while simultaneously feeding decompressed SQL, chunk by
            //    chunk, straight into the SqlDumpParser. The parser routes
            //    each line into a bounded per-table buffer as it arrives —
            //    the full decompressed dump never accumulates anywhere.
            if ($onProgress) {
                $onProgress(RestoreStatus::Parsing);
            }
            $parser = new SqlDumpParser($context->tables);
            Log::debug("[RestorePipeline] Piping stream to decompressor and parsing inline...");
            $this->pipeToDecompressor(
                $decryptedStream,
                $decompProc,
                $decompStdin,
                $decompStdout,
                $decompStderr,
                $readChunk,
                $parser,
            );
            $tableBlocks = $parser->finish();
            Log::debug("[RestorePipeline] Decompression and parsing completed. Found " . count($tableBlocks) . " table blocks.");

            // Exclude the package's own tracking tables to prevent the
            // restore from destroying its own restore record. A full restore
            // replays DROP + CREATE + INSERT for every table; when the
            // target database hosts the backups/restores tables, this wipes
            // the current restore record (so the final status UPDATE
            // silently affects 0 rows) and replaces the backups table with
            // stale data from the dump.
            $tableBlocks = $this->excludeTables($tableBlocks);

            // 7. Restore the tables inside a transaction.
            if ($onProgress) {
                $onProgress(RestoreStatus::Importing);
            }
            Log::debug("[RestorePipeline] Handing over to TableRestorer...");
            $result = $this->restorer->restore($tableBlocks, $context->connectionName, $startTime);
            Log::debug("[RestorePipeline] TableRestorer completed.");
            return $result;
        } catch (\Throwable $e) {
            Log::error("[RestorePipeline] Exception caught: {$e->getMessage()}", ['exception' => $e]);
            $this->killProcess($decompProc);
            throw $e instanceof PipelineException ? $e : new PipelineException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Remove excluded tables from the parsed table blocks.
     *
     * The package's own tracking tables (backups, restores) must never be
     * restored into the target database: doing so would DROP and recreate
     * them with stale dump data, silently wiping the current restore
     * record and replacing backup metadata with old data.
     *
     * @param  array<string, resource> $tableBlocks
     * @return array<string, resource>
     */
    private function excludeTables(array $tableBlocks): array
    {
        $excludeTables = array_map('strtolower', (array) $this->config->get(
            'stream-backup.restore.exclude_tables',
            ['backups', 'restores'],
        ));

        if ($excludeTables === []) {
            return $tableBlocks;
        }

        foreach ($tableBlocks as $tableName => $buffer) {
            if (! in_array(strtolower($tableName), $excludeTables, true)) {
                continue;
            }

            if (is_resource($buffer)) {
                @fclose($buffer);
            }

            unset($tableBlocks[$tableName]);
            Log::info("[RestorePipeline] Excluded table `{$tableName}` from restore (package tracking table).");
        }

        return $tableBlocks;
    }

    /**
     * Apply decryption if the backup was encrypted.
     */
    private function applyDecryption(BackupStream $stream, Backup $backup): BackupStream
    {
        $driverName = $backup->encryption_driver;

        if ($driverName === null || $driverName === '' || $driverName === 'none') {
            return $stream;
        }

        $driver = $this->encryptionFactory->make($driverName);
        $key    = $this->keyResolver->resolve($driver);

        return $driver->spawnDecrypt($stream, $key);
    }

    /**
     * Pipe the source stream into the decompressor while, in the same loop,
     * feeding each decompressed chunk read from stdout directly into the
     * SqlDumpParser as it becomes available via stream_select(). No
     * intermediate buffer ever holds the full decompressed dump.
     */
    private function pipeToDecompressor(
        BackupStream $source,
        mixed $decompProc,
        mixed $decompStdin,
        mixed $decompStdout,
        mixed $decompStderr,
        int $readChunk,
        SqlDumpParser $parser,
    ): void {
        Log::debug("[RestorePipeline] Entering pipeToDecompressor loop.");

        $pendingChunk    = '';
        $sourceDone      = false;
        $stdinClosed     = false;
        $stdoutDone      = false;
        $stderrDone      = false;
        $stderrBuffer    = '';

        while (true) {
            // Read from the BackupStream (non-blocking) when we have no pending data.
            if (! $sourceDone && $pendingChunk === '') {
                $chunk = $source->read($readChunk);
                if ($chunk === null) {
                    $sourceDone = true;
                } elseif ($chunk !== '') {
                    $pendingChunk .= $chunk;
                }
            }

            $read   = [];
            $write  = [];
            $except = null;

            if (! $stdoutDone) {
                $read[] = $decompStdout;
            }

            if ($pendingChunk !== '' && ! $stdinClosed) {
                $write[] = $decompStdin;
            }

            // Also drain stderr.
            if (! $stderrDone && is_resource($decompStderr)) {
                $read[] = $decompStderr;
            }

            if ($read === [] && $write === []) {
                break;
            }

            $changed = @stream_select($read, $write, $except, 0, 200_000);

            if ($changed === false) {
                $err = error_get_last();
                if ($err !== null && isset($err['message']) && str_contains($err['message'], 'Interrupted system call')) {
                    continue;
                }
                throw new PipelineException('stream_select failed during decompression.');
            }

            // Write pending data to decompressor stdin.
            if ($pendingChunk !== '' && ! $stdinClosed && in_array($decompStdin, $write, true)) {
                $written = @fwrite($decompStdin, $pendingChunk);
                if ($written === false) {
                    throw new PipelineException('Failed to write to decompressor stdin.');
                }
                if ($written > 0) {
                    $pendingChunk = substr($pendingChunk, $written);
                }
            }

            // Close decompressor stdin once source is fully piped.
            if ($sourceDone && $pendingChunk === '' && ! $stdinClosed) {
                @fclose($decompStdin);
                $stdinClosed = true;
            }

            // Read decompressed output.
            if (! $stdoutDone && in_array($decompStdout, $read, true)) {
                $data = @fread($decompStdout, $readChunk);
                if ($data === false || ($data === '' && feof($decompStdout))) {
                    $stdoutDone = true;
                } elseif ($data !== '') {
                    $parser->feed($data);
                }
            }

            // Drain stderr.
            if (! $stderrDone && is_resource($decompStderr) && in_array($decompStderr, $read, true)) {
                $errData = @fread($decompStderr, 8192);
                if ($errData === false || ($errData === '' && feof($decompStderr))) {
                    $stderrDone = true;
                } elseif ($errData !== '') {
                    $stderrBuffer .= $errData;
                }
            }
        }

        // Close remaining pipes and validate exit code.
        Log::debug("[RestorePipeline] Exited pipeToDecompressor loop. Validating exit code...");
        if (is_resource($decompStdout)) {
            @fclose($decompStdout);
        }
        if (is_resource($decompStderr)) {
            @fclose($decompStderr);
        }

        $this->validateDecompressorExit($decompProc, $stderrBuffer);
        Log::debug("[RestorePipeline] Decompressor exit code validated successfully.");

        // Close the source stream (which may also close the decryption layer).
        $source->close();
    }

    /**
     * Spawn the decompression child process.
     *
     * @return array{0: resource, 1: resource, 2: resource, 3: resource}
     */
    private function spawnDecompressor(): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($this->compression->buildDecompressCommand(), $descriptors, $pipes);

        if (! is_resource($proc)) {
            throw new PipelineException('Failed to start decompression process.');
        }

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$proc, $pipes[0], $pipes[1], $pipes[2]];
    }

    private function validateDecompressorExit(mixed $proc, string $stderrBuffer): void
    {
        $status = proc_get_status($proc);
        $waitStart = microtime(true);

        while ($status['running'] && microtime(true) - $waitStart < 5.0) {
            usleep(50_000);
            $status = proc_get_status($proc);
        }

        if ($status['running']) {
            proc_terminate($proc);
            proc_close($proc);
            throw new PipelineException('Decompression process timed out.');
        }

        $exitCode = $status['exitcode'];
        proc_close($proc);

        if ($exitCode !== 0) {
            throw new PipelineException(sprintf(
                'Decompression process exited with code %d. stderr: %s',
                $exitCode,
                trim($stderrBuffer),
            ));
        }
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
}
