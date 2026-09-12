<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Exceptions;

/**
 * Base type for both timeout safeguards (max runtime / idle timeout).
 * Extends PipelineException so it is caught by StreamPipeline's own cleanup
 * handler, which aborts the multipart upload and terminates the dump/
 * compressor processes before propagating.
 */
abstract class BackupTimeoutException extends PipelineException
{
}
