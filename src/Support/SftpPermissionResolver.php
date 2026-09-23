<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Support;

use Ahmednour\StreamBackup\Exceptions\InvalidConfigException;

/**
 * Resolves the `chmod` modes SftpChunkedUploader applies to uploaded files
 * and the directories it creates on demand.
 *
 * Two ways to configure this, in priority order:
 *
 *   1. Explicit octal modes — `file_mode` / `directory_mode`. These map
 *      1:1 onto `chmod` and are the recommended way to configure this:
 *      there is no naming ambiguity to misread.
 *
 *   2. `visibility` / `directory_visibility` — kept for backwards
 *      compatibility. Despite the name, neither value makes anything
 *      reachable over the network: they only control which *local
 *      accounts on the SFTP server* may read/write the file.
 *
 *        'private' => 0600 (files) / 0700 (directories) — owner-only.
 *        'public'  => 0644 (files) / 0755 (directories) — group/world
 *                     readable on the server's local filesystem only.
 *
 * Both default to 'private' — least-privilege by default.
 */
final class SftpPermissionResolver
{
    private const VISIBILITY_MODES = [
        'private' => ['file' => 0600, 'directory' => 0700],
        'public'  => ['file' => 0644, 'directory' => 0755],
    ];

    /**
     * @param  array<string, mixed>  $config  the `stream-backup.destination` array
     * @return array{0: int, 1: int} [fileMode, directoryMode]
     *
     * @throws InvalidConfigException if an explicit mode is not a valid
     *         octal string/int, or a visibility value is neither
     *         'private' nor 'public'
     */
    public static function resolve(array $config): array
    {
        return [
            self::resolveMode($config, 'file_mode', 'visibility', 'file'),
            self::resolveMode($config, 'directory_mode', 'directory_visibility', 'directory'),
        ];
    }

    private static function resolveMode(array $config, string $modeKey, string $visibilityKey, string $kind): int
    {
        $explicit = $config[$modeKey] ?? null;

        if ($explicit !== null && $explicit !== '') {
            return self::normalizeMode($explicit, $modeKey);
        }

        $visibility = (string) ($config[$visibilityKey] ?? 'private');

        return self::VISIBILITY_MODES[$visibility][$kind]
            ?? throw new InvalidConfigException(
                "stream-backup: invalid SFTP `{$visibilityKey}` value '{$visibility}'. "
                . "Expected 'private' or 'public', or set `{$modeKey}` to an explicit octal mode (e.g. '0640') instead."
            );
    }

    private static function normalizeMode(mixed $value, string $key): int
    {
        if (is_int($value)) {
            return $value;
        }

        // Env vars always arrive as strings — accept '0640', '640', or '0o640',
        // always interpreted as octal regardless of a leading zero.
        $normalized = preg_replace('/^0o/i', '', (string) $value);

        if ($normalized === '' || ! preg_match('/^[0-7]{3,4}$/', $normalized)) {
            throw new InvalidConfigException(
                "stream-backup: `{$key}` must be a valid octal file mode (e.g. '0640'), got '{$value}'."
            );
        }

        return octdec($normalized);
    }
}
