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

    /**
     * Replaces the words of a text node, keeping the whitespace around
     * them. The words are plain text: markup or Blade typed here is shown,
     * not run.
     */
    public static function setText(Node $text, string $words): void
    {
        Content::setText($text, $words);
    }

    /** Changes what an echo prints: `{{ $entry->title }}` → `{{ $entry->get('heading') }}`. */
    public static function setExpression(Node $echo, string $expression): void
    {
        Content::setExpression($echo, $expression);
    }

    /** Switches an echo between escaped `{{ }}` and raw `{!! !!}`. */
    public static function setEchoRaw(Node $echo, bool $raw): void
    {
        Content::setEchoRaw($echo, $raw);
    }

    /**
     * Changes what is between a directive's brackets: the condition of an
     *
     * @if, the loop of a @foreach, the view and data of an @include. Null
     * removes the brackets.
     */
    public static function setArguments(Node $directive, ?string $arguments): void
    {
        Content::setArguments($directive, $arguments);
    }

    /** Replaces a node with Blade written by hand; an empty string removes it. */
    public static function setCode(Node $node, string $code): void
    {
        Content::setCode($node, $code);
    }

    public static function remove(Node $node): void
    {
        $parent = $node->parent;
        $index = $node->index();
        array_splice($parent->children, $index, 1);
        $node->parent = null;
        Seams::repair($parent, [$index]);
    }

    public static function swap(Node $a, Node $b): void
    {
        $parent = $a->parent;
        [$i, $j] = [$a->index(), $b->index()];
        [$parent->children[$i], $parent->children[$j]] = [$b, $a];
        Seams::repair($parent, [$i, $i + 1, $j, $j + 1]);
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
