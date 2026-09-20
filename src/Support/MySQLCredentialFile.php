<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\DTOs\DatabaseCredentials;

/**
 * Writes MySQL credentials to a restricted-permission temp file so they are
 * passed to mysqldump via --defaults-extra-file instead of the command line
 * (which would leak them in `ps aux`).
 *
 * Deletion is explicit: callers must invoke delete() once the dump process
 * that consumes the file has exited and its pipes/handle are closed (see
 * AbstractProcessDumper::releaseResources() and ProcessBackupStream). A
 * shutdown-function safety net is also registered in case the worker process
 * dies before that explicit cleanup runs; it is a no-op once delete() has
 * already removed the file, so long-lived queue workers never accumulate
 * stale credential files across jobs.
 */
final class MySQLCredentialFile
{
    private ?string $path = null;

    private bool $deleted = true;

    public function write(DatabaseCredentials $credentials): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stream_bkp_cnf_');

        if ($path === false) {
            throw new \RuntimeException('Unable to allocate a temp file for MySQL credentials.');
        }

        $contents = sprintf(
            "[client]\nhost=%s\nport=%d\nuser=%s\npassword=%s\n",
            $credentials->host,
            $credentials->port,
            $credentials->username,
            $credentials->password,
        );

        file_put_contents($path, $contents);
        @chmod($path, 0600);

        $this->path    = $path;
        $this->deleted = false;

        register_shutdown_function(function () use ($path): void {
            $this->deleteIfExists($path);
        });

        return $path;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * Remove the credential file. Safe to call multiple times (including
     * from the shutdown-function safety net after an explicit call already
     * removed it) and safe to call when write() was never called.
     */
    public function delete(): void
    {
        if ($this->path !== null) {
            $this->deleteIfExists($this->path);
        }
    }

    private function deleteIfExists(string $path): void
    {
        if ($this->deleted) {
            return;
        }

        $this->deleted = true;

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
