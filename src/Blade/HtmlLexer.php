<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * Finds HTML tags, comments and doctypes in a Blade template, keeping the
 * exact byte range of each. Blade constructs are masked first, so a ">" or a
 * quote inside `{{ }}` or a directive never ends a tag.
 *
 * Text is not tokenized: whatever lies between tokens is text.
 *
 * @phpstan-type Attr array{kind: 'attr'|'code', name: string, start: int, end: int, valueStart: ?int, valueEnd: ?int, dynamic: bool}
 * @phpstan-type Tok array{type: 'open'|'close'|'comment'|'doctype', start: int, end: int, name: string, attrs: list<Attr>, selfClosing: bool}
 */
final class HtmlLexer
{
    private const MASK = "\x1A";

    /** Elements whose content is not markup. */
    public const RAW_TEXT = ['script', 'style'];

    /**
     * @param  list<Atom>  $atoms
     * @return list<Tok>
     */
    public function lex(string $source, array $atoms): array
    {
        $mask = $source;
        $atomAt = [];
        foreach ($atoms as $atom) {
            $mask = substr_replace($mask, str_repeat(self::MASK, $atom->end - $atom->start), $atom->start, $atom->end - $atom->start);
            $atomAt[$atom->start] = $atom;
        }

        $tokens = [];
        $length = strlen($mask);
        $i = 0;
        while (($i = strpos($mask, '<', $i)) !== false) {
            $token = $this->tokenAt($mask, $i, $length, $atomAt);
            if ($token === null) {
                $i++;

                continue;
            }
            $tokens[] = $token;
            $i = $token['end'];

            // <script> and <style>: skip to the closing tag, the content is text.
            if ($token['type'] === 'open' && ! $token['selfClosing'] && in_array($token['name'], self::RAW_TEXT, true)) {
                $close = stripos($mask, '</'.$token['name'], $i);
                $i = $close === false ? $length : $close;
            }
        }

        return $tokens;
    }

    /**
     * @param  array<int, Atom>  $atomAt
     * @return Tok|null
     */
    private function tokenAt(string $mask, int $i, int $length, array $atomAt): ?array
    {
        if (substr_compare($mask, '<!--', $i, 4) === 0) {
            $end = strpos($mask, '-->', $i + 4);

            return $end === false ? null : $this->token('comment', $i, $end + 3);
        }

        $next = $mask[$i + 1] ?? '';
        if ($next === '!') {
            $end = strpos($mask, '>', $i);

            return $end === false ? null : $this->token('doctype', $i, $end + 1);
        }

        if ($next === '/' && ctype_alpha($mask[$i + 2] ?? '')) {
            $nameLength = strspn($mask, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789:._-', $i + 2);
            $end = strpos($mask, '>', $i + 2 + $nameLength);

            return $end === false ? null : $this->token('close', $i, $end + 1, strtolower(substr($mask, $i + 2, $nameLength)));
        }

        if (! ctype_alpha($next)) {
            return null;
        }

        $nameLength = strspn($mask, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789:._-', $i + 1);
        $name = strtolower(substr($mask, $i + 1, $nameLength));
        $p = $i + 1 + $nameLength;
        $attrs = [];

        while ($p < $length) {
            $p += strspn($mask, " \t\r\n\f", $p);
            $char = $mask[$p] ?? '';
            if ($char === '>') {
                return $this->token('open', $i, $p + 1, $name, $attrs);
            }
            if ($char === '/' && ($mask[$p + 1] ?? '') === '>') {
                return $this->token('open', $i, $p + 2, $name, $attrs, true);
            }
            if ($char === '') {
                break;
            }

            // A directive or echo standing alone in the tag: @class([...]), @selected(...), @if … @endif.
            if ($char === self::MASK && isset($atomAt[$p])) {
                $atom = $atomAt[$p];
                $attrs[] = ['kind' => 'code', 'name' => $atom->kind === 'directive' ? '@'.$atom->name : $atom->kind, 'start' => $p, 'end' => $atom->end, 'valueStart' => null, 'valueEnd' => null, 'dynamic' => true];
                $p = $atom->end;

                continue;
            }

            $start = $p;
            $p += max(1, strcspn($mask, " \t\r\n\f=>/", $p));
            $attrName = substr($mask, $start, $p - $start);
            $valueStart = $valueEnd = null;
            $afterName = $p;

            $q = $p + strspn($mask, " \t\r\n\f", $p);
            if (($mask[$q] ?? '') === '=') {
                $q++;
                $q += strspn($mask, " \t\r\n\f", $q);
                $quote = $mask[$q] ?? '';
                if ($quote === '"' || $quote === "'") {
                    $close = strpos($mask, $quote, $q + 1);
                    if ($close === false) {
                        return null;
                    }
                    $valueStart = $q + 1;
                    $valueEnd = $close;
                    $p = $close + 1;
                } else {
                    $valueStart = $q;
                    $p = $q + strcspn($mask, " \t\r\n\f>", $q);
                    $valueEnd = $p;
                }
            } else {
                $p = $afterName;
            }

            $attrs[] = [
                'kind' => 'attr',
                'name' => strtolower($attrName),
                'start' => $start,
                'end' => $p,
                'valueStart' => $valueStart,
                'valueEnd' => $valueEnd,
                'dynamic' => str_contains(substr($mask, $start, $p - $start), self::MASK),
            ];
        }

        return null;
    }

    /**
     * @param  list<Attr>  $attrs
     * @return Tok
     */
    private function token(string $type, int $start, int $end, string $name = '', array $attrs = [], bool $selfClosing = false): array
    {
        return ['type' => $type, 'start' => $start, 'end' => $end, 'name' => $name, 'attrs' => $attrs, 'selfClosing' => $selfClosing];
    }
}
