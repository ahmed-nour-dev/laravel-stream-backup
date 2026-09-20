<?php

declare(strict_types=1);

namespace Ahmednour\StreamBackup\Restore;

/**
 * Splits a streamed mysqldump SQL block into individual statements, tracking
 * the active statement delimiter and quote/comment context instead of the
 * naive "line ends with `;`" heuristic.
 *
 * mysqldump wraps stored procedures, functions, triggers and events in a
 * `DELIMITER $$ ... $$ DELIMITER ;` block precisely because their bodies
 * contain internal semicolons (e.g. a `BEGIN ... SELECT 1; END`). Splitting on
 * every line-ending `;` — the previous approach — chops such an object into
 * several invalid fragments. This reader instead:
 *
 *   - Recognises `DELIMITER <token>` directive lines and switches the active
 *     terminator without ever emitting the directive itself as a statement to
 *     execute (mysqld does not understand `DELIMITER`; it is a client-side
 *     convention).
 *   - Tracks single-quoted, double-quoted and backtick-quoted spans (with
 *     backslash- and doubled-quote escaping) so a delimiter occurrence inside
 *     a string/identifier literal never terminates a statement early.
 *   - Tracks `--`/`#` line comments and slash-star block comments so a
 *     delimiter occurrence inside a comment is likewise ignored.
 *   - Drops mysqldump's purely decorative comments (the `--` banners around
 *     each section) that precede a statement instead of gluing them onto its
 *     front, which would otherwise defeat {@see StatementFilter}'s prefix
 *     checks (e.g. `LOCK TABLES`). A "versioned" comment (MySQL's
 *     slash-star-bang conditional-execution comment) is never dropped: MySQL
 *     executes its contents conditionally, so it is real SQL, not decoration.
 *     Once a statement has real content, any further comment inside it is
 *     kept verbatim (only the leading position matters).
 *
 * This is a hand-rolled state machine, not a SQL parser: it only needs to
 * know where one statement ends and the next begins, which the above
 * contexts (quote/backtick/line-comment/block-comment) are sufficient for.
 *
 * Streaming contract: input arrives one line at a time via {@see feedLine()}
 * (mirroring `fgets()`), each call returns zero or more statements completed
 * by that line, and any statement still open at end-of-stream is retrieved
 * via {@see flush()}. Memory use is bounded by the longest single statement,
 * never the whole dump.
 */
final class DelimiterAwareStatementReader
{
    /**
     * Matches a standalone `DELIMITER <token>` directive line. Only
     * recognised between statements (see the `hasContent` guard in
     * {@see feedLine()}) and never while inside a quote or block comment
     * carried over from a previous line, so a multi-line string/comment that
     * happens to contain such a line verbatim is not misread as a directive.
     */
    private const DELIMITER_DIRECTIVE_PATTERN = '/^[ \t]*DELIMITER[ \t]+(\S+)[ \t]*\r?\n?$/i';

    /** Active statement terminator; mutated by `DELIMITER` directives. */
    private string $delimiter = ';';

    /** Text accumulated for the statement currently being built. */
    private string $buffer = '';

    /**
     * Whether {@see buffer} contains anything beyond whitespace/decorative
     * comments — i.e. whether it is actually a statement to execute, as
     * opposed to mysqldump commentary (`-- Dumping data for table ...`) or
     * blank lines between statements.
     */
    private bool $hasContent = false;

    private bool $inSingleQuote = false;
    private bool $inDoubleQuote = false;
    private bool $inBacktick = false;
    private bool $inLineComment = false;
    private bool $inBlockComment = false;

    /**
     * Whether the comment currently being scanned is decorative and should
     * be excluded from {@see buffer} rather than kept. Only meaningful while
     * {@see inLineComment} or {@see inBlockComment} is true.
     */
    private bool $discardCurrentComment = false;

    /**
     * Feed the next line of SQL (including its trailing newline, if any) and
     * return the statements it completed.
     *
     * @return list<string> Complete, trimmed statements ready to execute (in
     *                       the order they finished); empty when the line
     *                       only extended the statement currently in
     *                       progress, or was a `DELIMITER` directive.
     */
    public function feedLine(string $line): array
    {
        if (
            ! $this->inSingleQuote && ! $this->inDoubleQuote && ! $this->inBacktick
            && ! $this->inBlockComment && ! $this->hasContent
            && preg_match(self::DELIMITER_DIRECTIVE_PATTERN, $line, $matches) === 1
        ) {
            $this->delimiter = $matches[1];
            $this->buffer = '';

            return [];
        }

        return $this->consume($line);
    }

    /**
     * Retrieve any statement left open once the source stream has ended
     * (mysqldump always terminates its last real statement, but a trailing
     * comment-only tail must not be returned as one).
     */
    public function flush(): ?string
    {
        $statement = $this->hasContent ? trim($this->buffer) : null;

        $this->buffer = '';
        $this->hasContent = false;

        return $statement !== null && $statement !== '' ? $statement : null;
    }

