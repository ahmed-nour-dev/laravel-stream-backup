<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Support;

use Ahmednour\StreamBackup\Exceptions\InvalidConfigException;
use Ahmednour\StreamBackup\Support\SftpPermissionResolver;
use PHPUnit\Framework\TestCase;

final class SftpPermissionResolverTest extends TestCase
{
    public function test_defaults_to_least_privilege_private_modes_when_nothing_is_configured(): void
    {
        [$fileMode, $directoryMode] = SftpPermissionResolver::resolve([]);

        self::assertSame(0600, $fileMode);
        self::assertSame(0700, $directoryMode);
    }

    public function test_visibility_private_maps_to_owner_only_modes(): void
    {
        [$fileMode, $directoryMode] = SftpPermissionResolver::resolve([
            'visibility'           => 'private',
            'directory_visibility' => 'private',
        ]);

        self::assertSame(0600, $fileMode);
        self::assertSame(0700, $directoryMode);
    }

    public function test_visibility_public_maps_to_group_and_world_readable_modes(): void
    {
        [$fileMode, $directoryMode] = SftpPermissionResolver::resolve([
            'visibility'           => 'public',
            'directory_visibility' => 'public',
        ]);

        self::assertSame(0644, $fileMode);
        self::assertSame(0755, $directoryMode);
    }

    public function test_explicit_octal_string_modes_take_precedence_over_visibility(): void
    {
        [$fileMode, $directoryMode] = SftpPermissionResolver::resolve([
            'file_mode'            => '0640',
            'directory_mode'       => '0750',
            'visibility'           => 'public',
            'directory_visibility' => 'public',
        ]);

        self::assertSame(0640, $fileMode);
        self::assertSame(0750, $directoryMode);
    }

    public function test_explicit_int_modes_are_accepted_as_is(): void
    {
        [$fileMode, $directoryMode] = SftpPermissionResolver::resolve([
            'file_mode'      => 0640,
            'directory_mode' => 0750,
        ]);

        self::assertSame(0640, $fileMode);
        self::assertSame(0750, $directoryMode);
    }

    public function test_throws_on_invalid_visibility_value(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/invalid SFTP `visibility`/');

        SftpPermissionResolver::resolve(['visibility' => 'read-write']);
    }

    public function test_throws_on_invalid_directory_visibility_value(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/invalid SFTP `directory_visibility`/');

        SftpPermissionResolver::resolve(['directory_visibility' => 'everyone']);
    }

    public function test_throws_on_malformed_explicit_file_mode(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/`file_mode` must be a valid octal file mode/');

        SftpPermissionResolver::resolve(['file_mode' => '999']);
    }

    public function test_throws_on_malformed_explicit_directory_mode(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/`directory_mode` must be a valid octal file mode/');

        SftpPermissionResolver::resolve(['directory_mode' => 'not-a-mode']);
    }

    public function test_accepts_an_0o_prefixed_octal_string(): void
    {
        [$fileMode] = SftpPermissionResolver::resolve(['file_mode' => '0o640']);

        self::assertSame(0640, $fileMode);
    }
}
