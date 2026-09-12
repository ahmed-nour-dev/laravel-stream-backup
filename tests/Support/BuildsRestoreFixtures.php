<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Support;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Encryption\OpenSslAes256GcmDriver;

/**
 * Builders for synthetic mysqldump-shaped fixtures used by the large-scale
 * restore integration tests: table blocks in the exact format SqlDumpParser
 * expects, gzip compression, and AES-256-GCM encryption — all driven through
 * the package's own real streams/drivers so the fixtures are byte-for-byte
 * what the production pipeline would produce, without needing an actual
 * mysqldump/pigz round trip for every test.
 */
trait BuildsRestoreFixtures
{
    /**
     * A minimal in-memory BackupStream over a fixed string, used to feed
     * plaintext into the real encryption stream classes when building
     * encrypted fixtures.
     */
    private function memoryBackupStream(string $data, int $chunkSize = 65536): BackupStream
    {
        return new class($data, $chunkSize) implements BackupStream {
            private int $offset = 0;
            private bool $eof = false;

            public function __construct(private readonly string $data, private readonly int $chunkSize)
            {
            }

            public function read(int $length = 65536): ?string
            {
                if ($this->eof) {
                    return null;
                }

                if ($this->offset >= strlen($this->data)) {
                    $this->eof = true;
                    return null;
                }

                $take = min($length, $this->chunkSize, strlen($this->data) - $this->offset);
                $out  = substr($this->data, $this->offset, $take);
                $this->offset += $take;

                return $out;
            }

            public function isEof(): bool
            {
                return $this->eof;
            }

            public function close(): void
            {
            }
        };
    }

    /**
     * mysqldump-shaped standard gzip bytes (decompressible by both `gzip -d`
     * and `pigz -d`, which is all RestorePipeline's spawned decompressor
     * needs).
     */
    private function gzipCompress(string $plaintext): string
    {
        $compressed = gzencode($plaintext, 6);

        if ($compressed === false) {
            self::fail('gzencode() failed while building the test fixture.');
        }

        return $compressed;
    }

    /**
     * Encrypt bytes using the real AES-256-GCM stream driver, producing the
     * exact wire format RestorePipeline's decryption side expects.
     */
    private function encryptAes256Gcm(string $plaintext, string $rawKey): string
    {
        $driver = new OpenSslAes256GcmDriver();
        $stream = $driver->spawn($this->memoryBackupStream($plaintext), $rawKey);

        $out = '';
        while (($chunk = $stream->read(65536)) !== null) {
            $out .= $chunk;
        }

        return $out;
    }

    /**
     * One mysqldump-shaped table block: structure marker, DROP, CREATE, data
     * marker, and batched multi-row INSERTs — exactly what SqlDumpParser's
     * TABLE_STRUCTURE_PATTERN / TABLE_DATA_PATTERN look for.
     *
     * @param  string[]  $rowTuples  Pre-formatted "(1,'a')" value tuples.
     */
    private function mysqldumpTableBlock(string $table, string $createSql, array $rowTuples, int $batchSize = 500): string
    {
        $sql = "-- Table structure for table `{$table}`\n"
            . "DROP TABLE IF EXISTS `{$table}`;\n"
            . rtrim($createSql) . "\n"
            . "-- Dumping data for table `{$table}`\n";

        foreach (array_chunk($rowTuples, $batchSize) as $batch) {
            if ($batch === []) {
                continue;
            }

            $sql .= "INSERT INTO `{$table}` VALUES " . implode(',', $batch) . ";\n";
        }

        return $sql;
    }

    /**
     * Generate $count "(id,'value')" row tuples for a simple (id INT, value
     * VARCHAR) table shape, with quasi-random payloads so the fixture
     * doesn't compress to a trivial, unrealistic ratio.
     *
     * @return string[]
     */
    private function generateRowTuples(int $count, int $startId = 1, int $payloadLen = 40): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $id      = $startId + $i;
            $payload = substr(bin2hex(md5((string) $id, true)) . bin2hex(md5((string) ($id * 7919), true)), 0, $payloadLen);
            $rows[]  = "({$id},'{$payload}')";
        }

        return $rows;
    }

    /**
     * Write raw bytes to a local file under $root, creating parent
     * directories as needed, and return the path relative to $root — ready
     * to hand to LocalDownloadDriver / a Backup model's `path`.
     */
    private function writeLocalBackupFile(string $root, string $relativePath, string $bytes): string
    {
        $fullPath = rtrim($root, '/') . '/' . ltrim($relativePath, '/');
        $dir      = dirname($fullPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, $bytes);

        return $relativePath;
    }

    /**
     * Recursively delete a directory tree. Best-effort test cleanup only.
     */
    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
