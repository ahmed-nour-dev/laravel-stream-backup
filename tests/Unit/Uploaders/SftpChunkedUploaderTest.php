<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Uploaders;

use Ahmednour\StreamBackup\DTOs\BackupMetadata;
use Ahmednour\StreamBackup\Uploaders\SftpChunkedUploader;
use Carbon\CarbonImmutable;
use phpseclib3\Net\SFTP;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SftpChunkedUploaderTest extends TestCase
{
    private function metadata(string $path = 'db/2026/backup.sql.gz'): BackupMetadata
    {
        return new BackupMetadata(
            backupId:  1,
            tenantId:  null,
            bucket:    '',
            path:      $path,
            disk:      'sftp',
            startedAt: CarbonImmutable::now(),
        );
    }

    /** @return SFTP&MockObject */
    private function mockSftp(): SFTP
    {
        return $this->createMock(SFTP::class);
    }

    public function test_initiate_chmods_the_uploaded_file_to_the_configured_file_mode(): void
    {
        $sftp = $this->mockSftp();
        $sftp->method('is_dir')->willReturn(true);
        $sftp->method('put')->willReturn(true);
        $sftp->expects(self::once())->method('chmod')->with(0640, 'db/2026/backup.sql.gz');

        $uploader = new SftpChunkedUploader($sftp, '', 0640, 0750);
        $uploader->initiate($this->metadata());
    }

    public function test_initiate_creates_a_missing_directory_with_the_configured_directory_mode(): void
    {
        $sftp = $this->mockSftp();
        $sftp->method('is_dir')->willReturn(false);
        $sftp->expects(self::once())->method('mkdir')->with('db/2026', 0750, true);
        $sftp->method('put')->willReturn(true);

        $uploader = new SftpChunkedUploader($sftp, '', 0640, 0750);
        $uploader->initiate($this->metadata());
    }

    public function test_initiate_skips_mkdir_when_the_directory_already_exists(): void
    {
        $sftp = $this->mockSftp();
        $sftp->method('is_dir')->willReturn(true);
        $sftp->expects(self::never())->method('mkdir');
        $sftp->method('put')->willReturn(true);

        $uploader = new SftpChunkedUploader($sftp, '', 0640, 0750);
        $uploader->initiate($this->metadata());
    }

    public function test_defaults_are_least_privilege_owner_only_modes(): void
    {
        $sftp = $this->mockSftp();
        $sftp->method('is_dir')->willReturn(false);
        $sftp->expects(self::once())->method('mkdir')->with(self::anything(), 0700, true);
        $sftp->method('put')->willReturn(true);
        $sftp->expects(self::once())->method('chmod')->with(0600, self::anything());

        $uploader = new SftpChunkedUploader($sftp, '');
        $uploader->initiate($this->metadata());
    }

    public function test_root_is_prefixed_onto_the_resolved_and_directory_paths(): void
    {
        $sftp = $this->mockSftp();
        $sftp->method('is_dir')->willReturn(false);
        $sftp->expects(self::once())->method('mkdir')->with('backups/db/2026', 0700, true);
        $sftp->method('put')->willReturn(true);
        $sftp->expects(self::once())->method('chmod')->with(0600, 'backups/db/2026/backup.sql.gz');

        $uploader = new SftpChunkedUploader($sftp, 'backups');
        $uploader->initiate($this->metadata());
    }
}
