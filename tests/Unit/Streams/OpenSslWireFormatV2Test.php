<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Streams;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Encryption\OpenSslAes256GcmDriver;
use Ahmednour\StreamBackup\Exceptions\InvalidBackupException;
use Ahmednour\StreamBackup\Models\Backup;
use PHPUnit\Framework\TestCase;

/**
 * Minimal in-memory BackupStream. Hands back one queued chunk per read()
 * (an empty string models "no data yet" on a non-blocking source).
 */
final class V2FixedChunkStream implements BackupStream
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
 * Wire format v2 of the AES-256-GCM driver: the end of the stream is
 * authenticated and the header is bound into every chunk, so truncation at
 * a chunk boundary, forged end markers, header downgrades and trailing
 * bytes are all rejected. v1 backups must keep decrypting.
 *
 * Wire format v2 (see OpenSslEncryptionStream):
 *   [1 byte  version = 0x02]
 *   [12 bytes base nonce]
 *   repeated: [4 bytes BE ciphertext length][16 bytes GCM tag][ciphertext]
 *   final frame: [4 bytes 0x00000000][16 bytes GCM tag over empty plaintext]
 *   AAD of every frame: version . base nonce . final flag (0x00 / 0x01)
 */
final class OpenSslWireFormatV2Test extends TestCase
{
    private const HEADER_LEN = 1 + 12;

    /**
     * Captured from OpenSslEncryptionStream at e9c8860 (wire format v1),
     * key = bytes 0x00..0x1f, plaintext chunks:
     * 'v1 backup, chunk one; ', 'chunk two; ', 'chunk three.'
     */
    private const V1_FIXTURE_HEX = '01ad8a7f59088c04e561934df80000001664cdd4b6ca6151287433a862a718bfd278390f4e3154067b7d3c83ee1fb052a97f03fefbe0400000000ba2501ff8df6cb0422bdde33d404962bc49f97b897c9b0ab0d242500000000c76c4d31bf3168c665bb137573976859587889b9890fc36d2831d0e7400000000';

    private const V1_FIXTURE_PLAINTEXT = 'v1 backup, chunk one; chunk two; chunk three.';

    protected function setUp(): void
    {
        if (! extension_loaded('openssl')) {
            $this->markTestSkipped('ext-openssl not available.');
        }
    }

    // ---- Encryptor writes v2 ----

    public function test_encryptor_writes_version_byte_0x02(): void
    {
        $blob = $this->encrypt(['payload'], $this->validKey());

        self::assertSame("\x02", $blob[0]);
    }

    public function test_encryptor_ends_with_an_authenticated_empty_final_frame(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['payload'], $key);

        $final = substr($blob, -20);
        self::assertSame(0, unpack('N', substr($final, 0, 4))[1]);

