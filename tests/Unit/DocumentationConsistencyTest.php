<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit;

use Ahmednour\StreamBackup\Commands\RestoreBackupCommand;
use Ahmednour\StreamBackup\Tests\TestCase;

/**
 * Guards against the documentation drifting back into overstating what the
 * package actually does:
 *
 * - Restore supports every database backup supports (MySQL, PostgreSQL
 *   and SQLite), but only into a connection of the same engine.
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
            '/\|\s*PostgreSQL\s*\|\s*✅ Yes\s*\|\s*✅ Yes/',
            $readme,
            'README support matrix must mark PostgreSQL restore as supported.'
        );

        self::assertMatchesRegularExpression(
            '/\|\s*SQLite\s*\|\s*✅ Yes\s*\|\s*✅ Yes/',
            $readme,
            'README support matrix must mark SQLite restore as supported.'
        );

        self::assertMatchesRegularExpression(
            '/\|\s*MySQL\s*\|\s*✅ Yes\s*\|\s*✅ Yes/',
            $readme,
            'README support matrix must mark MySQL restore as supported.'
        );
    }

    public function test_readme_restore_section_states_the_same_engine_rule(): void
    {
        $readme = $this->readme();

        self::assertStringContainsString(
            'Same engine only',
            $readme,
            'The Restore usage section must call out that restore needs the same engine.'
        );
    }

    public function test_restore_command_description_lists_the_engines(): void
    {
        $command = new RestoreBackupCommand();

        self::assertStringContainsString('MySQL, PostgreSQL or SQLite', $command->getDescription());
        self::assertStringContainsString('same database engine', $command->getDescription());
    }

    public function test_config_restore_section_documents_the_supported_engines(): void
    {
        $configSource = (string) file_get_contents(dirname(__DIR__, 2) . '/config/stream-backup.php');

        self::assertStringContainsString(
            'Restore supports MySQL (mysqldump), PostgreSQL (plain-format',
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
