<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * The edits a visual editor makes, applied to the tree. Each one changes the
 * text of the nodes involved and nothing else.
 */
final class Edit
{
    /**
     * Sets an attribute to plain text, or with $blade to a value written as
     * given (`{{ $entry->url }}`). Null writes the bare name (`disabled`).
     */
    public static function setAttribute(Node $element, string $name, ?string $value, bool $blade = false): void
    {
        Attributes::set($element, $name, $value, $blade);
    }

    public static function removeAttribute(Node $element, string $name): void
    {
        Attributes::remove($element, $name);
    }

    public static function addClass(Node $element, string $class): void
    {
        Attributes::addClass($element, $class);
    }

    public static function removeClass(Node $element, string $class): void
    {
        Attributes::removeClass($element, $class);
    }

    /** Sets the id; an empty one removes the attribute. */
    public static function setId(Node $element, string $id): void
    {
        trim($id) === '' ? Attributes::remove($element, 'id') : Attributes::set($element, 'id', trim($id));
    }

    /** Renames the element, both tags. */
    public static function setTag(Node $element, string $tag): void
    {
        Attributes::setTag($element, $tag);
    }

    /** Replaces the words of a text node, keeping the whitespace around them. */
    public static function setText(Node $text, string $words): void
    {
        $leading = substr($text->open, 0, strlen($text->open) - strlen(ltrim($text->open)));
        $trailing = substr($text->open, strlen(rtrim($text->open)));
        $text->open = $leading.$words.$trailing;
        self::repairSeams($text->parent, [$text->index(), $text->index() + 1]);
    }

    public static function remove(Node $node): void
    {
        $parent = $node->parent;
        $index = $node->index();
        array_splice($parent->children, $index, 1);
        $node->parent = null;
        self::repairSeams($parent, [$index]);
    }

    public static function swap(Node $a, Node $b): void
    {
        $parent = $a->parent;
        [$i, $j] = [$a->index(), $b->index()];
        [$parent->children[$i], $parent->children[$j]] = [$b, $a];
        self::repairSeams($parent, [$i, $i + 1, $j, $j + 1]);
    }

    /**
     * Blade only sees "@endif" as a directive when the character before it
     * isn't a letter, digit or "_", and reads "@else" + "more" as the unknown
     * directive "@elsemore". Bringing two pieces together can create either,
     * e.g. deleting the echo in `@else{{ $x }}@endif` leaves `@else@endif`,
     * which no longer compiles.
     */
    public static function unsafeSeam(string $left, string $right): bool
    {
        $word = fn (string $char): bool => $char !== '' && (ctype_alnum($char) || $char === '_');

        return (preg_match('/^@\w/', $right) === 1 && $word(substr($left, -1)))
            || (preg_match('/(?<![\w@])@\w+\z/', $left) === 1 && $word($right[0] ?? ''));
    }

    /**
     * Puts one space where the text before and after a child position would
     * otherwise run together into something Blade misreads.
     *
     * @param  list<int>  $indexes  positions in $parent->children; each seam is just before that child
     */
    private static function repairSeams(Node $parent, array $indexes): void
    {
        rsort($indexes);
        foreach (array_unique($indexes) as $index) {
            if (self::unsafeSeam(self::before($parent, $index), self::after($parent, $index))) {
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

    public static function wrap(Node $node, string $tag, string $class): Node
    {
        $wrapper = new Node('element');
        $wrapper->name = $tag;
        $wrapper->open = '<'.$tag.' class="'.$class.'">';
        $wrapper->close = '</'.$tag.'>';
        $wrapper->parent = $node->parent;
        $node->parent->children[$node->index()] = $wrapper;
        $wrapper->append($node);

        return $wrapper;
    }

    /** Appends a new element showing a field, e.g. <p>{{ $entry->get('intro') }}</p>. */
    public static function appendField(Node $element, string $tag, string $expression): Node
    {
        $child = $element->append(new Node('element'));
        $child->name = $tag;
        $child->open = '<'.$tag.'>';
        $child->close = '</'.$tag.'>';
        $echo = $child->append(new Node('atom'));
        $echo->kind = 'echo';
        $echo->args = $expression;
        $echo->open = '{{ '.$expression.' }}';

        return $child;
    }
}
