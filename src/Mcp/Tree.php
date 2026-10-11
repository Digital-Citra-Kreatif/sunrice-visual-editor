<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Mcp;

/**
 * A template's tree as an agent reads it: the same nodes the editor's
 * panels show, without what only a screen needs (labels, byte ranges).
 */
final class Tree
{
    /**
     * @param  array<string, mixed>  $node  a node as TreeData gives it
     * @return array<string, mixed>
     */
    public static function compact(array $node): array
    {
        $compact = ['id' => $node['id'], 'type' => $node['type']];
        foreach (['kind', 'name', 'atom', 'text', 'expression', 'arguments', 'source', 'stray'] as $key) {
            if (isset($node[$key]) && $node[$key] !== '') {
                $compact[$key] = $node[$key];
            }
        }
        if (isset($node['attributes']) && is_array($node['attributes'])) {
            $compact['attributes'] = array_map(fn (array $attribute) => array_filter([
                'name' => $attribute['name'] ?? null,
                'value' => $attribute['value'] ?? null,
                // Written in Blade: change it with blade: true, or leave it.
                'dynamic' => ($attribute['dynamic'] ?? false) ?: null,
                'code' => ($attribute['code'] ?? false) ? ($attribute['source'] ?? true) : null,
            ], fn ($value) => $value !== null), $node['attributes']);
        }
        // What may be done to it, by name: a node that stays where it is has none of move and delete.
        $compact['can'] = array_keys(array_filter((array) ($node['can'] ?? [])));
        if (isset($node['children']) && is_array($node['children'])) {
            $compact['children'] = array_map(self::compact(...), $node['children']);
        }

        return $compact;
    }
}
