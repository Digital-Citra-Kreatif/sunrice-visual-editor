<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * Changes to where nodes are: insert, move, duplicate, delete, wrap, unwrap.
 *
 * A node on a line of its own stays that way: what is inserted beside it
 * gets a line and the same indentation, and deleting it takes its line with
 * it. Inline nodes are joined and parted without added whitespace.
 */
final class Structure
{
    /**
     * @param  string  $from  the indentation the snippet's lines are written at
     * @return list<Node> the inserted nodes
     */
    public static function insertBefore(Node $reference, string $blade, string $from = ''): array
    {
        $parent = self::siblingsOf($reference);
        $indent = Layout::indent($reference);
        $nodes = Fragment::parse(Layout::reindent($blade, $from, $indent ?? $from), $parent);
        if ($nodes === []) {
            return [];
        }

        return self::splice($parent, $reference->index(), $indent === null ? $nodes : [...$nodes, Layout::space("\n".$indent)], $nodes);
    }

    /** @return list<Node> the inserted nodes */
    public static function insertAfter(Node $reference, string $blade, string $from = ''): array
    {
        $parent = self::siblingsOf($reference);
        $indent = Layout::indent($reference);
        $nodes = Fragment::parse(Layout::reindent($blade, $from, $indent ?? $from), $parent);
        if ($nodes === []) {
            return [];
        }

        return self::splice($parent, $reference->index() + 1, $indent === null ? $nodes : [Layout::space("\n".$indent), ...$nodes], $nodes);
    }

    /**
     * Inserts as the last thing inside an element, an arm of a block or the
     * template.
     *
     * @return list<Node> the inserted nodes
     */
    public static function append(Node $parent, string $blade, string $from = ''): array
    {
        self::mustHold($parent);

        // After the last child that has a line of its own, on a line like it.
        foreach (array_reverse($parent->children) as $child) {
            if (! Layout::isSpace($child)) {
                return Layout::indent($child) === null && ! self::endsLine($child)
                    ? self::inline($parent, count($parent->children), $blade, $from)
                    : self::insertAfter($child, $blade, $from);
            }
        }

        return self::intoEmpty($parent, $blade, $from);
    }

    /** @return list<Node> the inserted nodes */
    public static function prepend(Node $parent, string $blade, string $from = ''): array
    {
        self::mustHold($parent);

        foreach ($parent->children as $child) {
            if (! Layout::isSpace($child)) {
                return Layout::indent($child) === null
                    ? self::inline($parent, $child->index(), $blade, $from)
                    : self::insertBefore($child, $blade, $from);
            }
        }

        return self::intoEmpty($parent, $blade, $from);
    }

    /** Deletes a node. One that had a line to itself takes the line with it. */
    public static function remove(Node $node): void
    {
        self::mustMove($node);
        $parent = $node->parent;
        $index = $node->index();
        $previous = $parent->children[$index - 1] ?? null;
        $next = $parent->children[$index + 1] ?? null;

        $ownLine = Layout::indent($node) !== null
            && ($next === null || ($next->type === 'text' && preg_match('/^[ \t]*(\r?\n|\z)/', $next->open) === 1));

        array_splice($parent->children, $index, 1);
        $node->parent = null;

        if ($ownLine && $previous !== null && $previous->type === 'text') {
            // The line break and indentation that led up to it.
            $previous->open = (string) preg_replace('/\r?\n[ \t]*\z/', '', $previous->open);
            if ($previous->open === '') {
                array_splice($parent->children, $index - 1, 1);
                $index--;
            }
        } elseif ($ownLine && $previous === null && $next !== null) {
            // First in the file: the line break after it.
            $next->open = (string) preg_replace('/^[ \t]*\r?\n/', '', $next->open);
            if ($next->open === '') {
                array_splice($parent->children, $index, 1);
            }
        }

        Seams::repair($parent, [$index]);
    }

    /** Puts a copy of the node right after it. */
    public static function duplicate(Node $node): Node
    {
        self::mustMove($node);
        $indent = Layout::indent($node);

        return self::insertAfter($node, $node->print(), $indent ?? '')[0];
    }

