<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Encryption;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Contracts\EncryptionDriver;
use Ahmednour\StreamBackup\Contracts\VerifiesMagicBytes;
use Ahmednour\StreamBackup\Exceptions\InvalidConfigException;
use Ahmednour\StreamBackup\Models\Backup;
use Ahmednour\StreamBackup\Streams\OpenSslDecryptionStream;
use Ahmednour\StreamBackup\Streams\OpenSslEncryptionStream;

/**
 * AES-256-GCM stream encryption driver via PHP's ext-openssl.
 *
 * The driver itself is stateless — all per-backup mutable state (IV,
 * chunk counter, key) lives inside OpenSslEncryptionStream so multiple
 * concurrent backups never interfere.
 *
 * Wire format v2 (handled by OpenSslEncryptionStream):
 *   Header:  [0x02 (version byte)][12-byte random base nonce]
 *   Chunks:  [4-byte BE ciphertext_len][16-byte GCM tag][ciphertext]
 *   Final:   [4 bytes: 0x00000000][16-byte GCM tag over empty plaintext]
 *   AAD:     version . base nonce . final flag (0x00 data, 0x01 final)
 *
 * Backups written in wire format v1 (version byte 0x01, no AAD, bare
 * 0x00000000 end marker) still decrypt.
 *
 * Per-chunk nonce derivation:
 *   nonce_i = base_nonce XOR pack('N', i) in the last 4 bytes.
 *   Supports up to 2^32 chunks (~256 TB at 64 KB chunks).
 *
 * Requirements: ext-openssl (universally available on PHP).
 *
 * Generate a key:
 *   php -r "echo base64_encode(random_bytes(32));"
 */
final class OpenSslAes256GcmDriver implements EncryptionDriver, VerifiesMagicBytes
{
    public function spawn(BackupStream $inner, string $key): BackupStream
    {
        $this->assertOpenSsl();
        $this->assertKeyLength($key);

        return new OpenSslEncryptionStream($inner, $key);
    }

    public function spawnDecrypt(BackupStream $inner, string $key): BackupStream
    {
        $this->assertOpenSsl();
        $this->assertKeyLength($key);

        return new OpenSslDecryptionStream($inner, $key);
    }

    public function name(): string
    {
        return 'openssl-aes-256-gcm';
    }

    public function keyLength(): int
    {
        return 32; // 256 bits
    }

    public function magicBytesLength(): int
    {
        return 1;
    }

    public function verifyMagicBytes(string $magic, Backup $backup): void
    {
        if (strlen($magic) < 1 || ($magic[0] !== "\x01" && $magic[0] !== "\x02")) {
            throw new \RuntimeException(sprintf(
                'Backup %s is encrypted with openssl-aes-256-gcm but does not start with the expected version byte; the object is likely corrupt.',
                $backup->path,
            ));
        }
    }

    private function assertOpenSsl(): void
    {
        if (! extension_loaded('openssl')) {
            throw new InvalidConfigException(
                "Encryption driver 'openssl-aes-256-gcm' requires ext-openssl, which is not loaded."
            );
        }
    }

    private function assertKeyLength(string $key): void
    {
        if (strlen($key) !== $this->keyLength()) {
            throw new InvalidConfigException(sprintf(
                'Encryption driver "%s" requires a %d-byte key; got %d bytes. ' .
                'Generate a valid key: php -r "echo base64_encode(random_bytes(%d));"',
                $this->name(),
                $this->keyLength(),
                strlen($key),
                $this->keyLength(),
            ));
        }
    }
}
