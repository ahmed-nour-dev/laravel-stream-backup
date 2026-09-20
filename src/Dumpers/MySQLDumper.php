<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Dumpers;

use Ahmednour\StreamBackup\DTOs\BackupContext;
use Ahmednour\StreamBackup\DTOs\DatabaseCredentials;
use Ahmednour\StreamBackup\Exceptions\PipelineException;
use Ahmednour\StreamBackup\Support\BinaryLocator;
use Ahmednour\StreamBackup\Support\MySQLCredentialFile;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * MySQL dump driver using mysqldump.
 *
 * Credentials are written to a temporary file passed via
 * --defaults-extra-file so the password never appears on the
 * command line (which would leak via `ps aux`). The file is deleted via
 * releaseResources() as soon as the mysqldump process it was written for
 * has ended — see AbstractProcessDumper::dump() and MySQLCredentialFile.
 *
 * Extends AbstractProcessDumper via the Template Method pattern —
 * all proc_open boilerplate lives in the base class.
 */
final class MySQLDumper extends AbstractProcessDumper
{
    public function __construct(
        BinaryLocator $locator,
        Config $config,
        private readonly MySQLCredentialFile $credentialFile,
    ) {
        parent::__construct($locator, $config);
    }

    protected function buildCommand(BackupContext $context): array
    {
        $credentials = $this->resolveCredentials($context);
        $cnfPath     = $this->credentialFile->write($credentials);

        // Backward compat: check the old key first, then the new per-driver key.
        $binary = $this->locator->locate(
            $this->config->get(
                'stream-backup.dump.drivers.mysql.binary',
                $this->config->get('stream-backup.dump.binary', 'mysqldump'),
            )
        );

        return array_merge(
            [
                $binary,
                '--defaults-extra-file=' . $cnfPath,
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
            ],
            (array) $this->config->get('stream-backup.dump.extra_flags', []),
            $context->extraDumpFlags,
            [$credentials->database],
        );
    }

    public function name(): string
    {
        return 'mysqldump';
    }

    protected function releaseResources(): void
    {
        $this->credentialFile->delete();
    }

    private function resolveCredentials(BackupContext $context): DatabaseCredentials
    {
        $key = "database.connections.{$context->connectionName}";
        $conn = (array) $this->config->get($key, []);

        if ($conn === []) {
            throw new PipelineException(sprintf(
                "Database connection '%s' is not configured.",
                $context->connectionName,
            ));
        }

        return new DatabaseCredentials(
            host:     (string) ($conn['host']     ?? '127.0.0.1'),
            port:     (int)    ($conn['port']     ?? 3306),
            database: $context->databaseName,
            username: (string) ($conn['username'] ?? ''),
            password: (string) ($conn['password'] ?? ''),
        );
    }
}
