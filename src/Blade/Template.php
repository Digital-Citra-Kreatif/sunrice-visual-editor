<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * A Blade template opened for visual editing.
 *
 * Template::open() is the gate: it returns a tree only when the file can be
 * read into one and printed back unchanged. Otherwise it throws Unsupported
 * and the caller falls back to editing the file as code.
 */
final class Template
{
    private function __construct(public readonly string $source, public readonly Node $root) {}

    public static function open(string $source): self
    {
        $root = (new TreeBuilder)->build($source);
        if ($root->print() !== $source) {
            throw new Unsupported('The tree does not print back to the same file');
        }

        return new self($source, $root);
    }

    public function print(): string
    {
        return $this->root->print();
    }

    /**
     * Nodes a person can select, move or delete: elements, Blade blocks and
     * standalone Blade constructs.
     *
     * @return list<Node>
     */
    public function selectable(): array
    {
        return array_values(array_filter(
            iterator_to_array($this->root->walk(), false),
            fn (Node $node) => in_array($node->type, ['element', 'block'], true) || ($node->type === 'atom' && $node->kind !== 'comment'),
        ));
    }

    /** @return list<Node> */
    public function elements(): array
    {
        return array_values(array_filter(iterator_to_array($this->root->walk(), false), fn (Node $node) => $node->type === 'element'));
    }

    /** @return list<Node> */
    public function texts(): array
    {
        return array_values(array_filter(
            iterator_to_array($this->root->walk(), false),
            fn (Node $node) => $node->type === 'text' && trim($node->open) !== '' && ! in_array($node->parent?->name, HtmlLexer::RAW_TEXT, true),
        ));
    }
}
