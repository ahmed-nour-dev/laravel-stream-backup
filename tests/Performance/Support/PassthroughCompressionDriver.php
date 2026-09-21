<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Performance\Support;

use Ahmednour\StreamBackup\Contracts\CompressionDriver;

/**
 * Identity "compression" driver used to exercise the "compression disabled"
 * scenario in the performance suite.
 *
 * StreamPipeline always spawns a compressor subprocess between the dump and
 * the encryption/upload stages — there is no in-process no-op branch to
 * flip off. Routing that stage through `cat` instead of gzip/pigz keeps the
 * pipeline's real subprocess + stream_select machinery under test while
 * removing compression's own CPU/ratio effects, isolating the encryption
 * and upload stages' memory/throughput behavior on uncompressed bytes.
 */
final class PassthroughCompressionDriver implements CompressionDriver
{
    public function buildCommand(): array
    {
        return ['cat'];
    }

    public function buildDecompressCommand(): array
    {
        return ['cat'];
    }

    public function name(): string
    {
        return 'none';
    }
}
