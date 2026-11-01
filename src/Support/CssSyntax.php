<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Support;

/**
 * The CSS check the editor runs when no checker is configured
 * (`syntax_checks.css` null): every `{` `(` `[` closed by its own pair,
 * every string and comment closed. Comments, strings, escapes and an
 * unquoted `url(…)` are read past, so what they hold is never counted.
 *
 * It does not know properties or values: a typo in one is something a
 * browser drops by itself. What it finds is what makes a browser drop the
 * rest of the file, or swallow the rules after it.
 */
final class CssSyntax
{
    private const PAIRS = ['{' => '}', '(' => ')', '[' => ']'];

    /**
     * The first thing wrong, and its line; null when there is none.
     *
     * @return array{line: int, message: string}|null
     */
    public static function problem(string $css): ?array
    {
        $length = strlen($css);
        $line = 1;
        /** @var list<array{0: string, 1: int}> $open */
        $open = [];

        for ($at = 0; $at < $length; $at++) {
            $char = $css[$at];
            if ($char === "\n") {
                $line++;

                continue;
            }
            if ($char === '\\') {
                // An escape: "\{" or ".sm\:flex" is a character of a name, nothing more.
                if ($at + 1 < $length && $css[$at + 1] === "\n") {
                    $line++;
                }
                $at++;

                continue;
            }
            if ($char === '/' && ($css[$at + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $at + 2);
                if ($end === false) {
                    return ['line' => $line, 'message' => 'A comment “/*” is never closed with “*/”.'];
                }
                $line += substr_count($css, "\n", $at, $end - $at);
                $at = $end + 1;

                continue;
            }
            if ($char === '"' || $char === "'") {
                $start = $line;
                for ($at++; $at < $length && $css[$at] !== $char; $at++) {
                    if ($css[$at] === "\n") {
                        return ['line' => $start, 'message' => "A string {$char}…{$char} is not closed on its line."];
                    }
                    if ($css[$at] === '\\' && $at + 1 < $length) {
                        // An escaped line break goes on to the next line: "\r\n" is one.
                        if ($css[$at + 1] === "\r" && ($css[$at + 2] ?? '') === "\n") {
                            $at++;
                        }
                        if ($css[$at + 1] === "\n") {
                            $line++;
                        }
                        $at++;
                    }
                }
                if ($at >= $length) {
                    return ['line' => $start, 'message' => "A string {$char}…{$char} is never closed."];
                }

                continue;
            }
            if ($char === '(' && self::unquotedUrl($css, $at)) {
                // url(images/a.png): what is inside is the address, quotes and all; "\)" is part of it.
                $end = self::urlEnd($css, $at);
                if ($end === null) {
                    return ['line' => $line, 'message' => '“url(” is never closed with “)”.'];
                }
                $line += substr_count($css, "\n", $at, $end - $at);
                $at = $end;

                continue;
            }
            if (isset(self::PAIRS[$char])) {
                $open[] = [$char, $line];

                continue;
            }
            if (in_array($char, self::PAIRS, true)) {
                $last = array_pop($open);
                if ($last === null) {
                    return ['line' => $line, 'message' => "“{$char}” closes nothing: there is no “".array_search($char, self::PAIRS, true).'” before it.'];
                }
                if (self::PAIRS[$last[0]] !== $char) {
                    return ['line' => $line, 'message' => "“{$char}” where “".self::PAIRS[$last[0]]."” was expected, to close the “{$last[0]}” of line {$last[1]}."];
                }
            }
        }

        $last = array_pop($open);

        return $last === null ? null : ['line' => $last[1], 'message' => "“{$last[0]}” is never closed with “".self::PAIRS[$last[0]].'”.'];
    }

    /** Where the unquoted url( opened at $at ends: its first ")" that no "\\" escapes. */
    private static function urlEnd(string $css, int $at): ?int
    {
        for ($at++, $length = strlen($css); $at < $length; $at++) {
            if ($css[$at] === '\\') {
                $at++;
            } elseif ($css[$at] === ')') {
                return $at;
            }
        }

        return null;
    }

    /** The "(" at $at opens url( with an address not in quotes. */
    private static function unquotedUrl(string $css, int $at): bool
    {
        if ($at < 3 || strcasecmp(substr($css, $at - 3, 3), 'url') !== 0) {
            return false;
        }
        if ($at > 3 && preg_match('/[A-Za-z0-9_-]/', $css[$at - 4]) === 1) {
            return false;
        }

        return preg_match('/\G\(\s*[^\s"\')]/', $css, offset: $at) === 1;
    }
}
