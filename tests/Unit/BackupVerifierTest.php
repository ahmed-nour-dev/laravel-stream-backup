<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\EncryptionDriver;
use Ahmednour\StreamBackup\Contracts\VerifiesMagicBytes;
use Ahmednour\StreamBackup\Encryption\EncryptionFactory;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Support\BackupVerifier;
use Ahmednour\StreamBackup\Tests\TestCase;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;

final class BackupVerifierTest extends TestCase
{
    private function createS3Mock(int $size, string $magic): S3ClientInterface
    {
        $s3 = $this->createMock(S3ClientInterface::class);

        $s3->method('__call')->willReturnCallback(function (string $name, array $args) use ($size, $magic) {
            if ($name === 'headObject') {
                return ['ContentLength' => $size];
            }
            if ($name === 'getObject') {
                return ['Body' => $magic];
            }
            return [];
        });

        $this->app->instance(S3ClientInterface::class, $s3);
        
        config(['stream-backup.destination.driver' => 's3']);
        config(['filesystems.disks.s3.bucket' => 'test-bucket']);

        return $s3;
    }

    private function createVerifier(?EncryptionFactory $factory = null): BackupVerifier
    {
        return new BackupVerifier(
            $this->app,
            $this->app->make(\Illuminate\Contracts\Config\Repository::class),
            $factory ?? $this->app->make(EncryptionFactory::class)
        );
    }

    /**
     * Like createS3Mock(), but also serves a full-body getObject() (for the
     * streaming-checksum fallback) and an optional canned ChecksumMode
     * headObject response (for the server-side fast path). Tracks how many
     * times each call shape was made so tests can assert which path ran.
     *
     * @param array{ChecksumType?: string, ChecksumSHA256?: string}|null $checksumHead
     */
    private function createS3MockWithFullChecksum(
        int $size,
        string $magic,
        string $fullBody,
        ?array $checksumHead,
        array &$calls,
    ): S3ClientInterface {
        $s3 = $this->createMock(S3ClientInterface::class);

        $s3->method('__call')->willReturnCallback(function (string $name, array $args) use ($size, $magic, $fullBody, $checksumHead, &$calls) {
            $params = $args[0] ?? [];

            if ($name === 'headObject') {
                if (isset($params['ChecksumMode'])) {
                    $calls['checksumHead'] = ($calls['checksumHead'] ?? 0) + 1;
                    return array_merge(['ContentLength' => $size], $checksumHead ?? []);
                }
                return ['ContentLength' => $size];
            }

            if ($name === 'getObject') {
                if (isset($params['Range'])) {
                    return ['Body' => $magic];
                }

                $calls['fullDownload'] = ($calls['fullDownload'] ?? 0) + 1;
                $resource = fopen('php://temp', 'r+b');
                fwrite($resource, $fullBody);
                rewind($resource);
                return ['Body' => $resource];
            }

            return [];
        });

        $this->app->instance(S3ClientInterface::class, $s3);

        config(['stream-backup.destination.driver' => 's3']);
        config(['filesystems.disks.s3.bucket' => 'test-bucket']);

        return $s3;
    }

    public function test_throws_on_size_mismatch(): void
    {
        $this->createS3Mock(500, "\x1f\x8b");
        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path' => 'test.sql.gz',
            'disk' => 's3',
            'size' => 1000,
            'encryption_driver' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Backup size mismatch');

        $verifier->verify($backup);
    }

