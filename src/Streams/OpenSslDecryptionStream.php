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
 * Wire format v2 (produced by OpenSslEncryptionStream):
 *   [1 byte:  version = 0x02]
 *   [12 bytes: random base nonce]
 *   // for each encrypted chunk:
 *   [4 bytes BE: ciphertext length]
 *   [16 bytes: GCM authentication tag]
 *   [N bytes: ciphertext]
 *   // final frame:
 *   [4 bytes: 0x00000000]
 *   [16 bytes: GCM authentication tag over empty plaintext]
 *   AAD of every frame: version . base nonce . final flag (0x00 / 0x01)
 *
 * Wire format v1 (version byte 0x01) is still accepted: same frames
 * without AAD, ended by a bare 4-byte 0x00000000 marker. Its end of
 * stream is not authenticated, which is why v2 exists.
 *
 * In both versions, any byte after the end of the stream is rejected.
 *
 * Memory per iteration: one ciphertext chunk + one same-size plaintext;
 * both are freed at the end of each read() call.
 */
final class OpenSslDecryptionStream implements BackupStream
{
    private const ALGORITHM = 'aes-256-gcm';
    private const VERSION_1 = "\x01";
    private const VERSION_2 = "\x02";
    private const NONCE_LEN = 12;
    private const TAG_LEN   = 16;

    private const FLAG_DATA  = "\x00";
    private const FLAG_FINAL = "\x01";

    private string $version   = '';
    private string $baseNonce = '';
    private int    $chunkIndex = 0;

    /** Length of the frame being read, once its length field is consumed. */
    private ?int $frameLen = null;

    /** Raw bytes buffered from the inner stream, not yet consumed as frames. */
    private string $readBuffer = '';

    /** Decrypted plaintext buffered from a previous frame, not yet returned. */
    private string $pending = '';

    private bool $headerRead = false;

    /** True once the end of the stream was read; only inner EOF may follow. */
    private bool $ended      = false;

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

        if ($this->ended) {
            return $this->expectInnerEof();
        }

        // Read the header on first call.
        if (! $this->headerRead) {
            $header = $this->readExactly(1 + self::NONCE_LEN);

            if ($header === null) {
                return '';  // not enough data yet
            }

            if ($header[0] !== self::VERSION_1 && $header[0] !== self::VERSION_2) {
                throw new InvalidBackupException(sprintf(
                    'Expected OpenSSL encryption version byte 0x01 or 0x02, got 0x%s.',
                    bin2hex($header[0]),
                ));
            }

            $this->version    = $header[0];
            $this->baseNonce  = substr($header, 1, self::NONCE_LEN);
            $this->headerRead = true;
        }

        // Read the next frame: [4-byte len][16-byte tag][ciphertext]. The
        // length is kept across calls so a short read cannot lose it.
        if ($this->frameLen === null) {
            $lenBytes = $this->readExactly(4);

            if ($lenBytes === null) {
                return '';  // not enough data yet
            }

            /** @var array{N: int} $unpacked */
            $unpacked       = unpack('N', $lenBytes);
            $this->frameLen = $unpacked['N'] ?? $unpacked[1];
        }

        // v1 end of stream: a bare 4-byte zero length, not authenticated.
        if ($this->frameLen === 0 && $this->version === self::VERSION_1) {
            $this->ended = true;
            return $this->expectInnerEof();
        }

        $tagAndCiphertext = $this->readExactly(self::TAG_LEN + $this->frameLen);

        if ($tagAndCiphertext === null) {
            return '';  // not enough data yet
        }

        // v2 final frame: zero length, tag over empty plaintext.
        $final          = $this->frameLen === 0;
        $this->frameLen = null;

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
            $this->aad($final),
        );

        if ($plaintext === false) {
            throw new InvalidBackupException(
                'AES-256-GCM decryption failed at chunk ' . ($this->chunkIndex - 1)
                . ': authentication tag mismatch. The backup may be corrupt or the key is wrong.'
            );
        }

        if ($final) {
            $this->ended = true;
            return $this->expectInnerEof();
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
     * AAD for a frame: none in v1; in v2 the header plus the final flag.
     */
    private function aad(bool $final): string
    {
        if ($this->version === self::VERSION_1) {
            return '';
        }

        return $this->version . $this->baseNonce . ($final ? self::FLAG_FINAL : self::FLAG_DATA);
    }

    /**
     * After the end of the stream, the inner stream must be exhausted.
     *
     * Returns null once it is, '' while no data is available yet
     * (non-blocking), and throws on any trailing byte.
     */
    private function expectInnerEof(): ?string
    {
        $chunk = $this->readBuffer !== '' ? $this->readBuffer : $this->inner->read(1);

        if ($chunk === null) {
            $this->eof = true;
            return null;
        }

        if ($chunk === '') {
            return '';
        }

        throw new InvalidBackupException(
            'Encrypted backup stream has trailing bytes after the end of the stream.'
        );
    }

    /**
     * Read exactly $needed bytes from the inner stream, buffering partial reads.
     *
     * Returns null if not enough data is available yet (non-blocking).
     *
     * A well-formed encrypted backup always terminates with an explicit
     * end of stream (the v2 final frame, or the v1 zero-length marker), so
     * if the inner stream ends before $needed bytes are available —
     * whether that's before the header, mid-frame, or right after the last
     * data frame with the end of stream missing — the stream is
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
                        ? 'Encrypted backup stream ended unexpectedly: missing header or final frame.'
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
