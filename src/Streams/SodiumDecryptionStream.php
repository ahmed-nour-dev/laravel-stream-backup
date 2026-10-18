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
 * Only an authenticated TAG_FINAL chunk ends the stream: a zero-length
 * frame is rejected, and so is any byte after the TAG_FINAL chunk.
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

    /** Length of the frame being read, once its length field is consumed. */
    private ?int $frameLen = null;

    /** Raw bytes buffered from the inner stream, not yet consumed as frames. */
    private string $readBuffer = '';

    /** Decrypted plaintext buffered from a previous frame, not yet returned. */
    private string $pending = '';

    private bool $headerRead = false;

    /** True once the TAG_FINAL chunk was read; only inner EOF may follow. */
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

        // Read the next frame: [4-byte len][ciphertext]. The length is kept
        // across calls so a short read cannot lose it.
        if ($this->frameLen === null) {
            $lenBytes = $this->readExactly(4);

            if ($lenBytes === null) {
                return '';  // not enough data yet
            }

            /** @var array{N: int} $unpacked */
            $unpacked       = unpack('N', $lenBytes);
            $this->frameLen = $unpacked['N'] ?? $unpacked[1];

            // Every secretstream chunk carries ABYTES of tag + MAC, so a
            // shorter frame (e.g. a forged zero-length "end marker") is invalid.
            if ($this->frameLen < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                throw new InvalidBackupException(sprintf(
                    'Encrypted backup stream has an invalid %d-byte frame; '
                    . 'only an authenticated final chunk may end the stream.',
                    $this->frameLen,
                ));
            }
        }

        $ciphertext = $this->readExactly($this->frameLen);

        if ($ciphertext === null) {
            return '';  // not enough data yet
        }

        $this->frameLen = null;

        $result = sodium_crypto_secretstream_xchacha20poly1305_pull($this->state, $ciphertext);

        if ($result === false) {
            throw new InvalidBackupException(
                'Sodium decryption failed: authentication error. '
                . 'The backup may be corrupt or the key is wrong.'
            );
        }

        [$plaintext, $tag] = $result;

        if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
            $this->ended = true;

            // The final chunk may contain data (or be empty).
            if ($plaintext === '') {
                return $this->expectInnerEof();
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
     * After the TAG_FINAL chunk, the inner stream must be exhausted.
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
