<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Streams;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Encryption\SodiumDriver;
use Ahmednour\StreamBackup\Exceptions\InvalidBackupException;
use PHPUnit\Framework\TestCase;

/**
 * Minimal in-memory BackupStream. Reads ignore $length and hand back one
 * queued chunk per call, so tests can control exactly how many bytes the
 * decryption stream sees per read() — including feeding a whole ciphertext
 * blob as a single chunk or splitting it byte-by-byte.
 */
final class SodiumFixedChunkStream implements BackupStream
{
    private int $offset = 0;

    /** @param array<int, string> $chunks */
    public function __construct(private readonly array $chunks)
    {
    }

    public function read(int $length = 65536): ?string
    {
        if ($this->offset >= count($this->chunks)) {
            return null;
        }

        return $this->chunks[$this->offset++];
    }

    public function isEof(): bool
    {
        return $this->offset >= count($this->chunks);
    }

    public function close(): void
    {
    }
}

/**
 * Adversarial tests for the XChaCha20-Poly1305 secretstream wire format:
 * prove that every supported corruption of an encrypted backup stream is
 * detected as an explicit, deterministic integrity failure — never a
 * silent partial "success" — while an unmodified stream still round-trips
 * correctly.
 *
 * Wire format under test (see SodiumEncryptionStream):
 *   [1 byte  version = 0x02]
 *   [24 bytes secretstream push header]
 *   repeated: [4 bytes BE ciphertext length][ciphertext]
 *   last chunk carries libsodium's TAG_FINAL, embedded inside the
 *   ciphertext itself (no separate length-0 EOF marker like the OpenSSL
 *   driver).
 */
final class SodiumDecryptionStreamCorruptionTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not available.');
        }
    }

    private function validKey(): string
    {
        return random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }

    /** @param array<int, string> $plaintextChunks */
    private function encrypt(array $plaintextChunks, string $key): string
    {
        $stream = (new SodiumDriver())->spawn(new SodiumFixedChunkStream($plaintextChunks), $key);
        $blob   = $this->drain($stream);
        $stream->close();

        return $blob;
    }

    /**
     * Decrypts a ciphertext blob via the real driver + decryption stream,
     * feeding it to the decoder in small pieces to also exercise the
     * frame-buffering (readExactly) logic under partial reads — not just
     * whole-blob-in-one-call.
     */
    private function decrypt(string $blob, string $key, int $feedSize = 7): string
    {
        $chunks = $feedSize > 0 ? str_split($blob, $feedSize) : [$blob];
        $stream = (new SodiumDriver())->spawnDecrypt(new SodiumFixedChunkStream($chunks), $key);

        return $this->drain($stream);
    }

    private function drain(BackupStream $stream, int $length = 65536): string
    {
        $buffer = '';
        while (($chunk = $stream->read($length)) !== null) {
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function headerLen(): int
    {
        return 1 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
    }

    // ---- Baseline: valid streams still round-trip ----

    public function test_valid_single_chunk_stream_round_trips(): void
    {
        $key       = $this->validKey();
        $plaintext = 'a valid backup payload';
        $blob      = $this->encrypt([$plaintext], $key);

        self::assertSame($plaintext, $this->decrypt($blob, $key));
    }

    public function test_valid_multi_chunk_stream_round_trips_under_partial_reads(): void
    {
        $key    = $this->validKey();
        $chunks = ['first segment of data', 'second segment', 'a third and final segment'];
        $blob   = $this->encrypt($chunks, $key);

        self::assertSame(implode('', $chunks), $this->decrypt($blob, $key, feedSize: 1));
    }

    // ---- Corruption: modified ciphertext bytes ----

    public function test_modified_ciphertext_byte_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['secret database rows'], $key);

        $ciphertextStart = $this->headerLen() + 4 + 1; // skip len prefix + first ciphertext byte
        $blob[$ciphertextStart] = chr(ord($blob[$ciphertextStart]) ^ 0xFF);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/authentication error/');
        $this->decrypt($blob, $key);
    }

    // ---- Corruption: modified authentication tag (Poly1305 MAC is the trailing bytes of the ciphertext frame) ----

    public function test_modified_authentication_tag_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['secret database rows'], $key);

        $lenOffset  = $this->headerLen();
        $frameLen   = unpack('N', substr($blob, $lenOffset, 4))[1];
        $macTailPos = $lenOffset + 4 + $frameLen - 1; // last byte of the MAC region

        $blob[$macTailPos] = chr(ord($blob[$macTailPos]) ^ 0xFF);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/authentication error/');
        $this->decrypt($blob, $key);
    }

    // ---- Corruption: modified nonce/header data ----

    public function test_modified_secretstream_header_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['payload depends on header'], $key);

        $blob[5] = chr(ord($blob[5]) ^ 0xFF);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/authentication error/');
        $this->decrypt($blob, $key);
    }

    public function test_wrong_version_byte_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['payload'], $key);

        $blob[0] = "\x01";

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/version byte/');
        $this->decrypt($blob, $key);
    }

    // ---- Corruption: modified length metadata ----

    public function test_inflated_chunk_length_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['short payload'], $key);

        $lenOffset = $this->headerLen();
        $original  = unpack('N', substr($blob, $lenOffset, 4))[1];
        $blob      = substr_replace($blob, pack('N', $original + 5000), $lenOffset, 4);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/ended unexpectedly/');
        $this->decrypt($blob, $key);
    }

    public function test_deflated_chunk_length_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['a somewhat longer payload than the overhead'], $key);

        $lenOffset = $this->headerLen();
        $original  = unpack('N', substr($blob, $lenOffset, 4))[1];
        self::assertGreaterThan(2, $original);
        $blob = substr_replace($blob, pack('N', $original - 2), $lenOffset, 4);

        $this->expectException(InvalidBackupException::class);
        $this->decrypt($blob, $key);
    }

    public function test_implausibly_large_length_claim_fails_fast_without_buffering_it(): void
    {
        $key = $this->validKey();

        // A malicious/corrupt header claiming a ~4 GB frame on a tiny stream.
        $bogus = "\x02" . random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES)
            . pack('N', 0xFFFFFFF0) . random_bytes(3);

        $start = microtime(true);
        try {
            $this->decrypt($bogus, $key, feedSize: 0);
            self::fail('Expected InvalidBackupException.');
        } catch (InvalidBackupException $e) {
            self::assertLessThan(2.0, microtime(true) - $start, 'Corruption must be detected quickly, not after buffering the claimed length.');
        }
    }

    // ---- Corruption: truncated encrypted chunks ----

    public function test_truncated_mid_frame_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['chunk one here', 'chunk two here'], $key);

        $truncated = substr($blob, 0, -5);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/ended unexpectedly mid-frame/');
        $this->decrypt($truncated, $key);
    }

    // ---- Corruption: truncated final stream / missing final (TAG_FINAL) chunk ----

    public function test_stream_truncated_right_after_first_data_frame_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['first chunk', 'second chunk, then a final marker follows'], $key);

        $frames = $this->splitFrames($blob);
        self::assertGreaterThanOrEqual(2, count($frames), 'Expected at least one data frame plus a final frame.');

        // Truncate right after the first complete data frame, dropping the
        // TAG_FINAL frame entirely — the stream looks "complete" up to a
        // frame boundary but never signals a legitimate end.
        $truncated = substr($blob, 0, $this->headerLen()) . $frames[0];

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/ended unexpectedly/');
        $this->decrypt($truncated, $key);
    }

    public function test_stream_truncated_right_after_first_data_frame_does_not_silently_return_partial_plaintext(): void
    {
        $key    = $this->validKey();
        $chunks = ['this must never', 'come back as a successful restore'];
        $blob   = $this->encrypt($chunks, $key);

        $frames    = $this->splitFrames($blob);
        $truncated = substr($blob, 0, $this->headerLen()) . $frames[0];

        $stream    = (new SodiumDriver())->spawnDecrypt(new SodiumFixedChunkStream([$truncated]), $key);
        $recovered = '';

        try {
            while (($chunk = $stream->read()) !== null) {
                $recovered .= $chunk;
            }
            self::fail('Expected InvalidBackupException; instead decryption reported success.');
        } catch (InvalidBackupException $e) {
            self::assertNotSame(implode('', $chunks), $recovered);
        }
    }

    public function test_empty_stream_is_rejected(): void
    {
        $key = $this->validKey();

        $this->expectException(InvalidBackupException::class);
        $this->decrypt('', $key, feedSize: 0);
    }

    // ---- Corruption: wrong encryption key ----

    public function test_wrong_key_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['data encrypted under one key'], $key);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/authentication error/');
        $this->decrypt($blob, $this->validKey());
    }

    // ---- Corruption: reordered encrypted chunks ----

    public function test_reordered_chunks_are_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['AAAA first chunk', 'BBBB second chunk'], $key);

        $frames = $this->splitFrames($blob);
        self::assertCount(3, $frames, 'Expected 2 message frames + 1 final frame.');

        // Swap the two message frames (leave the TAG_FINAL frame last).
        // The secretstream's rolling internal state makes each frame's MAC
        // depend on having processed every prior frame in order, so this
        // must break authentication even though the stream still parses
        // as structurally well-formed.
        [$frames[0], $frames[1]] = [$frames[1], $frames[0]];

        $reordered = substr($blob, 0, $this->headerLen()) . implode('', $frames);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/authentication error/');
        $this->decrypt($reordered, $key);
    }

    /**
     * Splits the frame region of a wire-format blob (after the header)
     * into individual [len][ciphertext] frames.
     *
     * @return array<int, string>
     */
    private function splitFrames(string $blob): array
    {
        $pos    = $this->headerLen();
        $frames = [];

        while ($pos < strlen($blob)) {
            $len   = unpack('N', substr($blob, $pos, 4))[1];
            $start = $pos;
            $pos  += 4 + $len;
            $frames[] = substr($blob, $start, 4 + $len);
        }

        return $frames;
    }
}
