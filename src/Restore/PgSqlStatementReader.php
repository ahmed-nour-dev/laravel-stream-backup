<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

/**
 * Splits pg_dump SQL into statements, line by line.
 *
 * A statement ends at a `;` that is outside single-quoted strings (including
 * E'' strings with backslash escapes), double-quoted identifiers,
 * dollar-quoted bodies ($$ ... $$ / $tag$ ... $tag$) and comments. State
 * carries across lines, so function bodies containing `;` stay in one piece.
 */
final class PgSqlStatementReader
{
    private string $buffer = '';

    private bool $inSingle = false;

    private bool $escapeString = false;

    private bool $inDouble = false;

    private ?string $dollarTag = null;

    private int $blockComment = 0;

    /**
     * True when no statement is partially read.
     */
    public function isIdle(): bool
    {
        return ! $this->inSingle
            && ! $this->inDouble
            && $this->dollarTag === null
            && $this->blockComment === 0
            && trim($this->buffer) === '';
    }

    /**
     * Feed one line (with or without its newline).
     *
     * @return list<string> Statements completed by this line, without the
     *                      trailing semicolon.
     */
    public function push(string $line): array
    {
        $statements = [];
        $length     = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($this->blockComment > 0) {
                if ($char === '*' && ($line[$i + 1] ?? '') === '/') {
                    $this->blockComment--;
                    $i++;
                } elseif ($char === '/' && ($line[$i + 1] ?? '') === '*') {
                    $this->blockComment++;
                    $i++;
                }
                continue;
            }

            if ($this->dollarTag !== null) {
                $end = strpos($line, $this->dollarTag, $i);
                if ($end === false) {
                    $this->buffer .= substr($line, $i);
                    break;
                }
                $this->buffer .= substr($line, $i, $end - $i + strlen($this->dollarTag));
                $i = $end + strlen($this->dollarTag) - 1;
                $this->dollarTag = null;
                continue;
            }

            if ($this->inSingle) {
                $this->buffer .= $char;
                if ($this->escapeString && $char === '\\') {
                    $this->buffer .= $line[$i + 1] ?? '';
                    $i++;
                } elseif ($char === "'") {
                    $this->inSingle = false;
                }
                continue;
            }

            if ($this->inDouble) {
                $this->buffer .= $char;
                if ($char === '"') {
                    $this->inDouble = false;
                }
                continue;
            }

            if ($char === '-' && ($line[$i + 1] ?? '') === '-') {
                $this->buffer .= "\n";
                break; // line comment: ignore the rest of the line
            }

            if ($char === '/' && ($line[$i + 1] ?? '') === '*') {
                $this->blockComment = 1;
                $i++;
                continue;
            }

            if ($char === "'") {
                $prev = $i > 0 ? $line[$i - 1] : '';
                $before = $i > 1 ? $line[$i - 2] : '';
                $this->escapeString = ($prev === 'E' || $prev === 'e') && ! self::isIdentifierChar($before);
                $this->inSingle = true;
                $this->buffer .= $char;
                continue;
            }

            if ($char === '"') {
                $this->inDouble = true;
                $this->buffer .= $char;
                continue;
            }

            if ($char === '$' && ! self::isIdentifierChar($i > 0 ? $line[$i - 1] : '')
                && preg_match('/\G\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/', $line, $m, 0, $i) === 1
            ) {
                $this->dollarTag = $m[0];
                $this->buffer .= $m[0];
                $i += strlen($m[0]) - 1;
                continue;
            }

            if ($char === ';') {
                $statement = trim($this->buffer);
                $this->buffer = '';
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                continue;
            }

            $this->buffer .= $char;
        }

        return $statements;
    }

    /**
     * Flush a trailing statement that has no terminating semicolon.
     *
     * @return list<string>
     */
    public function finalize(): array
    {
        $statement = trim($this->buffer);
        $this->buffer = '';

        return $statement === '' ? [] : [$statement];
    }

    private static function isIdentifierChar(string $char): bool
    {
        return $char !== '' && (ctype_alnum($char) || $char === '_' || $char === '$');
    }
}
