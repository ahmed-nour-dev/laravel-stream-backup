<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Support;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\DownloadDriver;
use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\Encryption\EncryptionFactory;
use Ahmednour\StreamBackup\Jobs\RunBackupJob;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Support\EncryptionKeyResolver;

/**
 * Shared helpers for the real-service integration suite (tests/Integration):
 *
 *  - running the package migrations, which TestCase does not load automatically
 *  - checking an external binary is on PATH without throwing
 *  - draining a non-blocking process pipe (dumper stdout) into a string
 *  - driving RunBackupJob synchronously exactly as a `sync` queue connection
 *    would, so the full dump -> compress -> encrypt -> upload -> verify
 *    pipeline runs against real services
 *  - pulling an uploaded backup back down through the real DownloadDriver +
 *    EncryptionFactory, proving the bytes that landed on the remote service
 *    are the original dump rather than just that the upload call didn't throw
 */
trait BuildsIntegrationBackups
{
    private function runPackageMigrations(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') as $file) {
            (require $file)->up();
        }
    }

    private function binaryAvailable(string $binary): bool
    {
        $output = @shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($binary)));

        return is_string($output) && trim($output) !== '';
    }

    /**
     * Reads a (possibly non-blocking) BackupStream to completion. Dumper
     * process pipes are set non-blocking, so a '' chunk does not mean EOF —
     * only a null return does.
     */
    private function drain(BackupStream $stream): string
    {
        $out = '';

        while (($chunk = $stream->read(65536)) !== null) {
            if ($chunk === '') {
                usleep(1_000);
                continue;
            }

            $out .= $chunk;
        }

        return $out;
    }

    /**
     * Runs RunBackupJob::handle() synchronously (the same way a `sync` queue
     * connection would) and returns the resulting Backup row.
     */
    private function runBackupSync(BackupContext $context): Backup
    {
        $job = new RunBackupJob($context);

        $this->app->call([$job, 'handle']);

        return Backup::where('attempt_group_id', $context->attemptGroupId)->firstOrFail();
    }

    /**
     * Downloads the backup through the real DownloadDriver, decrypts it (if
     * encrypted) through the real EncryptionFactory, and gunzips it — giving
     * back the original plaintext dump bytes for content assertions.
     */
    private function downloadAndDecompress(Backup $backup): string
    {
        $downloader = $this->app->make(DownloadDriver::class);
        $stream     = $downloader->download((string) $backup->path);

        $driverName = $backup->encryption_driver;
        if ($driverName !== null && $driverName !== '' && $driverName !== 'none') {
            $driver = $this->app->make(EncryptionFactory::class)->make($driverName);
            $key    = $this->app->make(EncryptionKeyResolver::class)->resolve($driver);
            $stream = $driver->spawnDecrypt($stream, $key);
        }

        $compressed = $this->drain($stream);
        $stream->close();

        $plaintext = gzdecode($compressed);

        if ($plaintext === false) {
            self::fail('Downloaded backup bytes did not gunzip cleanly.');
        }

        return $plaintext;
    }
}
