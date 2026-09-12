<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit;

use Ahmednour\StreamBackup\Commands\RestoreBackupCommand;
use Ahmednour\StreamBackup\Tests\TestCase;

/**
 * Guards against the documentation drifting back into overstating what the
 * package actually does:
 *
 * - Restore is not supported for every database backup supports. Restore
 *   currently only understands mysqldump output (see SqlDumpParser);
 *   PostgreSQL and SQLite are backup-only until the restore pipeline is
 *   extended.
 * - The package does not use literally zero local disk. It guarantees no
 *   database-sized temporary files; bounded php://temp buffers may still
 *   spill a small, fixed amount of data to disk (see SqlDumpParser and
 *   StreamPipeline).
 */
final class DocumentationConsistencyTest extends TestCase
{
    private function readme(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
    }

    public function test_readme_documents_the_backup_vs_restore_support_matrix(): void
    {
        $readme = $this->readme();

        self::assertMatchesRegularExpression(
            '/\|\s*PostgreSQL\s*\|\s*✅ Yes\s*\|\s*❌ Not currently supported/',
            $readme,
            'README support matrix must mark PostgreSQL restore as not currently supported.'
        );

        self::assertMatchesRegularExpression(
            '/\|\s*SQLite\s*\|\s*✅ Yes\s*\|\s*❌ Not currently supported/',
            $readme,
            'README support matrix must mark SQLite restore as not currently supported.'
        );

        self::assertMatchesRegularExpression(
            '/\|\s*MySQL\s*\|\s*✅ Yes\s*\|\s*✅ Yes/',
            $readme,
            'README support matrix must mark MySQL restore as supported.'
        );
    }

    public function test_readme_restore_section_states_the_mysql_limitation(): void
    {
        $readme = $this->readme();

        self::assertStringContainsString(
            'MySQL only',
            $readme,
            'The Restore usage section must call out that restore is MySQL-only.'
        );
    }

    public function test_restore_command_description_states_the_mysql_limitation(): void
    {
        $command = new RestoreBackupCommand();

        self::assertStringContainsString('MySQL', $command->getDescription());
        self::assertStringContainsString('not currently supported', $command->getDescription());
    }

    public function test_config_restore_section_documents_the_mysql_limitation(): void
    {
        $configSource = (string) file_get_contents(dirname(__DIR__, 2) . '/config/stream-backup.php');

        self::assertStringContainsString(
            'Restore currently supports MySQL backups only',
            $configSource
        );
    }

    public function test_readme_does_not_claim_literal_zero_disk_usage(): void
    {
        $readme = $this->readme();

        self::assertStringNotContainsString(
            '(Zero bytes)',
            $readme,
            'README must not claim literal zero-byte local disk usage: php://temp buffers can spill to disk.'
        );

        self::assertStringNotContainsString(
            'Nothing is ever buffered to disk',
            $readme,
            'README must not claim nothing is ever buffered to disk: the multipart part buffer spills past 2 MB.'
        );
    }

    public function test_readme_documents_temporary_disk_usage_thresholds(): void
    {
        $readme = $this->readme();

        self::assertStringContainsString(
            '### Temporary Disk Usage',
            $readme,
            'README must document the bounded php://temp buffer thresholds under a dedicated section.'
        );

        self::assertStringContainsString(
            'no database-sized temporary files',
            $readme,
            'README must state the accurate guarantee: no database-sized temp files, not zero disk usage.'
        );

        self::assertStringContainsString(
            'SqlDumpParser::TEMP_MAX_MEMORY',
            $readme,
            'README must document the restore per-table buffer threshold constant.'
        );
    }

    public function test_config_documents_the_part_buffer_disk_spill_behaviour(): void
    {
        $configSource = (string) file_get_contents(dirname(__DIR__, 2) . '/config/stream-backup.php');

        self::assertStringContainsString(
            'php://temp',
            $configSource,
            'config/stream-backup.php must document that the multipart part buffer can spill to disk.'
        );

        self::assertStringContainsString(
            'not literally zero bytes',
            $configSource
        );
    }
}
