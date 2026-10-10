<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * Keeps directives readable where an edit brings two pieces of a file
 * together.
 *
 * Blade only sees "@endif" as a directive when the character before it isn't
 * a letter, digit or "_", and reads "@else" + "more" as the unknown directive
 * "@elsemore". Deleting the echo in `@else{{ $x }}@endif` leaves
 * `@else@endif`, which no longer compiles. One space at the join fixes it.
 */
final class Seams
{
    public static function unsafe(string $left, string $right): bool
    {
        $word = fn (string $char): bool => $char !== '' && (ctype_alnum($char) || $char === '_');

        return (preg_match('/^@\\w/', $right) === 1 && $word(substr($left, -1)))
            || (preg_match('/(?<![\\w@])@\\w+\\z/', $left) === 1 && $word($right[0] ?? ''));
    }

    /**
     * Puts one space where the text before and after a child position would
     * otherwise run together into something Blade misreads.
     *
     * @param  list<int>  $indexes  positions in $parent->children; each seam is just before that child
     */
    public static function repair(Node $parent, array $indexes): void
    {
        $indexes = array_unique($indexes);
        rsort($indexes);
        foreach ($indexes as $index) {
            $before = self::before($parent, $index);
            $after = self::after($parent, $index);

            // "@else" + "(note)": Blade would take the brackets as the
            // directive's own. A space doesn't help; the bracket is written
            // as a character reference, which reads the same in text.
            $next = $parent->children[$index] ?? null;
            if ($next !== null && $next->type === 'text' && preg_match('/(?<![\w@])@\w+[ \t]*\z/', $before) === 1 && preg_match('/^[ \t]*\(/', $next->open) === 1) {
                $next->open = (string) preg_replace('/^([ \t]*)\(/', '$1&#40;', $next->open);

                continue;
            }

            if (self::unsafe($before, $after)) {
                $space = new Node('text');
                $space->open = ' ';
                $space->parent = $parent;
                array_splice($parent->children, $index, 0, [$space]);
            }
        }
    }

    /** Everything the file prints before the child at $index. */
    private static function before(Node $parent, int $index): string
    {
        $out = ($parent->parent === null ? '' : self::before($parent->parent, $parent->index())).$parent->open;
        foreach (array_slice($parent->children, 0, $index) as $child) {
            $out .= $child->print();
        }

        return $out;
    }

    /** Everything the file prints from the child at $index on. */
    private static function after(Node $parent, int $index): string
    {
        $out = '';
        foreach (array_slice($parent->children, $index) as $child) {
            $out .= $child->print();
        }

        return $out.$parent->close.($parent->parent === null ? '' : self::after($parent->parent, $parent->index() + 1));
    }
}
