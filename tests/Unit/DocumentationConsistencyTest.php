<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit;

use Ahmednour\StreamBackup\Commands\RestoreBackupCommand;
use Ahmednour\StreamBackup\Tests\TestCase;

/**
 * Guards against the documentation drifting back into implying that restore
 * is supported for every database backup supports. Restore currently only
 * understands mysqldump output (see SqlDumpParser); PostgreSQL and SQLite
 * are backup-only until the restore pipeline is extended.
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
}
