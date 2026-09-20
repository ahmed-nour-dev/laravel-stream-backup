<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Streams;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Exceptions\InvalidBackupException;

/**
 * XChaCha20-Poly1305 streaming decryptor — BackupStream decorator.
 *
 * Inverse of SodiumEncryptionStream. Uses libsodium's secretstream pull API
 * which manages nonce advancement automatically via an internal state machine.
 *
 * Wire format (produced by SodiumEncryptionStream):
 *   [1 byte:   version = 0x02]
 *   [24 bytes: secretstream push header]
 *   // for each encrypted chunk:
 *   [4 bytes BE: ciphertext length  (= plaintext_len + ABYTES)]
 *   [N bytes: sodium ciphertext]
 *   // last chunk uses TAG_FINAL (embedded in ciphertext)
 *
 * Memory per iteration: one ciphertext chunk + one same-size plaintext;
 * both are freed at the end of each read() call.
 */
final class SodiumDecryptionStream implements BackupStream
{
    private const VERSION    = "\x02";
    private const HEADER_LEN = 24; // SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES

    /**
     * Opaque secretstream pull state.
     * Mutated in-place by sodium_crypto_secretstream_xchacha20poly1305_pull().
     */
    private mixed $state = null;

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

        // Read the header on first call and initialise pull state.
        if (! $this->headerRead) {
            $header = $this->readExactly(1 + self::HEADER_LEN);

            if ($header === null) {
                return '';  // not enough data yet
            }

            if ($header[0] !== self::VERSION) {
                throw new InvalidBackupException(sprintf(
                    'Expected Sodium encryption version byte 0x02, got 0x%s.',
                    bin2hex($header[0]),
                ));
            }

            $streamHeader = substr($header, 1, self::HEADER_LEN);
            $this->state  = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $this->key);
            $this->headerRead = true;
        }

        // Read the next frame: [4-byte len][ciphertext]
        $lenBytes = $this->readExactly(4);

        if ($lenBytes === null) {
            return '';  // not enough data yet
        }

        /** @var array{N: int} $unpacked */
        $unpacked      = unpack('N', $lenBytes);
        $ciphertextLen = $unpacked['N'] ?? $unpacked[1];

        if ($ciphertextLen === 0) {
            $this->eof = true;
            return null;
        }

        $ciphertext = $this->readExactly($ciphertextLen);

        if ($ciphertext === null) {
            return '';  // not enough data yet
        }

        $result = sodium_crypto_secretstream_xchacha20poly1305_pull($this->state, $ciphertext);

        if ($result === false) {
            throw new InvalidBackupException(
                'Sodium decryption failed: authentication error. '
                . 'The backup may be corrupt or the key is wrong.'
            );
        }

        [$plaintext, $tag] = $result;

        if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
            $this->eof = true;

            // The final chunk may contain data (or be empty).
            if ($plaintext === '') {
                return null;
            }
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

        // Wipe the key from PHP memory first — before any potential exception.
        sodium_memzero($this->key);

        $this->inner->close();
    }

    /**
     * Read exactly $needed bytes from the inner stream, buffering partial reads.
     *
     * Returns null if not enough data is available yet (non-blocking).
     *
     * A well-formed encrypted backup always terminates with a secretstream
     * TAG_FINAL chunk, which is consumed and validated directly by read()
     * and never reaches this method. So if the inner stream ends before
     * $needed bytes are available — whether that's before the header,
     * mid-frame, or right after the last data frame with the final chunk
     * missing — the stream is truncated or malformed, and we must fail
     * loudly rather than silently treating a short read as a legitimately
     * finished restore.
     */
    private function readExactly(int $needed): ?string
    {
        while (strlen($this->readBuffer) < $needed) {
            $chunk = $this->inner->read($needed - strlen($this->readBuffer));

            if ($chunk === null) {
                throw new InvalidBackupException(
                    $this->readBuffer === ''
                        ? 'Encrypted backup stream ended unexpectedly: missing header or final chunk.'
                        : 'Encrypted backup stream ended unexpectedly mid-frame.'
                );
            }

            if ($chunk === '') {
                return null;
            }

            $this->readBuffer .= $chunk;
        }

        $result           = substr($this->readBuffer, 0, $needed);
        $this->readBuffer = substr($this->readBuffer, $needed);
        return $result;
    }
}