    public function test_unencrypted_passes_with_correct_magic_bytes(): void
    {
        $this->createS3Mock(1000, "\x1f\x8b");
        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path' => 'test.sql.gz',
            'disk' => 's3',
            'size' => 1000,
            'encryption_driver' => null,
        ]);

        $verifier->verify($backup);
        $this->assertTrue(true); // Verification passed
    }

    public function test_unencrypted_throws_with_incorrect_magic_bytes(): void
    {
        $this->createS3Mock(1000, "\x00\x00");
        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path' => 'test.sql.gz',
            'disk' => 's3',
            'size' => 1000,
            'encryption_driver' => 'none',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not start with the gzip magic bytes');

        $verifier->verify($backup);
    }

    public function test_openssl_passes_with_correct_version_byte(): void
    {
        $this->createS3Mock(1000, "\x01\xff");
        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path' => 'test.sql.gz.enc',
            'disk' => 's3',
            'size' => 1000,
            'encryption_driver' => 'openssl-aes-256-gcm',
        ]);

        $verifier->verify($backup);
        $this->assertTrue(true); // Verification passed
    }

    public function test_openssl_throws_with_incorrect_version_byte(): void
    {
        $this->createS3Mock(1000, "\x02\xff");
        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path' => 'test.sql.gz.enc',
            'disk' => 's3',
            'size' => 1000,
            'encryption_driver' => 'openssl-aes-256-gcm',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not start with the expected version byte');

        $verifier->verify($backup);
    }

    public function test_sodium_passes_with_correct_version_byte(): void
    {
        $this->createS3Mock(1000, "\x02\xff");
        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path' => 'test.sql.gz.enc',
            'disk' => 's3',
            'size' => 1000,
            'encryption_driver' => 'sodium',
        ]);

        $verifier->verify($backup);
        $this->assertTrue(true); // Verification passed
    }

    public function test_sodium_throws_with_incorrect_version_byte(): void
    {
        $this->createS3Mock(1000, "\x01\xff");
        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path' => 'test.sql.gz.enc',
            'disk' => 's3',
            'size' => 1000,
            'encryption_driver' => 'sodium',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not start with the expected version byte');

        $verifier->verify($backup);
    }

    public function test_custom_driver_without_interface_bypasses_magic_bytes_check(): void
    {
        $factory = $this->app->make(EncryptionFactory::class);
        $factory->extend('custom-driver', function () {
            return new class implements EncryptionDriver {
                public function spawn(BackupStream $inner, string $key): BackupStream { return $inner; }
                public function spawnDecrypt(BackupStream $inner, string $key): BackupStream { return $inner; }
                public function name(): string { return 'custom-driver'; }
                public function keyLength(): int { return 0; }
            };
        });

        $this->createS3Mock(1000, "anything");
        $verifier = $this->createVerifier($factory);

        $backup = new Backup([
            'path' => 'test.sql.gz.enc',
            'disk' => 's3',
            'size' => 1000,
            'encryption_driver' => 'custom-driver',
        ]);

        // Should not throw exception
        $verifier->verify($backup);
        $this->assertTrue(true); // Verification passed
    }

    public function test_custom_driver_with_interface_performs_check(): void
    {
        $factory = $this->app->make(EncryptionFactory::class);
        $factory->extend('custom-verifiable-driver', function () {
            return new class implements EncryptionDriver, VerifiesMagicBytes {
                public function spawn(BackupStream $inner, string $key): BackupStream { return $inner; }
                public function spawnDecrypt(BackupStream $inner, string $key): BackupStream { return $inner; }
                public function name(): string { return 'custom-verifiable-driver'; }
                public function keyLength(): int { return 0; }
                public function magicBytesLength(): int { return 3; }
                public function verifyMagicBytes(string $magic, Backup $backup): void {
                    if ($magic !== 'ABC') {
                        throw new \RuntimeException('Custom invalid magic!');
                    }
                }
            };
        });

        $this->createS3Mock(1000, "XYZ");
        $verifier = $this->createVerifier($factory);

        $backup = new Backup([
            'path' => 'test.sql.gz.enc',
            'disk' => 's3',
            'size' => 1000,
            'encryption_driver' => 'custom-verifiable-driver',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Custom invalid magic!');

        $verifier->verify($backup);
    }

    public function test_remote_size_returns_null_on_s3_not_found(): void
    {
        $s3 = $this->createMock(S3ClientInterface::class);
        $s3->method('__call')->willReturnCallback(function (string $name, array $args) {
            if ($name === 'headObject') {
                throw new S3Exception('Not Found', new \Aws\Command('HeadObject'), ['code' => 'NotFound']);
            }
            return [];
        });
        $this->app->instance(S3ClientInterface::class, $s3);
        config(['stream-backup.destination.driver' => 's3']);
        config(['filesystems.disks.s3.bucket' => 'test-bucket']);

        $verifier = $this->createVerifier();
        $backup = new Backup(['path' => 'missing.sql.gz', 'disk' => 's3']);

        self::assertNull($verifier->remoteSize($backup));
    }

    public function test_remote_size_returns_the_object_size_when_it_exists(): void
    {
        $this->createS3Mock(1234, "\x1f\x8b");
        $verifier = $this->createVerifier();

        $backup = new Backup(['path' => 'present.sql.gz', 'disk' => 's3']);

        self::assertSame(1234, $verifier->remoteSize($backup));
    }

    public function test_remote_size_rethrows_non_not_found_s3_errors(): void
    {
        $s3 = $this->createMock(S3ClientInterface::class);
        $s3->method('__call')->willReturnCallback(function (string $name, array $args) {
            if ($name === 'headObject') {
                throw new S3Exception('Access Denied', new \Aws\Command('HeadObject'), ['code' => 'AccessDenied']);
            }
            return [];
        });
        $this->app->instance(S3ClientInterface::class, $s3);
        config(['stream-backup.destination.driver' => 's3']);
        config(['filesystems.disks.s3.bucket' => 'test-bucket']);

        $verifier = $this->createVerifier();
        $backup = new Backup(['path' => 'forbidden.sql.gz', 'disk' => 's3']);

        $this->expectException(S3Exception::class);
        $verifier->remoteSize($backup);
    }

    public function test_remote_size_returns_null_for_missing_local_file(): void
    {
        $root = sys_get_temp_dir() . '/sbr_verifier_local_' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);

        config(['stream-backup.destination.driver' => 'local']);
        config(['stream-backup.default_disk' => 'local_test']);
        config(['filesystems.disks.local_test.root' => $root]);

        $verifier = $this->createVerifier();
        $backup = new Backup(['path' => 'nope.sql.gz', 'disk' => 'local_test']);

        self::assertNull($verifier->remoteSize($backup));

        @rmdir($root);
    }

    public function test_remote_size_returns_size_for_existing_local_file(): void
    {
        $root = sys_get_temp_dir() . '/sbr_verifier_local_' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root . '/present.sql.gz', str_repeat('a', 42));

        config(['stream-backup.destination.driver' => 'local']);
        config(['stream-backup.default_disk' => 'local_test']);
        config(['filesystems.disks.local_test.root' => $root]);

        $verifier = $this->createVerifier();
        $backup = new Backup(['path' => 'present.sql.gz', 'disk' => 'local_test']);

        self::assertSame(42, $verifier->remoteSize($backup));

        @unlink($root . '/present.sql.gz');
        @rmdir($root);
    }

    public function test_full_checksum_verification_is_skipped_by_default(): void
    {
        $content = "\x1f\x8b" . str_repeat('X', 2000);
        $size    = strlen($content);
        $calls   = [];

        $this->createS3MockWithFullChecksum($size, substr($content, 0, 2), $content, null, $calls);
        config(['stream-backup.full_checksum_verification' => false]);

        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path'              => 'test.sql.gz',
            'disk'              => 's3',
            'size'              => $size,
            'checksum'          => str_repeat('0', 64), // deliberately wrong — must never be checked
            'encryption_driver' => null,
        ]);

        $verifier->verify($backup);

        self::assertArrayNotHasKey('checksumHead', $calls);
        self::assertArrayNotHasKey('fullDownload', $calls);
    }

    public function test_full_checksum_verification_is_skipped_when_no_checksum_was_recorded(): void
    {
        $content = "\x1f\x8b" . str_repeat('X', 2000);
        $size    = strlen($content);
        $calls   = [];

        $this->createS3MockWithFullChecksum($size, substr($content, 0, 2), $content, null, $calls);
        config(['stream-backup.full_checksum_verification' => true]);

        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path'              => 'test.sql.gz',
            'disk'              => 's3',
            'size'              => $size,
            'checksum'          => null,
            'encryption_driver' => null,
        ]);

        $verifier->verify($backup);

        self::assertArrayNotHasKey('checksumHead', $calls);
        self::assertArrayNotHasKey('fullDownload', $calls);
    }

    public function test_full_checksum_verification_passes_via_streaming_download_when_checksums_match(): void
    {
        $content = "\x1f\x8b" . str_repeat('X', 2000);
        $size    = strlen($content);
        $calls   = [];

        // No ChecksumMode response configured: simulates an S3-compatible
        // provider that doesn't return a server-side checksum.
        $this->createS3MockWithFullChecksum($size, substr($content, 0, 2), $content, null, $calls);
        config(['stream-backup.full_checksum_verification' => true]);

        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path'              => 'test.sql.gz',
            'disk'              => 's3',
            'size'              => $size,
            'checksum'          => hash('sha256', $content),
            'encryption_driver' => null,
        ]);

        $verifier->verify($backup);

        self::assertSame(1, $calls['fullDownload'] ?? 0);
    }

    public function test_full_checksum_verification_throws_on_mismatch_via_streaming_download(): void
    {
        $content = "\x1f\x8b" . str_repeat('X', 2000);
        $size    = strlen($content);
        $calls   = [];

        $this->createS3MockWithFullChecksum($size, substr($content, 0, 2), $content, null, $calls);
        config(['stream-backup.full_checksum_verification' => true]);

        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path'              => 'test.sql.gz',
            'disk'              => 's3',
            'size'              => $size,
            'checksum'          => str_repeat('0', 64), // does not match $content's real digest
            'encryption_driver' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Backup checksum mismatch');

        $verifier->verify($backup);
    }

    public function test_full_checksum_verification_uses_s3_server_side_checksum_when_available(): void
    {
        $content  = "\x1f\x8b" . str_repeat('X', 2000);
        $size     = strlen($content);
        $checksum = hash('sha256', $content);
        $calls    = [];

        $this->createS3MockWithFullChecksum($size, substr($content, 0, 2), $content, [
            'ChecksumType'   => 'FULL_OBJECT',
            'ChecksumSHA256' => base64_encode(hex2bin($checksum)),
        ], $calls);
        config(['stream-backup.full_checksum_verification' => true]);

        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path'              => 'test.sql.gz',
            'disk'              => 's3',
            'size'              => $size,
            'checksum'          => $checksum,
            'encryption_driver' => null,
        ]);

        $verifier->verify($backup);

        self::assertSame(1, $calls['checksumHead'] ?? 0);
        self::assertArrayNotHasKey('fullDownload', $calls);
    }

    public function test_full_checksum_verification_throws_when_s3_server_side_checksum_mismatches(): void
    {
        $content = "\x1f\x8b" . str_repeat('X', 2000);
        $size    = strlen($content);
        $calls   = [];

        $this->createS3MockWithFullChecksum($size, substr($content, 0, 2), $content, [
            'ChecksumType'   => 'FULL_OBJECT',
            'ChecksumSHA256' => base64_encode(random_bytes(32)),
        ], $calls);
        config(['stream-backup.full_checksum_verification' => true]);

        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path'              => 'test.sql.gz',
            'disk'              => 's3',
            'size'              => $size,
            'checksum'          => hash('sha256', $content),
            'encryption_driver' => null,
        ]);

        try {
            $verifier->verify($backup);
            self::fail('Expected a RuntimeException for the checksum mismatch.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Backup checksum mismatch', $e->getMessage());
        }

        // The whole point of the server-side fast path: no full download
        // should have been attempted even on mismatch.
        self::assertArrayNotHasKey('fullDownload', $calls);
    }

    public function test_full_checksum_verification_falls_back_to_streaming_when_s3_checksum_is_composite(): void
    {
        $content = "\x1f\x8b" . str_repeat('X', 2000);
        $size    = strlen($content);
        $calls   = [];

        // COMPOSITE is the default S3 multipart checksum type: a hash of
        // the parts' checksums, not of the object's bytes, so it must never
        // be compared directly against the whole-stream SHA-256.
        $this->createS3MockWithFullChecksum($size, substr($content, 0, 2), $content, [
            'ChecksumType'   => 'COMPOSITE',
            'ChecksumSHA256' => base64_encode(random_bytes(32)),
        ], $calls);
        config(['stream-backup.full_checksum_verification' => true]);

        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path'              => 'test.sql.gz',
            'disk'              => 's3',
            'size'              => $size,
            'checksum'          => hash('sha256', $content),
            'encryption_driver' => null,
        ]);

        $verifier->verify($backup);

        self::assertSame(1, $calls['checksumHead'] ?? 0);
        self::assertSame(1, $calls['fullDownload'] ?? 0);
    }

    public function test_full_checksum_verification_passes_for_local_driver_when_checksums_match(): void
    {
        $dir = $this->makeLocalDiskFixture($content = "\x1f\x8b" . str_repeat('Y', 3000));

        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path'              => 'test.sql.gz',
            'disk'              => 'local_test',
            'size'              => strlen($content),
            'checksum'          => hash('sha256', $content),
            'encryption_driver' => null,
        ]);

        try {
            $verifier->verify($backup);
            $this->assertTrue(true); // verification passed
        } finally {
            $this->cleanupLocalDiskFixture($dir);
        }
    }

    public function test_full_checksum_verification_throws_for_local_driver_on_mismatch(): void
    {
        $dir = $this->makeLocalDiskFixture($content = "\x1f\x8b" . str_repeat('Y', 3000));

        $verifier = $this->createVerifier();

        $backup = new Backup([
            'path'              => 'test.sql.gz',
            'disk'              => 'local_test',
            'size'              => strlen($content),
            'checksum'          => str_repeat('a', 64),
            'encryption_driver' => null,
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Backup checksum mismatch');

            $verifier->verify($backup);
        } finally {
            $this->cleanupLocalDiskFixture($dir);
        }
    }

    /**
     * Writes $content to a fresh temp directory and points the 'local'
     * destination/default disk at it, with full checksum verification
     * enabled — everything BackupVerifier's 'local' branch needs.
     */
    private function makeLocalDiskFixture(string $content): string
    {
        $dir = sys_get_temp_dir() . '/sbr_local_verifier_test_' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/test.sql.gz', $content);

        config(['stream-backup.destination.driver' => 'local']);
        config(['stream-backup.default_disk' => 'local_test']);
        config(['filesystems.disks.local_test.root' => $dir]);
        config(['stream-backup.full_checksum_verification' => true]);

        return $dir;
    }

    private function cleanupLocalDiskFixture(string $dir): void
    {
        @unlink($dir . '/test.sql.gz');
        @rmdir($dir);
    }
}
