<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * A Blade template opened for visual editing.
 *
 * Template::open() is the gate: it returns a tree only when the file can be
 * read into one and printed back unchanged. Tags that don't nest are kept
 * in the tree as written (see strays()). When Blade's own blocks don't pair
 * up, or Blade can't be read at all, it throws Unsupported and the caller
 * falls back to editing the file as code.
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
     * The place a position in the source is at, as a place is said
     * elsewhere: a node, and whether the position is inside it, after
     * everything it holds, or where the node stands. A position inside a
     * tag, a directive or a text is where that stands.
     *
     * @param  int  $offset  in bytes from the start of the source
     * @return array{0: Node, 1: bool}
     */
    public function at(int $offset): array
    {
        $node = $this->root;
        while (true) {
            if ($node->type === 'block') {
                // Between a block's arms there is nothing: a position is in the arm it comes after the start of.
                $arm = $node->children[0] ?? null;
                foreach ($node->children as $child) {
                    $arm = $child->start < $offset ? $child : $arm;
                }
                if ($arm === null || $offset < $arm->start + strlen($arm->open)) {
                    // In the arm's own line: where the arm stands, and the block when there is none.
                    return [$arm ?? $node, false];
                }
                $node = $arm;
            }
            $into = null;
            foreach ($node->children as $child) {
                if ($offset <= $child->start) {
                    // Before this one: what comes before it is above the place, and it is not.
                    return [$child, false];
                }
                if ($offset < $child->end) {
                    $into = $child;
                    break;
                }
            }
            if ($into === null) {
                return [$node, true];
            }
            // What it holds begins after its opening tag or directive, and ends before what closes it.
            $holds = in_array($into->type, ['element', 'block'], true);
            if (! $holds || $offset < $into->start + strlen($into->open) || $offset > $into->end - strlen($into->close)) {
                return [$into, false];
            }
            $node = $into;
        }
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

    /**
     * Tags and directives kept as written because their other half isn't
     * in the same place. Empty for a template that nests cleanly.
     *
     * @return list<Node>
     */
    public function strays(): array
    {
        return array_values(array_filter(iterator_to_array($this->root->walk(), false), fn (Node $node) => $node->stray !== null));
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
