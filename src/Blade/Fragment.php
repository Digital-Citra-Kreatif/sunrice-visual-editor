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
     * @return list<Node> the snippet's top-level nodes, belonging to no tree yet
     */
    public static function parse(string $blade): array
    {
        try {
            $root = (new TreeBuilder)->build($blade);
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
}