    /**
     * Moves a node before or after another, or inside one as its last child.
     *
     * @param  string  $position  before, after or inside
     */
    public static function move(Node $node, Node $target, string $position): void
    {
        self::mustMove($node);
        for ($up = $target; $up !== null; $up = $up->parent) {
            if ($up === $node) {
                throw new EditRejected('A node can\'t be moved into or beside itself.');
            }
        }
        if (! in_array($position, ['before', 'after', 'inside'], true)) {
            throw new EditRejected("Unknown position \"{$position}\".");
        }
        $position === 'inside' ? self::mustHold($target) : self::siblingsOf($target);
        if (Layout::isSpace($target)) {
            throw new EditRejected('Whitespace is no place to move to.');
        }

        if ($position !== 'inside' && $target->parent === $node->parent) {
            self::reorder($node, $target, $position);

            return;
        }

        $blade = $node->print();
        $from = Layout::indent($node) ?? '';
        self::remove($node);

        match ($position) {
            'before' => self::insertBefore($target, $blade, $from),
            'after' => self::insertAfter($target, $blade, $from),
            'inside' => self::append($target, $blade, $from),
        };
    }

    /**
     * Wraps a node in an element or a block, written as an empty one:
     * `<div class="card"></div>`, `<a href="{{ $url }}"></a>`, `@if ($show)@endif`.
     */
    public static function wrap(Node $node, string $wrapper): Node
    {
        if (! Rules::wrappable($node)) {
            throw new EditRejected('This can\'t be wrapped on its own.');
        }
        $parsed = Fragment::parse($wrapper, $node->parent);
        $outer = $parsed[0] ?? null;
        $holder = $outer?->type === 'block' && count($outer->children) === 1 ? $outer->children[0] : $outer;
        if (count($parsed) !== 1 || $holder === null || $holder->children !== []
            || ! ($holder->type === 'branch' || ($holder->type === 'element' && $holder->close !== '' && ! in_array($holder->name, HtmlLexer::RAW_TEXT, true)))) {
            throw new EditRejected('The wrapper has to be one empty element or block, such as <div class="card"></div>.');
        }

        if ($node->type === 'text') {
            $node = self::wordsOf($node);
        }
        $parent = $node->parent;
        $index = $node->index();
        // Text is wrapped where it stands: the whitespace around it stays outside.
        $indent = $node->type === 'text' ? null : Layout::indent($node);

        if ($indent === null) {
            $inside = [$node];
        } else {
            // On a line of its own: the wrapper takes that line, the node moves in one step.
            $step = Layout::step($node);
            $inside = [
                Layout::space("\n".$indent.$step),
                ...Fragment::parse(Layout::reindent($node->print(), $indent, $indent.$step), $node->parent),
                Layout::space("\n".$indent),
            ];
            $node->parent = null;
        }
        foreach ($inside as $child) {
            $holder->append($child);
        }

        $outer->parent = $parent;
        $parent->children[$index] = $outer;

        Seams::repair($holder, [0, count($holder->children)]);
        Seams::repair($parent, [$index, $index + 1]);

        return $outer;
    }

    /** Removes an element's tags, or a block's directives, and keeps what was inside. */
    public static function unwrap(Node $node): void
    {
        self::mustMove($node);
        $holder = match (true) {
            $node->type === 'element' && $node->close !== '' && ! in_array($node->name, HtmlLexer::RAW_TEXT, true) => $node,
            $node->type === 'block' && count($node->children) === 1 => $node->children[0],
            default => throw new EditRejected($node->type === 'block' ? 'A block with more than one arm can\'t be unwrapped.' : 'There is nothing inside this to keep.'),
        };

        $inside = $holder->children;
        while ($inside !== [] && Layout::isSpace($inside[0])) {
            array_shift($inside);
        }
        while ($inside !== [] && Layout::isSpace($inside[count($inside) - 1])) {
            array_pop($inside);
        }
        if ($inside === []) {
            self::remove($node);

            return;
        }

        $blade = implode('', array_map(fn (Node $child) => $child->print(), $inside));
        $indent = Layout::indent($node);
        $inner = Layout::indent($inside[0]);
        if ($indent !== null && $inner !== null) {
            $blade = Layout::reindent($blade, $inner, $indent);
        }

        $parent = $node->parent;
        $index = $node->index();
        $nodes = Fragment::parse($blade, $parent);
        $node->parent = null;
        array_splice($parent->children, $index, 1);
        self::splice($parent, $index, $nodes, $nodes);
    }

