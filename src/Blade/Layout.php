<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * How a file is laid out around a node: whether it stands on a line of its
 * own, how far that line is indented, and how big one step of indentation
 * is here. Edits that add or move nodes use it so the result looks like the
 * rest of the file; nothing they don't touch is reindented.
 */
final class Layout
{
    /** Used where the file gives no hint. */
    public const STEP = '    ';

    public static function isSpace(?Node $node): bool
    {
        return $node !== null && $node->type === 'text' && trim($node->open) === '';
    }

    /**
     * The indentation of the line a node starts on, when nothing but
     * indentation comes before it on that line; null when it sits inline.
     */
    public static function indent(Node $node): ?string
    {
        if ($node->type === 'branch') {
            return $node->index() === 0 && $node->parent !== null ? self::indent($node->parent) : self::afterNewline($node->parent?->children[$node->index() - 1] ?? null, deep: true);
        }
        if ($node->parent === null) {
            return '';
        }

        $previous = $node->parent->children[$node->index() - 1] ?? null;
        if ($previous === null) {
            // First in its parent: on the parent's line, unless the file starts here.
            return $node->parent->type === 'root' ? '' : null;
        }

        return self::afterNewline($previous);
    }

    /**
     * One step of indentation where the node is: the difference between its
     * line and the line of what holds it, looking further up when that
     * doesn't say.
     */
    public static function step(Node $node): string
    {
        for ($inner = $node; $inner->parent !== null; $inner = $holder) {
            $holder = $inner->parent->type === 'branch' && $inner->parent->parent !== null ? $inner->parent->parent : $inner->parent;
            if ($holder->type === 'root') {
                break;
            }
            $own = self::indent($inner);
            $outer = self::indent($holder);
            if ($own !== null && $outer !== null && $own !== $outer && str_starts_with($own, $outer)) {
                return substr($own, strlen($outer));
            }
        }

        return self::STEP;
    }

    /**
     * Moves the lines of a snippet from one indentation to another. The
     * first line is left alone: where it starts is the caller's business.
     * So is a line that starts inside a <pre> or a <textarea>, where the
     * spaces are part of what is shown, and a snippet with a heredoc in it
     * is returned as it is.
     */
    public static function reindent(string $blade, string $from, string $to): string
    {
        if ($from === $to || ! str_contains($blade, "\n") || str_contains($blade, '<<<')) {
            return $blade;
        }

        $lines = explode("\n", $blade);
        $holding = null;
        foreach ($lines as $i => $line) {
            $held = $holding !== null;
            preg_match_all('/<(\/?)(pre|textarea)\b/i', $line, $tags, PREG_SET_ORDER);
            foreach ($tags as [, $closes, $name]) {
                if ($holding === null && $closes === '') {
                    $holding = strtolower($name);
                } elseif ($closes === '/' && strtolower($name) === $holding) {
                    $holding = null;
                }
            }
            if ($i === 0 || $held || trim($line) === '') {
                continue;
            }
            if ($from === '' || str_starts_with($line, $from)) {
                $lines[$i] = $to.substr($line, strlen($from));
            }
        }

        return implode("\n", $lines);
    }

    public static function space(string $text): Node
    {
        $node = new Node('text');
        $node->open = $text;

        return $node;
    }

    /**
     * The indentation after the last line break of a text node, when the
     * text ends with a line break and indentation; null otherwise.
     * With $deep, looks at the end of whatever the node prints.
     */
    private static function afterNewline(?Node $node, bool $deep = false): ?string
    {
        if ($node === null) {
            return null;
        }
        $text = $node->type === 'text' ? $node->open : ($deep ? $node->print() : null);
        if ($text === null || ($position = strrpos($text, "\n")) === false) {
            return null;
        }
        $tail = substr($text, $position + 1);

        return trim($tail, " \t") === '' ? $tail : null;
    }
}
