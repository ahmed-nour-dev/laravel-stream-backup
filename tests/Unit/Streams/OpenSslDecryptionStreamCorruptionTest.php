<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Streams;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Encryption\OpenSslAes256GcmDriver;
use Ahmednour\StreamBackup\Exceptions\InvalidBackupException;
use PHPUnit\Framework\TestCase;

/**
 * Minimal in-memory BackupStream. Reads ignore $length and hand back one
 * queued chunk per call, so tests can control exactly how many bytes the
 * decryption stream sees per read() — including feeding a whole ciphertext
 * blob as a single chunk or splitting it byte-by-byte.
 */
final class FixedChunkStream implements BackupStream
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
 * Adversarial tests for the AES-256-GCM streaming wire format: prove that
 * every supported corruption of an encrypted backup stream is detected as
 * an explicit, deterministic integrity failure — never a silent partial
 * "success" — while an unmodified stream still round-trips correctly.
 *
 * Wire format under test (see OpenSslEncryptionStream):
 *   [1 byte  version = 0x01]
 *   [12 bytes base nonce]
 *   repeated: [4 bytes BE ciphertext length][16 bytes GCM tag][ciphertext]
 *   EOF marker: [4 bytes 0x00000000]
 */
final class OpenSslDecryptionStreamCorruptionTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('openssl')) {
            $this->markTestSkipped('ext-openssl not available.');
        }
    }

    private function validKey(): string
    {
        return random_bytes(32);
    }

    /** @param array<int, string> $plaintextChunks */
    private function encrypt(array $plaintextChunks, string $key): string
    {
        $stream = (new OpenSslAes256GcmDriver())->spawn(new FixedChunkStream($plaintextChunks), $key);
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
        $stream = (new OpenSslAes256GcmDriver())->spawnDecrypt(new FixedChunkStream($chunks), $key);

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

    /** Locates the 4-byte length field of the first data frame (after the 13-byte header). */
    private function firstFrameLenOffset(): int
    {
        return 1 + 12; // version + base nonce
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

        // Feed a single byte at a time to stress the readExactly buffering.
        self::assertSame(implode('', $chunks), $this->decrypt($blob, $key, feedSize: 1));
    }

    // ---- Corruption: modified ciphertext bytes ----

    public function test_modified_ciphertext_byte_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['secret database rows'], $key);

        $ciphertextStart = $this->firstFrameLenOffset() + 4 + 16;
        $blob[$ciphertextStart] = chr(ord($blob[$ciphertextStart]) ^ 0xFF);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/authentication tag mismatch/');
        $this->decrypt($blob, $key);
    }

    // ---- Corruption: modified authentication tag ----

    public function test_modified_authentication_tag_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['secret database rows'], $key);

        $tagStart = $this->firstFrameLenOffset() + 4;
        $blob[$tagStart] = chr(ord($blob[$tagStart]) ^ 0xFF);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/authentication tag mismatch/');
        $this->decrypt($blob, $key);
    }

    // ---- Corruption: modified nonce/header data ----

    public function test_modified_base_nonce_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['payload depends on nonce'], $key);

        // Flip a byte inside the 12-byte base nonce (offset 1..12).
        $blob[5] = chr(ord($blob[5]) ^ 0xFF);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/authentication tag mismatch/');
        $this->decrypt($blob, $key);
    }

    public function test_wrong_version_byte_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['payload'], $key);

        $blob[0] = "\x02";

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/version byte/');
        $this->decrypt($blob, $key);
    }

    // ---- Corruption: modified length metadata ----

    public function test_inflated_chunk_length_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['short payload'], $key);

        $lenOffset = $this->firstFrameLenOffset();
        $original  = unpack('N', substr($blob, $lenOffset, 4))[1];
        $blob      = substr_replace($blob, pack('N', $original + 5000), $lenOffset, 4);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/ended unexpectedly/');
        $this->decrypt($blob, $key);
    }

    public function test_deflated_chunk_length_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['a somewhat longer payload than the tag'], $key);

        $lenOffset = $this->firstFrameLenOffset();
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
        // A safe implementation must detect the truncation quickly rather
        // than attempting to buffer gigabytes of nonexistent data.
        $bogus = "\x01" . random_bytes(12) . pack('N', 0xFFFFFFF0) . random_bytes(3);

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

    // ---- Corruption: truncated final stream / missing EOF marker ----

    public function test_stream_truncated_right_after_last_data_frame_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['only chunk, no eof marker follows'], $key);

        // Drop exactly the trailing 4-byte 0x00000000 EOF marker so the
        // stream ends immediately after an otherwise-complete data frame.
        $withoutEofMarker = substr($blob, 0, -4);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/ended unexpectedly/');
        $this->decrypt($withoutEofMarker, $key);
    }

    public function test_stream_truncated_right_after_first_data_frame_does_not_silently_return_partial_plaintext(): void
    {
        $key    = $this->validKey();
        $chunks = ['first piece of the payload', 'second piece that must never surface without the rest'];
        $blob   = $this->encrypt($chunks, $key);

        $frames = $this->splitFrames($blob);
        self::assertGreaterThanOrEqual(2, count($frames), 'Expected at least one data frame plus the EOF marker frame.');

        // Truncate right after the first complete data frame, dropping the
        // second data frame and the EOF marker entirely.
        $truncated = substr($blob, 0, 1 + 12) . $frames[0];
        $stream    = (new OpenSslAes256GcmDriver())->spawnDecrypt(new FixedChunkStream([$truncated]), $key);

        $recovered = '';
        try {
            while (($chunk = $stream->read()) !== null) {
                $recovered .= $chunk;
            }
            self::fail('Expected InvalidBackupException; instead decryption reported success.');
        } catch (InvalidBackupException $e) {
            // Whatever was buffered before the failure must not look like a
            // clean, complete restore of the original plaintext.
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
        $this->expectExceptionMessageMatches('/authentication tag mismatch/');
        $this->decrypt($blob, $this->validKey());
    }

    // ---- Corruption: reordered encrypted chunks ----

    public function test_reordered_chunks_are_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['AAAA first chunk', 'BBBB second chunk'], $key);

        $frames = $this->splitFrames($blob);
        self::assertCount(3, $frames, 'Expected 2 data frames + 1 EOF marker frame.');

        // Swap the two data frames (their internal ciphertext lengths
        // happen to differ, but the format allows arbitrary per-frame
        // lengths so this still parses as a structurally valid stream).
        [$frames[0], $frames[1]] = [$frames[1], $frames[0]];

        $reordered = substr($blob, 0, 1 + 12) . implode('', $frames);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/authentication tag mismatch/');
        $this->decrypt($reordered, $key);
    }

    /**
     * Splits the frame region of a wire-format blob (after the 13-byte
     * header) into individual [len][tag][ciphertext] frames, including the
     * trailing 4-byte EOF marker as its own "frame".
     *
     * @return array<int, string>
     */
    private function splitFrames(string $blob): array
    {
        $pos    = 1 + 12;
        $frames = [];

        while ($pos < strlen($blob)) {
            $len   = unpack('N', substr($blob, $pos, 4))[1];
            $start = $pos;
            $pos  += 4;

            if ($len === 0) {
                $frames[] = substr($blob, $start, 4);
                break;
            }

            $frameLen = 4 + 16 + $len;
            $frames[] = substr($blob, $start, $frameLen);
            $pos      = $start + $frameLen;
        }

        return $frames;
    }
}
