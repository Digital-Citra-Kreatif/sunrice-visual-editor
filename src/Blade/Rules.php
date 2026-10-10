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
     * A single directive that only means something inside its block: @empty
     * in a @forelse, and @case, @default and @break in a @switch. Never
     * moved or deleted on its own.
     */
    public static function isSeparator(Node $node): bool
    {
        return $node->type === 'atom' && $node->kind === 'directive'
            && (in_array($node->name, ['case', 'default', 'break'], true) || ($node->name === 'empty' && $node->args === null));
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
        return $node->parent !== null && self::selectable($node) && ! self::isSeparator($node) && ! self::insideCode($node);
    }

    /** Can have nodes inserted into it. */
    public static function holdsChildren(Node $node): bool
    {
        return match ($node->type) {
            'root' => true,
            'branch' => ! self::insideCode($node) && (new Classifier)->classify($node->parent ?? $node) !== 'code',
            'element' => $node->close !== '' && ! in_array($node->name, HtmlLexer::RAW_TEXT, true) && ! self::insideCode($node),
            default => false,
        };
    }
}