    /**
     * Character-by-character scan of one line, tracking quote/comment context
     * and splitting on the active delimiter wherever it appears outside of
     * those contexts.
     *
     * @return list<string>
     */
    private function consume(string $chunk): array
    {
        $statements = [];
        $length = strlen($chunk);
        $delimiter = $this->delimiter;
        $delimiterLength = strlen($delimiter);
        $i = 0;

        while ($i < $length) {
            $char = $chunk[$i];

            if ($this->inLineComment) {
                if (! $this->discardCurrentComment) {
                    $this->buffer .= $char;
                }

                $i++;

                if ($char === "\n") {
                    $this->inLineComment = false;
                }

                continue;
            }

            if ($this->inBlockComment) {
                if (! $this->discardCurrentComment) {
                    $this->buffer .= $char;
                }

                if ($char === '*' && ($chunk[$i + 1] ?? '') === '/') {
                    if (! $this->discardCurrentComment) {
                        $this->buffer .= '/';
                    }

                    $i += 2;
                    $this->inBlockComment = false;

                    continue;
                }

                $i++;

                continue;
            }

            if ($this->inSingleQuote || $this->inDoubleQuote || $this->inBacktick) {
                $quoteChar = $this->inSingleQuote ? "'" : ($this->inDoubleQuote ? '"' : '`');

                // Backslash escaping applies to string/identifier quoting
                // but not to backtick identifiers (MySQL only doubles those).
                if ($char === '\\' && ! $this->inBacktick && $i + 1 < $length) {
                    $this->buffer .= $char . $chunk[$i + 1];
                    $i += 2;

                    continue;
                }

                if ($char === $quoteChar) {
                    // A doubled quote character is an escaped literal quote,
                    // not the end of the span.
                    if (($chunk[$i + 1] ?? '') === $quoteChar) {
                        $this->buffer .= $quoteChar . $quoteChar;
                        $i += 2;

                        continue;
                    }

                    $this->inSingleQuote = false;
                    $this->inDoubleQuote = false;
                    $this->inBacktick = false;
                }

                $this->buffer .= $char;
                $i++;

                continue;
            }

            // Not inside a quote/comment: check for a comment opening first,
            // since e.g. `--` must not be mistaken for delimiter content.
            if ($char === '-' && ($chunk[$i + 1] ?? '') === '-') {
                $after = $chunk[$i + 2] ?? '';

                // MySQL only treats `--` as a comment when followed by
                // whitespace/end-of-line; otherwise it is not a valid
                // comment marker (kept as ordinary content).
                if ($after === '' || $after === "\n" || $after === "\r" || $after === "\t" || $after === ' ') {
                    $this->inLineComment = true;
                    $this->discardCurrentComment = ! $this->hasContent;

                    if (! $this->discardCurrentComment) {
                        $this->buffer .= '--';
                    }

                    $i += 2;

                    continue;
                }
            }

            if ($char === '#') {
                $this->inLineComment = true;
                $this->discardCurrentComment = ! $this->hasContent;

                if (! $this->discardCurrentComment) {
                    $this->buffer .= $char;
                }

                $i++;

                continue;
            }

            if ($char === '/' && ($chunk[$i + 1] ?? '') === '*') {
                // A "versioned" comment (`/*!... */`) is executed
                // conditionally by MySQL — it is real SQL, never decoration,
                // so it always counts as content and is always kept.
                $isVersioned = ($chunk[$i + 2] ?? '') === '!';

                $this->inBlockComment = true;
                $this->discardCurrentComment = ! $isVersioned && ! $this->hasContent;

                if ($isVersioned) {
                    $this->hasContent = true;
                }

                if (! $this->discardCurrentComment) {
                    $this->buffer .= '/*';
                }

                $i += 2;

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $this->inSingleQuote = $char === "'";
                $this->inDoubleQuote = $char === '"';
                $this->inBacktick = $char === '`';
                $this->hasContent = true;
                $this->buffer .= $char;
                $i++;

                continue;
            }

            if ($char === $delimiter[0] && substr($chunk, $i, $delimiterLength) === $delimiter) {
                if ($this->hasContent) {
                    $statement = trim($this->buffer);

                    if ($statement !== '') {
                        $statements[] = $statement;
                    }
                }

                $this->buffer = '';
                $this->hasContent = false;
                $i += $delimiterLength;

                continue;
            }

            if (! ctype_space($char)) {
                $this->hasContent = true;
                $this->buffer .= $char;
            } elseif ($this->hasContent) {
                $this->buffer .= $char;
            }

            $i++;
        }

        return $statements;
    }
}
