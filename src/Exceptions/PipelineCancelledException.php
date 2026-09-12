<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Exceptions;

/**
 * Thrown when a cancellation callback passed to StreamPipeline::run() trips
 * mid-stream (e.g. SIGTERM received by the worker). Extends PipelineException
 * so it is caught by the pipeline's own cleanup handler, which aborts the
 * multipart upload and terminates the dump/compressor processes.
 */
class PipelineCancelledException extends PipelineException
{
}
