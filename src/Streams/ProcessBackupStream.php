<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Streams;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Exceptions\DumpFailedException;
use Ahmednour\StreamBackup\Exceptions\DumpPartialException;

/**
 * Wraps the stdout pipe of a proc_open()'d child process as a non-blocking
 * BackupStream. Stderr is drained on every read() so it cannot fill its
 * kernel buffer and block the child.
 *
 * On close(), the process exit code is validated:
 *  - non-zero exit  => DumpFailedException
 *  - zero exit but stderr contains "ERROR" patterns => DumpPartialException
 */
final class ProcessBackupStream implements BackupStream
{
    /** @var resource */
    private $process;

    /** @var resource */
    private $stdout;

    /** @var resource|null */
    private $stderr;

    private string $stderrBuffer = '';

    private bool $eof = false;

    private bool $closed = false;

    private ?\Closure $onProcessEnded;

    /**
     * @param resource      $process        proc_open handle
     * @param resource      $stdout         pipe 1 (already set non-blocking)
     * @param resource|null $stderr         pipe 2 (already set non-blocking), may be null if not captured
     * @param \Closure|null $onProcessEnded invoked exactly once, once the process has exited and its
     *                                      pipes/handle are closed — whether via close() or, when a
     *                                      caller kills the process resource directly without calling
     *                                      close(), via __destruct() as a safety net. Used by dumpers to
     *                                      release resources tied to the process (e.g. a credential file).
     */
    public function __construct(
        $process,
        $stdout,
        $stderr,
        private readonly string $label = 'process',
        ?\Closure $onProcessEnded = null,
    ) {
        $this->process        = $process;
        $this->stdout         = $stdout;
        $this->stderr         = $stderr;
        $this->onProcessEnded = $onProcessEnded;
    }

    public function __destruct()
    {
        $this->runOnProcessEnded();
    }

    public function read(int $length = 65536): ?string
    {
        $this->drainStderr();

        if ($this->eof) {
            return null;
        }

        $data = @fread($this->stdout, $length);

        if ($data === false) {
            return '';
        }

        if ($data === '' && feof($this->stdout)) {
            $this->eof = true;
            return null;
        }

        return $data;
    }

    public function isEof(): bool
    {
        return $this->eof;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $this->drainStderr();

        if (is_resource($this->stdout)) {
            @fclose($this->stdout);
        }
        if (is_resource($this->stderr)) {
            @fclose($this->stderr);
        }

        try {
            $exitCode = $this->waitForExit();
        } finally {
            // The process has exited (or been terminated) and proc_close()
            // has run by this point — safe to release tied resources
            // regardless of whether the exit code check below throws.
            $this->runOnProcessEnded();
        }

        if ($exitCode !== 0) {
            throw new DumpFailedException(sprintf(
                '%s exited with code %d. stderr: %s',
                $this->label,
                $exitCode,
                trim($this->stderrBuffer),
            ));
        }

        // mysqldump writes table-level failures to stderr while still
        // returning 0. Treat any "ERROR" marker as a hard failure.
        if ($this->stderrBuffer !== '' && preg_match('/\b(ERROR|error\s+\d+)\b/', $this->stderrBuffer) === 1) {
            throw new DumpPartialException(sprintf(
                '%s reported errors on stderr: %s',
                $this->label,
                trim($this->stderrBuffer),
            ));
        }
    }

    public function stderr(): string
    {
        return $this->stderrBuffer;
    }

    /**
     * Expose the raw process and pipe resources for stream_select().
     *
     * This replaces the Reflection-based extractPipes() hack in
     * StreamPipeline, restoring Liskov compliance for BackupStream.
     */
    public function pipes(): PipeSet
    {
        return new PipeSet($this->process, $this->stdout, $this->stderr);
    }

    private function drainStderr(): void
    {
        if (! is_resource($this->stderr)) {
            return;
        }

        while (($chunk = @fread($this->stderr, 8192)) !== false && $chunk !== '') {
            $this->stderrBuffer .= $chunk;
        }
    }

    private function runOnProcessEnded(): void
    {
        if ($this->onProcessEnded === null) {
            return;
        }

        $callback             = $this->onProcessEnded;
        $this->onProcessEnded = null;
        $callback();
    }

    private function waitForExit(): int
    {
        $status = proc_get_status($this->process);

        $waitStart = microtime(true);
        while ($status['running'] && microtime(true) - $waitStart < 5.0) {
            usleep(50_000);
            $status = proc_get_status($this->process);
        }

        if ($status['running']) {
            proc_terminate($this->process);
            return -1;
        }

        $code = $status['exitcode'];
        proc_close($this->process);

        return is_int($code) ? $code : -1;
    }
}