    /**
     * Splits the whitespace off both ends of a text node into nodes of
     * their own and returns the node that is left holding the words.
     */
    private static function wordsOf(Node $text): Node
    {
        $parent = $text->parent;
        $leading = substr($text->open, 0, strlen($text->open) - strlen(ltrim($text->open)));
        $trailing = substr($text->open, strlen(rtrim($text->open)));
        $text->open = trim($text->open);

        if ($trailing !== '') {
            $after = Layout::space($trailing);
            $after->parent = $parent;
            array_splice($parent->children, $text->index() + 1, 0, [$after]);
        }
        if ($leading !== '') {
            $before = Layout::space($leading);
            $before->parent = $parent;
            array_splice($parent->children, $text->index(), 0, [$before]);
        }

        return $text;
    }

    /** Same parent: the nodes change places and the whitespace between them stays where it is. */
    private static function reorder(Node $node, Node $target, string $position): void
    {
        $parent = $node->parent;

        // Text carries the spaces around its words; they are whitespace
        // between nodes like any other and stay where they are.
        foreach ($parent->children as $child) {
            if ($child->type === 'text' && ! Layout::isSpace($child)) {
                self::wordsOf($child);
            }
        }

        $slots = [];
        $order = [];
        foreach ($parent->children as $i => $child) {
            if (! Layout::isSpace($child)) {
                $slots[] = $i;
                if ($child !== $node) {
                    $order[] = $child;
                }
            }
        }
        $at = (int) array_search($target, $order, true) + ($position === 'after' ? 1 : 0);
        array_splice($order, $at, 0, [$node]);

        foreach ($slots as $k => $slot) {
            $parent->children[$slot] = $order[$k];
        }

        Seams::repair($parent, [...$slots, ...array_map(fn (int $slot) => $slot + 1, $slots)]);
    }

    /** @return list<Node> the inserted nodes */
    private static function inline(Node $parent, int $index, string $blade, string $from): array
    {
        $nodes = Fragment::parse(Layout::reindent($blade, $from, ''), $parent);

        return $nodes === [] ? [] : self::splice($parent, $index, $nodes, $nodes);
    }

    /**
     * Into something that holds nothing yet. When it has a line of its own
     * it opens up: the new content goes on a line between its tags.
     *
     * @return list<Node> the inserted nodes
     */
    private static function intoEmpty(Node $parent, string $blade, string $from): array
    {
        $holder = $parent->type === 'branch' && $parent->parent !== null ? $parent->parent : $parent;
        $indent = $parent->type === 'root' ? null : Layout::indent($holder);
        if ($indent === null) {
            return self::inline($parent, count($parent->children), $blade, $from);
        }

        // Whatever whitespace it held is replaced by the lines written here.
        foreach ($parent->children as $space) {
            $space->parent = null;
        }
        $parent->children = [];

        $inner = $indent.Layout::step($holder);
        $nodes = Fragment::parse(Layout::reindent($blade, $from, $inner), $parent);

        return $nodes === [] ? [] : self::splice($parent, 0, [Layout::space("\n".$inner), ...$nodes, Layout::space("\n".$indent)], $nodes);
    }

    /**
     * @param  list<Node>  $insert  everything to put in, whitespace included
     * @param  list<Node>  $nodes  the nodes among them that were asked for
     * @return list<Node>
     */
    private static function splice(Node $parent, int $index, array $insert, array $nodes): array
    {
        foreach ($insert as $node) {
            $node->parent = $parent;
        }
        array_splice($parent->children, $index, 0, $insert);
        Seams::repair($parent, [$index, $index + count($insert)]);

        return $nodes;
    }

    /** Whether the node is the last thing on its line. */
    private static function endsLine(Node $node): bool
    {
        $next = $node->parent?->children[$node->index() + 1] ?? null;

        return $next !== null && $next->type === 'text' && preg_match('/^[ \t]*\r?\n/', $next->open) === 1;
    }

    /** The parent of a node that things can be put beside. */
    private static function siblingsOf(Node $reference): Node
    {
        if ($reference->parent === null || $reference->type === 'branch' || $reference->type === 'root') {
            throw new EditRejected('Nothing can be put beside this.');
        }
        self::mustHold($reference->parent);

        return $reference->parent;
    }

    private static function mustHold(Node $parent): void
    {
        if (! Rules::holdsChildren($parent)) {
            throw new EditRejected('Nothing can be put inside this.');
        }
    }

    private static function mustMove(Node $node): void
    {
        if (! Rules::movable($node)) {
            throw new EditRejected('This can\'t be moved or removed on its own.');
        }
    }
}
