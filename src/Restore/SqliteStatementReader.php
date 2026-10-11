<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

/**
 * Splits `sqlite3 .dump` output into statements, chunk by chunk.
 *
 * A statement ends at a `;` that is outside quoted strings/identifiers
 * ('...', "...", `...`, [...]) and comments. A CREATE TRIGGER body contains
 * `;` too, so a trigger only ends at the `END` that closes its BEGIN (CASE ...
 * END expressions inside the body are balanced out).
 *
 * Scanning jumps between special characters with strcspn()/strpos() instead of
 * walking byte by byte, because every row of a large dump passes through here.
 */
final class SqliteStatementReader
{
    private string $buffer = '';

    /** '' (normal), 'line' / 'block' (comments) or the closing quote: ' " ` ] */
    private string $mode = '';

    /** Unconsumed trailing byte that needs look-ahead ('-', '/' or '*'). */
    private string $carry = '';

    /**
     * Feed the next chunk of dump text.
     *
     * @return list<string> Statements completed by this chunk, without the
     *                      terminating semicolon.
     */
    public function feed(string $data): array
    {
        $data = $this->carry . $data;
        $this->carry = '';

        $statements = [];
        $length     = strlen($data);
        $i          = 0;
        $segment    = 0; // start of text not yet appended to the buffer

        while ($i < $length) {
            if ($this->mode !== '') {
                $needle = match ($this->mode) {
                    'line'  => "\n",
                    'block' => '*/',
                    default => $this->mode,
                };
                $end = strpos($data, $needle, $i);

                if ($end === false) {
                    if ($this->mode === 'block' && $data[$length - 1] === '*') {
                        $this->carry = '*';
                    }
                    if ($this->mode !== 'line' && $this->mode !== 'block') {
                        $this->buffer .= substr($data, $segment);
                    }
                    $segment = $i = $length;
                    break;
                }

                if ($this->mode === 'line') {
                    $i = $segment = $end; // the newline itself stays as a separator
                } elseif ($this->mode === 'block') {
                    $i = $segment = $end + 2;
                    $this->buffer .= ' ';
                } else {
                    $i = $end + 1;
                }
                $this->mode = '';
                continue;
            }

            $i += strcspn($data, "'\"`[-/;", $i);
            if ($i >= $length) {
                break;
            }

            $char = $data[$i];

            if ($char === '-' || $char === '/') {
                if ($i + 1 >= $length) {
                    $this->buffer .= substr($data, $segment, $i - $segment);
                    $this->carry = $char;
                    $segment = $i = $length;
                    break;
                }

                $next = $data[$i + 1];
                if (($char === '-' && $next === '-') || ($char === '/' && $next === '*')) {
                    $this->buffer .= substr($data, $segment, $i - $segment);
                    $this->mode = $char === '-' ? 'line' : 'block';
                    $i += 2;
                    $segment = $i;
                    continue;
                }

                $i++;
                continue;
            }

            if ($char === ';') {
                $candidate = $this->buffer . substr($data, $segment, $i - $segment);
                $i++;
                $segment = $i;

                if (self::isOpenTrigger($candidate)) {
                    // Keep the semicolon: it is part of the trigger body.
                    $this->buffer = $candidate . ';';
                    continue;
                }

                $this->buffer = '';
                $statement = trim($candidate);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                continue;
            }

            // A quote opens: keep scanning inside it.
            $this->mode = $char === '[' ? ']' : $char;
            $i++;
        }

        $this->buffer .= substr($data, $segment);

        return $statements;
    }

    /**
     * Flush a trailing statement that has no terminating semicolon.
     *
     * @return list<string>
     */
    public function finalize(): array
    {
        $statement = trim($this->buffer . $this->carry);
        $this->buffer = '';
        $this->carry  = '';

        return $statement === '' ? [] : [$statement];
    }

    /**
     * True when $statement is a CREATE TRIGGER whose BEGIN ... END body has
     * not closed yet, so a `;` at its end must not terminate it.
     */
    private static function isOpenTrigger(string $statement): bool
    {
        if (preg_match('/^\s*CREATE\s+(?:TEMP(?:ORARY)?\s+)?TRIGGER\b/i', $statement) !== 1) {
            return false;
        }

        // Words only: drop everything quoted.
        $words = preg_replace('/\'(?:[^\']|\'\')*\'|"(?:[^"]|"")*"|`[^`]*`|\[[^\]]*\]/', ' ', $statement) ?? '';

        if (preg_match('/\bBEGIN\b/i', $words) !== 1) {
            return true; // still in the trigger header
        }

        $cases = preg_match_all('/\bCASE\b/i', $words);
        $ends  = preg_match_all('/\bEND\b/i', $words);

        return ! ($ends === $cases + 1 && preg_match('/\bEND\s*$/i', $words) === 1);
    }
}
