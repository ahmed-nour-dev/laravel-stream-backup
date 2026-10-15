<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Streams;

use Ahmednour\StreamBackup\Contracts\BackupStream;

/**
 * AES-256-GCM streaming encryptor — BackupStream decorator.
 *
 * Encrypts the inner stream chunk-by-chunk using PHP's openssl_encrypt()
 * in AES-256-GCM mode (authenticated encryption with per-chunk nonces).
 * Memory per iteration: one plaintext chunk + one same-size ciphertext;
 * both are freed at the end of each read() call.
 *
 * Wire format (v2):
 *   [1 byte:  version = 0x02]
 *   [12 bytes: random base nonce]
 *   // for each plaintext chunk:
 *   [4 bytes BE: ciphertext length]
 *   [16 bytes: GCM authentication tag]
 *   [N bytes: ciphertext  (N == plaintext length)]
 *   // final frame (authenticated end of stream):
 *   [4 bytes: 0x00000000]
 *   [16 bytes: GCM authentication tag over empty plaintext]
 *
 * AAD of every frame: version byte . base nonce . final flag, where the
 * final flag is 0x00 for data frames and 0x01 for the final frame. This
 * binds the header into every chunk and makes the end of the stream
 * authenticated: a truncated backup cannot be closed with a forged marker.
 * Nothing may follow the final frame.
 *
 * Per-chunk nonce derivation:
 *   nonce_i = base_nonce with last 4 bytes XOR'd with pack('N', i).
 *   This gives 2^32 unique nonces per backup key — enough for ~256 TB
 *   at 64 KB chunks before nonce reuse (at which point a new key is needed).
 *   The final frame uses the next index after the last data frame.
 *
 * Wire format v1 (version byte 0x01, written before v2) had no AAD and
 * ended with a bare 4-byte 0x00000000 marker; OpenSslDecryptionStream
 * still reads it.
 */
final class OpenSslEncryptionStream implements BackupStream
{
    private const ALGORITHM = 'aes-256-gcm';
    private const VERSION   = "\x02";
    private const NONCE_LEN = 12;
    private const TAG_LEN   = 16;

    private const FLAG_DATA  = "\x00";
    private const FLAG_FINAL = "\x01";

    private string $baseNonce;
    private int    $chunkIndex = 0;

    /** Bytes buffered from the header, not yet returned to the caller. */
    private string $pending    = '';

    /** True once the inner stream has signalled EOF (returned null). */
    private bool $innerDone   = false;

    /** True once read() has returned null. */
    private bool $eof         = false;

    private bool $closed      = false;

    public function __construct(
        private readonly BackupStream $inner,
        private string $key, // mutable property so sodium_memzero can wipe it
    ) {
        $this->baseNonce = random_bytes(self::NONCE_LEN);
        // Pre-load the file header into pending so the first read() returns it.
        $this->pending = self::VERSION . $this->baseNonce;
    }

    public function read(int $length = 65536): ?string
    {
        if ($this->eof) {
            return null;
        }

        // (A) Drain the header bytes before any encryption work.
        if ($this->pending !== '') {
            $out           = $this->pending;
            $this->pending = '';
            return $out;
        }

        // (B) Inner is exhausted; we already emitted the final frame last
        //     call — now signal EOF to the pipeline loop.
        if ($this->innerDone) {
            $this->eof = true;
            return null;
        }

        $plain = $this->inner->read($length);

        // Non-blocking: no data ready yet but stream is not done.
        if ($plain === '') {
            return '';
        }

        // Inner stream exhausted — emit the authenticated final frame.
        if ($plain === null) {
            $this->innerDone = true;
            [$ciphertext, $tag] = $this->encryptFrame('', self::FLAG_FINAL);
            return pack('N', 0) . $tag;
        }

        [$ciphertext, $tag] = $this->encryptFrame($plain, self::FLAG_DATA);

        // Frame: [4-byte BE len][16-byte tag][ciphertext]
        return pack('N', strlen($ciphertext)) . $tag . $ciphertext;
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
     * Encrypt one frame with a unique per-chunk nonce and the header +
     * final flag as AAD.
     *
     * @return array{0: string, 1: string} [ciphertext, tag]
     */
    private function encryptFrame(string $plain, string $flag): array
    {
        $nonce      = $this->deriveNonce($this->chunkIndex++);
        $tag        = '';
        $ciphertext = openssl_encrypt(
            $plain,
            self::ALGORITHM,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::VERSION . $this->baseNonce . $flag,
            self::TAG_LEN,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException(
                'AES-256-GCM encryption failed: ' . openssl_error_string()
            );
        }

        return [$ciphertext, $tag];
    }

    /**
     * Derive the per-chunk GCM nonce by XOR-ing the last 4 bytes of
     * the base nonce with the chunk index (big-endian uint32).
     *
     * XOR is safe for GCM nonces because each (key, nonce) pair is used
     * exactly once across the life of the backup — the base nonce is
     * randomly generated per backup, and the index never repeats.
     */
    private function deriveNonce(int $index): string
    {
        $suffix = substr($this->baseNonce, self::NONCE_LEN - 4) ^ pack('N', $index);
        return substr($this->baseNonce, 0, self::NONCE_LEN - 4) . $suffix;
    }
}
