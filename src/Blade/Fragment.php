<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * A piece of Blade to put into a template: something typed in a code field,
 * pasted, or picked from the insert list. It is read by the same builder as
 * a file and refused for the same reasons, so nothing enters a template that
 * the tree could not hold.
 */
final class Fragment
{
    /**
     * @param  Node|null  $into  what the snippet will go inside; it decides how the snippet is read (inside <svg>, <path /> closes itself)
     * @return list<Node> the snippet's top-level nodes, belonging to no tree yet
     */
    public static function parse(string $blade, ?Node $into = null): array
    {
        try {
            $root = (new TreeBuilder)->build($blade, self::foreign($into));
        } catch (Unsupported $e) {
            throw new EditRejected('That can\'t be used here: '.$e->getMessage().'.');
        }
        if ($root->print() !== $blade) {
            throw new EditRejected('That can\'t be read back exactly.');
        }

        foreach ($root->children as $node) {
            $node->parent = null;
        }

        return $root->children;
    }

    private static function foreign(?Node $into): bool
    {
        for ($node = $into; $node !== null; $node = $node->parent) {
            if ($node->type === 'element' && in_array($node->name, ['svg', 'math'], true)) {
                return true;
            }
        }

        return false;
    }
}
