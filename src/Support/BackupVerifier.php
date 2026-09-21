<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\Contracts\VerifiesMagicBytes;
use Ahmednour\StreamBackup\Encryption\EncryptionFactory;
use Ahmednour\StreamBackup\Models\Backup;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use phpseclib3\Net\SFTP;

/**
 * Post-upload sanity check: the remote object exists, its size matches what we
 * streamed, and the first two bytes are the gzip magic number (0x1f 0x8b).
 * Catches silent backup corruption before it becomes a restore-time disaster.
 */
final class BackupVerifier
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly EncryptionFactory $encryptionFactory,
    ) {
    }

    public function verify(Backup $backup): void
    {
        $driverName = $this->config->get('stream-backup.destination.driver', 's3');

        $remoteSize = 0;
        $magic = '';
        
        $encryptionDriver = $this->encryptionFactory->make($backup->encryption_driver);
        $length = $encryptionDriver instanceof VerifiesMagicBytes 
            ? $encryptionDriver->magicBytesLength() 
            : 0;

        if ($driverName === 's3') {
            $s3 = $this->container->make(S3ClientInterface::class);
            $bucket = $this->bucket($backup);

            $head = $s3->headObject([
                'Bucket' => $bucket,
                'Key'    => $backup->path,
            ]);

            $remoteSize = (int) ($head['ContentLength'] ?? 0);

            if ($length > 0) {
                $range = $s3->getObject([
                    'Bucket' => $bucket,
                    'Key'    => $backup->path,
                    'Range'  => 'bytes=0-' . ($length - 1),
                ]);
                $magic = (string) $range['Body'];
            }
        } elseif ($driverName === 'sftp') {
            $sftp       = $this->sftpConnect();
            $remotePath = $this->sftpPath($backup);

            $remoteSize = (int) $sftp->filesize($remotePath);

            if ($length > 0) {
                $magic = (string) $sftp->get($remotePath, false, 0, $length);
            }
        } elseif ($driverName === 'local') {
            $localPath = $this->localPath($backup);

            if (! file_exists($localPath)) {
                throw new \RuntimeException("Local backup file not found for verification: {$localPath}");
            }

            $remoteSize = (int) filesize($localPath);

            if ($length > 0) {
                $fp = fopen($localPath, 'rb');
                if ($fp !== false) {
                    $magic = (string) fread($fp, $length);
                    fclose($fp);
                }
            }
        } else {
            throw new \RuntimeException("Verification not supported for driver: {$driverName}");
        }

        if ($backup->size !== null && $remoteSize !== (int) $backup->size) {
            throw new \RuntimeException(sprintf(
                'Backup size mismatch for %s: local=%d remote=%d',
                $backup->path,
                $backup->size,
                $remoteSize,
            ));
        }

        if ($length > 0 && $encryptionDriver instanceof VerifiesMagicBytes) {
            $encryptionDriver->verifyMagicBytes($magic, $backup);
        }
    }

    /**
     * Cheap existence probe used by BackupReconciler to tell "no object was
     * ever written" (null) apart from "an object exists, but the DB row
     * never got finalized" (its size). Unlike verify(), this never throws
     * on a missing object — a 404 / missing file is a legitimate, expected
     * outcome here, not an error.
     */
    public function remoteSize(Backup $backup): ?int
    {
        $driverName = $this->config->get('stream-backup.destination.driver', 's3');

        if ($driverName === 's3') {
            $s3 = $this->container->make(S3ClientInterface::class);

            try {
                $head = $s3->headObject([
                    'Bucket' => $this->bucket($backup),
                    'Key'    => $backup->path,
                ]);
            } catch (S3Exception $e) {
                if ($e->getStatusCode() === 404 || $e->getAwsErrorCode() === 'NotFound') {
                    return null;
                }
                throw $e;
            }

            return (int) ($head['ContentLength'] ?? 0);
        }

        if ($driverName === 'sftp') {
            $sftp       = $this->sftpConnect();
            $remotePath = $this->sftpPath($backup);

            if (! $sftp->file_exists($remotePath)) {
                return null;
            }

            $size = $sftp->filesize($remotePath);

            return $size === false ? null : (int) $size;
        }

        if ($driverName === 'local') {
            $localPath = $this->localPath($backup);

            return file_exists($localPath) ? (int) filesize($localPath) : null;
        }

        throw new \RuntimeException("Verification not supported for driver: {$driverName}");
    }

    private function bucket(Backup $backup): string
    {
        $configured = $this->config->get("filesystems.disks.{$backup->disk}.bucket");
        return is_string($configured) && $configured !== '' ? $configured : $backup->disk;
    }

    private function sftpConnect(): SFTP
    {
        if (! class_exists(SFTP::class)) {
            throw new \RuntimeException("SFTP verification requires phpseclib/phpseclib.");
        }

        $cfg  = (array) $this->config->get('stream-backup.destination', []);
        $sftp = new SFTP($cfg['host'], (int) ($cfg['port'] ?? 22));

        $authed = isset($cfg['private_key'])
            ? $sftp->login(
                $cfg['username'],
                \phpseclib3\Crypt\PublicKeyLoader::load(
                    file_get_contents($cfg['private_key']),
                    $cfg['passphrase'] ?? false
                )
            )
            : $sftp->login($cfg['username'], $cfg['password'] ?? '');

        if (! $authed) {
            throw new \RuntimeException("SFTP verification failed: Authentication failed.");
        }

        return $sftp;
    }

    private function sftpPath(Backup $backup): string
    {
        $cfg  = (array) $this->config->get('stream-backup.destination', []);
        $root = (string) ($cfg['root'] ?? '');
        $path = ltrim((string) $backup->path, '/');

        return $root !== '' ? rtrim($root, '/') . '/' . $path : $path;
    }

    private function localPath(Backup $backup): string
    {
        $diskName = (string) $this->config->get('stream-backup.default_disk', 'local');
        $root     = (string) $this->config->get("filesystems.disks.{$diskName}.root", storage_path('app/backups'));
        $path     = ltrim((string) $backup->path, '/');

        return $root !== '' ? rtrim($root, '/') . '/' . $path : $path;
    }
}
