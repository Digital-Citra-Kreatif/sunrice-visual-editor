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
     * Identifies these exact bytes. Node ids are offsets into this version
     * and mean nothing in another.
     */
    public function version(): string
    {
        return sha1($this->source);
    }

    /** The node with this id (see Node::id()), or null. */
    public function find(string $id): ?Node
    {
        if ($id === 'root') {
            return $this->root;
        }
        foreach ($this->root->walk() as $node) {
            if ($node->id() === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * The template as plain data for the editor's panels.
     *
     * @return array{version: string, bytes: int, tree: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['version' => $this->version(), 'bytes' => strlen($this->source), 'tree' => $this->root->toArray()];
    }

    /**
     * Nodes a person can select, move or delete: elements, Blade blocks and
     * standalone Blade constructs.
     *
     * @return list<Node>
     */
    public function selectable(): array
    {
        return array_values(array_filter(iterator_to_array($this->root->walk(), false), Rules::selectable(...)));
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
