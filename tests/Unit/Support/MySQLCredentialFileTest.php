<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Support;

use Ahmednour\StreamBackup\DTOs\DatabaseCredentials;
use Ahmednour\StreamBackup\Support\MySQLCredentialFile;
use PHPUnit\Framework\TestCase;

final class MySQLCredentialFileTest extends TestCase
{
    private function credentials(): DatabaseCredentials
    {
        return new DatabaseCredentials(
            host:     '127.0.0.1',
            port:     3306,
            database: 'test_db',
            username: 'root',
            password: 's3cret',
        );
    }

    public function test_write_creates_a_0600_file_with_expected_contents(): void
    {
        $file = new MySQLCredentialFile();
        $path = $file->write($this->credentials());

        self::assertFileExists($path);
        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));

        $contents = file_get_contents($path);
        self::assertStringContainsString('host=127.0.0.1', $contents);
        self::assertStringContainsString('port=3306', $contents);
        self::assertStringContainsString('user=root', $contents);
        self::assertStringContainsString('password=s3cret', $contents);

        $file->delete();
    }

    public function test_delete_removes_the_file(): void
    {
        $file = new MySQLCredentialFile();
        $path = $file->write($this->credentials());

        self::assertFileExists($path);

        $file->delete();

        self::assertFileDoesNotExist($path);
    }

    public function test_delete_is_safe_to_call_more_than_once(): void
    {
        $file = new MySQLCredentialFile();
        $path = $file->write($this->credentials());

        $file->delete();
        $file->delete();

        self::assertFileDoesNotExist($path);
    }

    public function test_delete_without_a_prior_write_is_a_noop(): void
    {
        $file = new MySQLCredentialFile();

        $file->delete();

        self::assertNull($file->path());
    }

    public function test_writing_again_tracks_the_new_path_for_deletion(): void
    {
        $file  = new MySQLCredentialFile();
        $first = $file->write($this->credentials());
        $file->delete();

        $second = $file->write($this->credentials());

        self::assertNotSame($first, $second);
        self::assertFileExists($second);

        $file->delete();

        self::assertFileDoesNotExist($second);
    }
}
