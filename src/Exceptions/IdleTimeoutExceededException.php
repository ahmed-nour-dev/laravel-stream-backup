<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Exceptions;

/**
 * Thrown when the streaming pipeline makes no forward progress (no bytes
 * read from the dump, written to the compressor, or read from the
 * compressor) for `stream-backup.timeouts.idle_timeout` seconds.
 *
 * Detects a stalled database process, compressor, or network operation
 * even while the worker process itself is still alive and the overall
 * max runtime has not yet elapsed.
 */
class IdleTimeoutExceededException extends BackupTimeoutException
{
}
