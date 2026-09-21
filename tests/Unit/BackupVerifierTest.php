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
}
