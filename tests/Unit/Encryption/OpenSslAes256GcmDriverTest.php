<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Tests\Unit\Encryption;

use Ahmednour\StreamBackup\Contracts\BackupStream;
use Ahmednour\StreamBackup\Encryption\OpenSslAes256GcmDriver;
use Ahmednour\StreamBackup\Exceptions\InvalidConfigException;
use Ahmednour\StreamBackup\Streams\OpenSslEncryptionStream;
use PHPUnit\Framework\TestCase;

/**
 * Minimal in-memory BackupStream for testing.
 */
final class FixedChunkStream implements BackupStream
{
    private int $offset = 0;

    /** @param array<int, string> $chunks */
    public function __construct(private readonly array $chunks) {}

    public function read(int $length = 65536): ?string
    {
        if ($this->offset >= count($this->chunks)) {
            return null;
        }
        return $this->chunks[$this->offset++];
    }

    public function isEof(): bool { return $this->offset >= count($this->chunks); }
    public function close(): void {}
}

/**
 * Decrypts an OpenSslEncryptionStream output buffer for round-trip tests.
 * Mirrors the wire format documented on OpenSslEncryptionStream.
 */
function opensslDecrypt(string $ciphertext, string $key): string
{
    $pos = 0;

    // Header
    $version   = ord($ciphertext[$pos++]);
    assert($version === 0x02, "Expected version 0x02, got {$version}");
    $baseNonce = substr($ciphertext, $pos, 12);
    $pos      += 12;
    $header    = substr($ciphertext, 0, 13);

    $plaintext  = '';
    $chunkIndex = 0;

    while ($pos < strlen($ciphertext)) {
        $chunkLen = unpack('N', substr($ciphertext, $pos, 4))[1];
        $pos     += 4;

        $tag        = substr($ciphertext, $pos, 16);
        $pos       += 16;
        $chunk      = substr($ciphertext, $pos, $chunkLen);
        $pos       += $chunkLen;

        // Derive per-chunk nonce
        $suffix    = substr($baseNonce, 8) ^ pack('N', $chunkIndex++);
        $nonce     = substr($baseNonce, 0, 8) . $suffix;

        // AAD = header . final flag; the final frame has length 0.
        $final = $chunkLen === 0;
        $plain = openssl_decrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $header . ($final ? "\x01" : "\x00"));
        assert($plain !== false, 'openssl_decrypt returned false — authentication tag mismatch?');

        if ($final) {
            break;
        }

        $plaintext .= $plain;
    }

    return $plaintext;
}

final class OpenSslAes256GcmDriverTest extends TestCase
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

    // ---- Driver contract ----

    public function test_name_returns_expected_identifier(): void
    {
        self::assertSame('openssl-aes-256-gcm', (new OpenSslAes256GcmDriver())->name());
    }

    public function test_key_length_returns_32(): void
    {
        self::assertSame(32, (new OpenSslAes256GcmDriver())->keyLength());
    }

    public function test_spawn_returns_encryption_stream(): void
    {
        $inner  = new FixedChunkStream(['hello']);
        $stream = (new OpenSslAes256GcmDriver())->spawn($inner, $this->validKey());
        self::assertInstanceOf(OpenSslEncryptionStream::class, $stream);
    }

    public function test_spawn_throws_on_wrong_key_length(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/requires a 32-byte key/');

        (new OpenSslAes256GcmDriver())->spawn(new FixedChunkStream([]), random_bytes(16));
    }

    // ---- Round-trip correctness ----

    public function test_round_trip_single_chunk(): void
    {
        $key       = $this->validKey();
        $plaintext = 'hello encrypted world!';
        $stream    = (new OpenSslAes256GcmDriver())->spawn(new FixedChunkStream([$plaintext]), $key);

        $encrypted = $this->drainStream($stream);
        $stream->close();

        self::assertNotSame($plaintext, $encrypted, 'Ciphertext must differ from plaintext.');
        self::assertSame($plaintext, opensslDecrypt($encrypted, $key));
    }

    public function test_round_trip_multiple_chunks(): void
    {
        $key    = $this->validKey();
        $chunks = ['chunk_one', 'chunk_two', 'chunk_three'];
        $stream = (new OpenSslAes256GcmDriver())->spawn(new FixedChunkStream($chunks), $key);

        $encrypted = $this->drainStream($stream);
        $stream->close();

        self::assertSame(implode('', $chunks), opensslDecrypt($encrypted, $key));
    }

    public function test_file_header_version_byte_is_0x02(): void
    {
        $key    = $this->validKey();
        $stream = (new OpenSslAes256GcmDriver())->spawn(new FixedChunkStream(['x']), $key);

        $output = $this->drainStream($stream);
        $stream->close();

        self::assertSame(0x02, ord($output[0]));
    }

    public function test_two_encryptions_of_same_plaintext_produce_different_ciphertexts(): void
    {
        $key    = $this->validKey();
        $data   = 'same plaintext';

        $enc1 = $this->drainStream((new OpenSslAes256GcmDriver())->spawn(new FixedChunkStream([$data]), $key));
        $enc2 = $this->drainStream((new OpenSslAes256GcmDriver())->spawn(new FixedChunkStream([$data]), $key));

        self::assertNotSame($enc1, $enc2, 'Different backups must produce different ciphertexts (random IV).');
    }

    public function test_tampered_ciphertext_fails_authentication(): void
    {
        $key    = $this->validKey();
        $stream = (new OpenSslAes256GcmDriver())->spawn(new FixedChunkStream(['secret data']), $key);

        $output   = $this->drainStream($stream);
        $stream->close();

        // Flip a bit in the ciphertext region (after 1-byte version + 12-byte nonce + 4-byte len + 16-byte tag)
        $flipAt          = 1 + 12 + 4 + 16 + 2;
        $tampered        = $output;
        $tampered[$flipAt] = chr(ord($tampered[$flipAt]) ^ 0xFF);

        // openssl_decrypt must reject the tampered ciphertext (returns false)
        $pos       = 0;
        $version   = ord($tampered[$pos++]);
        $baseNonce = substr($tampered, $pos, 12); $pos += 12;
        $chunkLen  = unpack('N', substr($tampered, $pos, 4))[1]; $pos += 4;
        $tag       = substr($tampered, $pos, 16); $pos += 16;
        $chunk     = substr($tampered, $pos, $chunkLen);
        $suffix    = substr($baseNonce, 8) ^ pack('N', 0);
        $nonce     = substr($baseNonce, 0, 8) . $suffix;

        $aad    = substr($tampered, 0, 13) . "\x00";
        $result = openssl_decrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad);
        self::assertFalse($result, 'Tampered ciphertext must fail GCM authentication.');
    }

    public function test_empty_stream_produces_only_header_and_final_frame(): void
    {
        $key    = $this->validKey();
        $stream = (new OpenSslAes256GcmDriver())->spawn(new FixedChunkStream([]), $key);

        $output = $this->drainStream($stream);
        $stream->close();

        // 1 version + 12 nonce + 4 zero length + 16 tag = 33 bytes
        self::assertSame(33, strlen($output));
        self::assertSame(0x02, ord($output[0]));
        self::assertSame(0, unpack('N', substr($output, 13, 4))[1]);
    }

    // ---- Helper ----

    private function drainStream(BackupStream $stream): string
    {
        $buffer = '';
        while (($chunk = $stream->read(128)) !== null) {
            $buffer .= $chunk;
        }
        return $buffer;
    }
}
