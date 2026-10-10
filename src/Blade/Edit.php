<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * The edits a visual editor makes, applied to the tree. Each one changes the
 * text of the nodes involved and nothing else.
 *
 * Every method here is an operation of the same name (see Operation): the
 * editor sends operations, and Operations applies and checks them.
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
     * Changes what is between a directive's brackets: a condition, a loop,
     * the view and data of an include. Null removes the brackets.
     */
    public static function setArguments(Node $directive, ?string $arguments): void
    {
        Content::setArguments($directive, $arguments);
    }

    /**
     * Replaces a node with Blade written by hand; an empty string removes it.
     *
     * @return list<Node> the nodes now in its place
     */
    public static function setCode(Node $node, string $code): array
    {
        return Content::setCode($node, $code);
    }

    /**
     * Inserts Blade beside a node or inside one. Beside a node that has a
     * line to itself the new one gets a line and the same indentation.
     *
     * @return list<Node> the inserted nodes
     */
    public static function insertBefore(Node $reference, string $blade): array
    {
        return Structure::insertBefore($reference, $blade);
    }

    /** @return list<Node> the inserted nodes */
    public static function insertAfter(Node $reference, string $blade): array
    {
        return Structure::insertAfter($reference, $blade);
    }

    /** @return list<Node> the inserted nodes */
    public static function append(Node $parent, string $blade): array
    {
        return Structure::append($parent, $blade);
    }

    /** @return list<Node> the inserted nodes */
    public static function prepend(Node $parent, string $blade): array
    {
        return Structure::prepend($parent, $blade);
    }

    /** Deletes a node; one that had a line to itself takes the line with it. */
    public static function remove(Node $node): void
    {
        Structure::remove($node);
    }

    /** Puts a copy of the node right after it. */
    public static function duplicate(Node $node): Node
    {
        return Structure::duplicate($node);
    }

    /**
     * Moves a node before or after another, or inside one as its last child.
     *
     * @param  string  $position  before, after or inside
     * @return Node the node where it is now
     */
    public static function move(Node $node, Node $target, string $position): Node
    {
        return Structure::move($node, $target, $position);
    }

    /** Wraps a node in an element or block written empty: `<div class="card"></div>`, `@if ($show)@endif`, `@auth @endauth`. */
    public static function wrap(Node $node, string $wrapper): Node
    {
        return Structure::wrap($node, $wrapper);
    }

    /**
     * Removes an element's tags or a block's directives and keeps what was inside.
     *
     * @return list<Node> what was inside, where it is now
     */
    public static function unwrap(Node $node): array
    {
        return Structure::unwrap($node);
    }
}
