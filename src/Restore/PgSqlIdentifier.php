<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

/**
 * Helpers for PostgreSQL identifiers as they appear in pg_dump output.
 */
final class PgSqlIdentifier
{
    /** One identifier: "quoted" (with "" escapes) or bare. */
    public const PART = '(?:"(?:[^"]|"")+"|[A-Za-z_][A-Za-z0-9_$]*)';

    /**
     * Restore-set key for a table: the bare name in the default `public`
     * schema, `schema.table` anywhere else.
     */
    public static function tableKey(string $schema, string $table): string
    {
        return ($schema === '' || $schema === 'public') ? $table : "{$schema}.{$table}";
    }

    /**
     * Strip quoting from one identifier part.
     */
    public static function unquote(string $part): string
    {
        if ($part !== '' && $part[0] === '"') {
            return str_replace('""', '"', substr($part, 1, -1));
        }

        return $part;
    }

    /**
     * Quote an identifier for use in SQL.
     */
    public static function quote(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * Quoted `"schema"."table"` for a restore-set key.
     */
    public static function qualifiedFromKey(string $key): string
    {
        [$schema, $table] = self::splitKey($key);

        return self::quote($schema) . '.' . self::quote($table);
    }

    /**
     * @return array{0: string, 1: string} [schema, table]
     */
    public static function splitKey(string $key): array
    {
        $dot = strpos($key, '.');

        return $dot === false ? ['public', $key] : [substr($key, 0, $dot), substr($key, $dot + 1)];
    }

    /**
     * Normalise a user-supplied table name to a restore-set key
     * (`public.users` => `users`).
     */
    public static function normaliseRequested(string $name): string
    {
        $name = trim($name);

        return str_starts_with($name, 'public.') ? substr($name, 7) : $name;
    }

    /**
     * Parse a (possibly quoted) dotted name such as `public."My Table"` into
     * its unquoted parts.
     *
     * @return list<string>
     */
    public static function parseDotted(string $text): array
    {
        if (preg_match_all('/(' . self::PART . ')/', $text, $m) === false) {
            return [];
        }

        return array_map([self::class, 'unquote'], $m[1]);
    }
}
