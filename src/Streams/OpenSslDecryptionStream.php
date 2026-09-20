<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Streams;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Exceptions\InvalidBackupException;

/**
 * AES-256-GCM streaming decryptor — BackupStream decorator.
 *
 * Inverse of OpenSslEncryptionStream. Reads the wire format produced
 * during backup and decrypts chunk-by-chunk using PHP's openssl_decrypt()
 * in AES-256-GCM mode.
 *
 * Wire format (produced by OpenSslEncryptionStream):
 *   [1 byte:  version = 0x01]
 *   [12 bytes: random base nonce]
 *   // for each encrypted chunk:
 *   [4 bytes BE: ciphertext length (0 = EOF)]
 *   [16 bytes: GCM authentication tag]
 *   [N bytes: ciphertext]
 *   // EOF marker:
 *   [4 bytes: 0x00000000]
 *
 * Memory per iteration: one ciphertext chunk + one same-size plaintext;
 * both are freed at the end of each read() call.
 */
final class OpenSslDecryptionStream implements BackupStream
{
    private const ALGORITHM = 'aes-256-gcm';
    private const VERSION   = "\x01";
    private const NONCE_LEN = 12;
    private const TAG_LEN   = 16;

    private string $baseNonce = '';
    private int    $chunkIndex = 0;

    /** Raw bytes buffered from the inner stream, not yet consumed as frames. */
    private string $readBuffer = '';

    /** Decrypted plaintext buffered from a previous frame, not yet returned. */
    private string $pending = '';

    private bool $headerRead = false;
    private bool $eof        = false;
    private bool $closed     = false;

    public function __construct(
        private readonly BackupStream $inner,
        private string $key,
    ) {
    }

    public function read(int $length = 65536): ?string
    {
        if ($this->eof) {
            return null;
        }

        // Return any buffered plaintext first.
        if ($this->pending !== '') {
            $out           = substr($this->pending, 0, $length);
            $this->pending = substr($this->pending, strlen($out));
            return $out;
        }

        // Read the header on first call.
        if (! $this->headerRead) {
            $header = $this->readExactly(1 + self::NONCE_LEN);

            if ($header === null) {
                return '';  // not enough data yet
            }

            if ($header[0] !== self::VERSION) {
                throw new InvalidBackupException(sprintf(
                    'Expected OpenSSL encryption version byte 0x01, got 0x%s.',
                    bin2hex($header[0]),
                ));
            }

            $this->baseNonce  = substr($header, 1, self::NONCE_LEN);
            $this->headerRead = true;
        }

        // Read the next frame: [4-byte len][16-byte tag][ciphertext]
        $lenBytes = $this->readExactly(4);

        if ($lenBytes === null) {
            return '';  // not enough data yet
        }

        /** @var array{N: int} $unpacked */
        $unpacked       = unpack('N', $lenBytes);
        $ciphertextLen  = $unpacked['N'] ?? $unpacked[1];

        // EOF marker: 4 zero bytes.
        if ($ciphertextLen === 0) {
            $this->eof = true;
            return null;
        }

        $tagAndCiphertext = $this->readExactly(self::TAG_LEN + $ciphertextLen);

        if ($tagAndCiphertext === null) {
            return '';  // not enough data yet
        }

        $tag        = substr($tagAndCiphertext, 0, self::TAG_LEN);
        $ciphertext = substr($tagAndCiphertext, self::TAG_LEN);

        $nonce    = $this->deriveNonce($this->chunkIndex++);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::ALGORITHM,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw new InvalidBackupException(
                'AES-256-GCM decryption failed at chunk ' . ($this->chunkIndex - 1)
                . ': authentication tag mismatch. The backup may be corrupt or the key is wrong.'
            );
        }

        // Buffer any excess beyond $length.
        if (strlen($plaintext) > $length) {
            $this->pending = substr($plaintext, $length);
            return substr($plaintext, 0, $length);
        }

        return $plaintext;
    }

    public function isEof(): bool
    {
        return $this->eof;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        // Wipe the key from PHP memory before any possible exception.
        if (function_exists('sodium_memzero')) {
            sodium_memzero($this->key);
        } else {
            $this->key = str_repeat("\x00", strlen($this->key));
        }

        $this->inner->close();
    }

    /**
     * Read exactly $needed bytes from the inner stream, buffering partial reads.
     *
     * Returns null if not enough data is available yet (non-blocking).
     *
     * A well-formed encrypted backup always terminates with the explicit
     * 4-byte EOF marker, which is consumed directly by read() and never
     * reaches this method. So if the inner stream ends before $needed bytes
     * are available — whether that's before the header, mid-frame, or right
     * after the last data frame with the EOF marker missing — the stream is
     * truncated or malformed, and we must fail loudly rather than silently
     * treating a short read as a legitimately finished restore.
     */
    private function readExactly(int $needed): ?string
    {
        while (strlen($this->readBuffer) < $needed) {
            $chunk = $this->inner->read($needed - strlen($this->readBuffer));

            if ($chunk === null) {
                throw new InvalidBackupException(
                    $this->readBuffer === ''
                        ? 'Encrypted backup stream ended unexpectedly: missing header or EOF marker.'
                        : 'Encrypted backup stream ended unexpectedly mid-frame.'
                );
            }

            if ($chunk === '') {
                // No data available yet — caller should retry via stream_select.
                return null;
            }

            $this->readBuffer .= $chunk;
        }

        $result           = substr($this->readBuffer, 0, $needed);
        $this->readBuffer = substr($this->readBuffer, $needed);
        return $result;
    }

    /**
     * Derive the per-chunk GCM nonce (same algorithm as OpenSslEncryptionStream).
     */
    private function deriveNonce(int $index): string
    {
        $suffix = substr($this->baseNonce, self::NONCE_LEN - 4) ^ pack('N', $index);
        return substr($this->baseNonce, 0, self::NONCE_LEN - 4) . $suffix;
    }
}
