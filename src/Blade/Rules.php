<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * What may be done to a node. The editor's panels ask these before offering
 * an action, and the edits refuse what they forbid.
 */
final class Rules
{
    /** Nodes a person can select: elements, Blade blocks, and Blade constructs other than comments. */
    public static function selectable(Node $node): bool
    {
        return in_array($node->type, ['element', 'block'], true) || ($node->type === 'atom' && $node->kind !== 'comment');
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

    /** Can be moved, duplicated, wrapped or deleted as one piece. */
    public static function movable(Node $node): bool
    {
        return $node->parent !== null && self::selectable($node) && ! self::isArmBreak($node) && ! self::insideCode($node);
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