        // The final frame's tag authenticates empty plaintext under the
        // final flag, at the chunk index right after the last data frame.
        $plain = openssl_decrypt(
            '',
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $this->deriveNonce(substr($blob, 1, 12), 1),
            substr($final, 4, 16),
            substr($blob, 0, self::HEADER_LEN) . "\x01",
        );
        self::assertSame('', $plain);
    }

    public function test_data_frames_bind_the_header_and_a_non_final_flag_into_the_aad(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['payload'], $key);

        $frame = $this->splitFrames($blob)[0];
        $len   = unpack('N', substr($frame, 0, 4))[1];
        $tag   = substr($frame, 4, 16);
        $ct    = substr($frame, 20, $len);
        $nonce = $this->deriveNonce(substr($blob, 1, 12), 0);

        $header = substr($blob, 0, self::HEADER_LEN);
        self::assertSame('payload', openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $header . "\x00"));
        self::assertFalse(openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $header . "\x01"));
        self::assertFalse(openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag));
    }

    // ---- v2 round trips ----

    public function test_v2_multi_chunk_stream_round_trips_under_partial_reads(): void
    {
        $key    = $this->validKey();
        $chunks = ['first segment', 'second segment', 'third and final segment'];

        self::assertSame(implode('', $chunks), $this->decrypt($this->encrypt($chunks, $key), $key, feedSize: 1));
    }

    public function test_v2_empty_backup_round_trips(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt([], $key);

        self::assertSame(self::HEADER_LEN + 4 + 16, strlen($blob));
        self::assertSame('', $this->decrypt($blob, $key));
    }

    public function test_v2_decrypt_tolerates_non_blocking_empty_reads_until_inner_eof(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['abc', 'def'], $key);

        $stream = (new OpenSslAes256GcmDriver())->spawnDecrypt(new V2FixedChunkStream([$blob, '', '']), $key);

        self::assertSame('abcdef', $this->drain($stream));
    }

    public function test_decrypt_survives_a_non_blocking_empty_read_between_length_and_frame_body(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['hello world'], $key);

        $parts  = [substr($blob, 0, self::HEADER_LEN + 4), '', substr($blob, self::HEADER_LEN + 4)];
        $stream = (new OpenSslAes256GcmDriver())->spawnDecrypt(new V2FixedChunkStream($parts), $key);

        self::assertSame('hello world', $this->drain($stream));
    }

    public function test_v1_decrypt_survives_a_non_blocking_empty_read_between_length_and_frame_body(): void
    {
        $blob   = hex2bin(self::V1_FIXTURE_HEX);
        $parts  = [substr($blob, 0, self::HEADER_LEN + 4), '', substr($blob, self::HEADER_LEN + 4)];
        $stream = (new OpenSslAes256GcmDriver())->spawnDecrypt(new V2FixedChunkStream($parts), $this->fixtureKey());

        self::assertSame(self::V1_FIXTURE_PLAINTEXT, $this->drain($stream));
    }

    // ---- Truncation at a chunk boundary ----

    public function test_truncation_at_chunk_boundary_with_a_v1_style_eof_marker_is_rejected(): void
    {
        // The issue #49 attack: cut after a complete chunk and append 0x00000000.
        $key    = $this->validKey();
        $blob   = $this->encrypt(['chunk one', 'chunk two', 'chunk three'], $key);
        $frames = $this->splitFrames($blob);

        $truncated = substr($blob, 0, self::HEADER_LEN) . $frames[0] . pack('N', 0);

        $this->expectException(InvalidBackupException::class);
        $this->decrypt($truncated, $key);
    }

    public function test_truncation_at_chunk_boundary_without_any_end_marker_is_rejected(): void
    {
        $key    = $this->validKey();
        $blob   = $this->encrypt(['chunk one', 'chunk two'], $key);
        $frames = $this->splitFrames($blob);

        $truncated = substr($blob, 0, self::HEADER_LEN) . $frames[0];

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/ended unexpectedly/');
        $this->decrypt($truncated, $key);
    }

    public function test_truncation_with_the_genuine_final_frame_moved_up_is_rejected(): void
    {
        // Keep the real (validly tagged) final frame but drop the chunks
        // before it: its nonce is bound to its original chunk index.
        $key    = $this->validKey();
        $blob   = $this->encrypt(['chunk one', 'chunk two', 'chunk three'], $key);
        $frames = $this->splitFrames($blob);

        $truncated = substr($blob, 0, self::HEADER_LEN) . $frames[0] . end($frames);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/authentication tag mismatch/');
        $this->decrypt($truncated, $key);
    }

    public function test_truncation_does_not_surface_partial_plaintext_as_success(): void
    {
        $key    = $this->validKey();
        $blob   = $this->encrypt(['first half, ', 'second half'], $key);
        $frames = $this->splitFrames($blob);

        $truncated = substr($blob, 0, self::HEADER_LEN) . $frames[0] . pack('N', 0) . str_repeat("\x00", 16);
        $stream    = (new OpenSslAes256GcmDriver())->spawnDecrypt(new V2FixedChunkStream([$truncated]), $key);

        try {
            $this->drain($stream);
            self::fail('Expected InvalidBackupException; instead decryption reported success.');
        } catch (InvalidBackupException $e) {
            self::assertMatchesRegularExpression('/authentication tag mismatch/', $e->getMessage());
        }
    }

    // ---- Trailing bytes after the final frame ----

    public function test_single_trailing_byte_after_final_frame_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['payload'], $key);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/trailing/');
        $this->decrypt($blob . "\x00", $key);
    }

    public function test_appended_valid_frame_after_final_frame_is_rejected(): void
    {
        $key    = $this->validKey();
        $blob   = $this->encrypt(['payload', 'more'], $key);
        $frames = $this->splitFrames($blob);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/trailing/');
        $this->decrypt($blob . $frames[0], $key);
    }

    public function test_trailing_bytes_arriving_after_non_blocking_empty_reads_are_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['payload'], $key);

        $stream = (new OpenSslAes256GcmDriver())->spawnDecrypt(new V2FixedChunkStream([$blob, '', 'junk']), $key);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/trailing/');
        $this->drain($stream);
    }

    // ---- Header binding ----

    public function test_v2_stream_relabelled_as_v1_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['payload'], $key);

        $blob[0] = "\x01";

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/authentication tag mismatch/');
        $this->decrypt($blob, $key);
    }

    public function test_unknown_version_byte_is_rejected(): void
    {
        $key  = $this->validKey();
        $blob = $this->encrypt(['payload'], $key);

        $blob[0] = "\x03";

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/version byte/');
        $this->decrypt($blob, $key);
    }

    // ---- v1 backward compatibility ----

    public function test_v1_backup_still_decrypts(): void
    {
        self::assertSame(self::V1_FIXTURE_PLAINTEXT, $this->decrypt(hex2bin(self::V1_FIXTURE_HEX), $this->fixtureKey()));
    }

    public function test_v1_backup_still_decrypts_under_partial_reads(): void
    {
        self::assertSame(self::V1_FIXTURE_PLAINTEXT, $this->decrypt(hex2bin(self::V1_FIXTURE_HEX), $this->fixtureKey(), feedSize: 1));
    }

    public function test_v1_backup_with_tampered_chunk_is_still_rejected(): void
    {
        $blob = hex2bin(self::V1_FIXTURE_HEX);
        $at   = self::HEADER_LEN + 4 + 16;
        $blob[$at] = chr(ord($blob[$at]) ^ 0xFF);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/authentication tag mismatch/');
        $this->decrypt($blob, $this->fixtureKey());
    }

    // ---- Magic bytes ----

    public function test_magic_bytes_accept_v1_and_v2(): void
    {
        $driver = new OpenSslAes256GcmDriver();
        $backup = new Backup(['path' => 'backups/x.sql.gz.enc']);

        $driver->verifyMagicBytes("\x01", $backup);
        $driver->verifyMagicBytes("\x02", $backup);

        $this->expectException(\RuntimeException::class);
        $driver->verifyMagicBytes("\x03", $backup);
    }

    // ---- Helpers ----

    private function validKey(): string
    {
        return random_bytes(32);
    }

    private function fixtureKey(): string
    {
        return implode('', array_map('chr', range(0, 31)));
    }

    /** @param array<int, string> $plaintextChunks */
    private function encrypt(array $plaintextChunks, string $key): string
    {
        $stream = (new OpenSslAes256GcmDriver())->spawn(new V2FixedChunkStream($plaintextChunks), $key);
        $blob   = $this->drain($stream);
        $stream->close();

        return $blob;
    }

    private function decrypt(string $blob, string $key, int $feedSize = 7): string
    {
        $chunks = $feedSize > 0 ? str_split($blob, $feedSize) : [$blob];
        $stream = (new OpenSslAes256GcmDriver())->spawnDecrypt(new V2FixedChunkStream($chunks), $key);

        return $this->drain($stream);
    }

    private function drain(BackupStream $stream): string
    {
        $buffer = '';
        while (($chunk = $stream->read()) !== null) {
            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function deriveNonce(string $baseNonce, int $index): string
    {
        return substr($baseNonce, 0, 8) . (substr($baseNonce, 8) ^ pack('N', $index));
    }

    /**
     * Splits the frame region (after the 13-byte header) into frames; the
     * v2 final frame is [0x00000000][16-byte tag].
     *
     * @return array<int, string>
     */
    private function splitFrames(string $blob): array
    {
        $pos    = self::HEADER_LEN;
        $frames = [];

        while ($pos < strlen($blob)) {
            $len      = unpack('N', substr($blob, $pos, 4))[1];
            $frames[] = substr($blob, $pos, 4 + 16 + $len);
            $pos     += 4 + 16 + $len;
        }

        return $frames;
    }
}
