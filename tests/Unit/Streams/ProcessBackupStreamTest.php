<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Streams;

use Ahmednour\StreamBackup\Exceptions\DumpFailedException;
use Ahmednour\StreamBackup\Streams\ProcessBackupStream;
use PHPUnit\Framework\TestCase;

/**
 * Covers the onProcessEnded cleanup hook used by dumpers to release
 * resources (e.g. a MySQL credential file) tied to the process's lifetime —
 * see AbstractProcessDumper::releaseResources().
 */
final class ProcessBackupStreamTest extends TestCase
{
    /**
     * @return array{0: resource, 1: resource, 2: resource}
     */
    private function spawn(string $script): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(['sh', '-c', $script], $descriptors, $pipes);

        if (! is_resource($process)) {
            self::fail('Failed to start test process.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes[1], $pipes[2]];
    }

    public function test_close_invokes_the_callback_exactly_once_on_a_successful_exit(): void
    {
        [$process, $stdout, $stderr] = $this->spawn('printf hi');

        $calls  = 0;
        $stream = new ProcessBackupStream($process, $stdout, $stderr, 'test', function () use (&$calls): void {
            $calls++;
        });

        while ($stream->read() !== null) {
            // drain
        }

        $stream->close();
        $stream->close(); // idempotent — must not invoke the callback again

        self::assertSame(1, $calls);
    }

    public function test_close_invokes_the_callback_even_when_the_exit_code_is_non_zero(): void
    {
        [$process, $stdout, $stderr] = $this->spawn('exit 1');

        $calls  = 0;
        $stream = new ProcessBackupStream($process, $stdout, $stderr, 'test', function () use (&$calls): void {
            $calls++;
        });

        while ($stream->read() !== null) {
            // drain
        }

        try {
            $stream->close();
            self::fail('Expected DumpFailedException was not thrown.');
        } catch (DumpFailedException) {
            // expected — cleanup must still have run
        }

        self::assertSame(1, $calls);
    }

    public function test_callback_fires_via_destructor_when_the_process_is_killed_without_calling_close(): void
    {
        // Mirrors StreamPipeline's cancellation/timeout path, which
        // terminates and closes the raw process resource directly instead
        // of calling BackupStream::close().
        [$process, $stdout, $stderr] = $this->spawn('sleep 5');

        $calls  = 0;
        $stream = new ProcessBackupStream($process, $stdout, $stderr, 'test', function () use (&$calls): void {
            $calls++;
        });

        proc_terminate($process);
        proc_close($process);

        unset($stream);

        self::assertSame(1, $calls);
    }

    public function test_no_callback_means_close_does_not_error(): void
    {
        [$process, $stdout, $stderr] = $this->spawn('printf hi');

        $stream = new ProcessBackupStream($process, $stdout, $stderr, 'test');

        while ($stream->read() !== null) {
            // drain
        }

        $stream->close();

        self::assertTrue($stream->isEof());
    }
}
