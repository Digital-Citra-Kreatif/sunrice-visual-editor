<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * What may be done to a node. The editor's panels ask these before offering
 * an action, and the edits refuse what they forbid.
 */
final class Rules
{
    /**
     * Nodes that are things of their own to pick up: elements, Blade blocks,
     * Blade constructs and comments. Not text, which is edited where it is,
     * nor the doctype.
     */
    public static function selectable(Node $node): bool
    {
        return in_array($node->type, ['element', 'block', 'atom', 'comment'], true);
    }

    /**
     * The @break that ends an arm of a @switch. It stays where it is, last
     * in its arm: without it the arm would run on into the next one.
     */
    public static function isArmBreak(Node $node): bool
    {
        return $node->type === 'atom' && $node->kind === 'directive' && $node->name === 'break'
            && $node->parent?->type === 'branch' && $node->parent->parent?->name === 'switch';
    }

    /** The part of a @switch before its first @case, where Blade allows nothing but whitespace. */
    public static function isSwitchHead(Node $node): bool
    {
        return $node->type === 'branch' && $node->name === 'switch';
    }

    /** Inside a block the editor treats as code (edited as one piece of text). */
    public static function insideCode(Node $node): bool
    {
        $classifier = new Classifier;
        for ($up = $node->parent; $up !== null; $up = $up->parent) {
            if ($up->type === 'block' && $classifier->classify($up) === 'code') {
                return true;
            }
        }

        return false;
    }

    /**
     * Has an opening tag whose attributes can be edited: an element, or an
     * opening tag left without its end tag.
     */
    public static function hasAttributes(Node $node): bool
    {
        return $node->type === 'element' || ($node->type === 'atom' && $node->kind === 'tag' && ! str_starts_with($node->open, '</'));
    }

    /**
     * The opening tag of a Blade component, with or without an end tag:
     * `<x-sunrice::entries collection="articles">`. Its attributes are what
     * the component is given, and are changed like an element's. It is
     * not an element: nothing is written into it that the component
     * would be handed.
     */
    public static function isComponentTag(Node $node): bool
    {
        return $node->kind === 'component' && in_array($node->type, ['branch', 'atom'], true) && str_starts_with($node->open, '<x-');
    }

    /**
     * Holds a stray tag or directive somewhere inside. Its other half is
     * elsewhere in the file, so the node can't leave, be copied or go.
     */
    public static function holdsStray(Node $node): bool
    {
        foreach ($node->children as $child) {
            if ($child->stray !== null || self::holdsStray($child)) {
                return true;
            }
        }

        return false;
    }

    /** Can be moved, duplicated, wrapped or deleted as one piece. */
    public static function movable(Node $node): bool
    {
        return $node->parent !== null && self::selectable($node) && $node->stray === null
            && ! self::isArmBreak($node) && ! self::insideCode($node) && ! self::holdsStray($node);
    }

    /** Can be wrapped in an element or a block: anything movable, and text. */
    public static function wrappable(Node $node): bool
    {
        if ($node->type !== 'text') {
            return self::movable($node);
        }

        return $node->parent !== null && trim($node->open) !== '' && ! self::insideCode($node)
            && ! ($node->parent->type === 'element' && in_array($node->parent->name, HtmlLexer::RAW_TEXT, true));
    }

    /** Can have nodes inserted into it. */
    public static function holdsChildren(Node $node): bool
    {
        return match ($node->type) {
            'root' => true,
            'branch' => ! self::isSwitchHead($node) && ! self::insideCode($node) && (new Classifier)->classify($node->parent ?? $node) !== 'code',
            'element' => $node->close !== '' && ! in_array($node->name, HtmlLexer::RAW_TEXT, true) && ! self::insideCode($node),
            default => false,
        };
    }
}
