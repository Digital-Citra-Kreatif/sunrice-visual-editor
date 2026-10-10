<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * A node of the template tree. Every node owns the exact source text it was
 * parsed from, so printing an untouched tree gives back the file byte for
 * byte, and an edit changes only the text of the nodes it touches.
 *
 *   root     children
 *   element  open tag + children + close tag
 *   block    a paired directive or component (@if … @endif): branches + close
 *   branch   one arm of a block (@if, @elseif, @else): head + children
 *   text, atom, comment, doctype   leaves: `open` holds their text
 */
final class Node
{
    public const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    public ?Node $parent = null;

    /** @var list<Node> */
    public array $children = [];

    /** Opening tag, branch head, or the whole text of a leaf. */
    public string $open = '';

    /** Closing tag or closing directive. */
    public string $close = '';

    /** Tag name, directive name or component name. */
    public string $name = '';

    /** For atoms: echo | raw_echo | comment | directive | php | verbatim | component. */
    public string $kind = '';

    /** Directive arguments or echo expression. */
    public ?string $args = null;

    /**
     * Element attributes; offsets are relative to `open`.
     *
     * @var list<array{kind: string, name: string, start: int, end: int, valueStart: ?int, valueEnd: ?int, dynamic: bool}>
     */
    public array $attrs = [];

    /** Where the node was in the parsed source (bytes, end exclusive). */
    public int $start = 0;

    public int $end = 0;

    public function __construct(public readonly string $type) {}

    public function append(Node $child): Node
    {
        $child->parent = $this;
        $this->children[] = $child;

        return $child;
    }

    public function print(): string
    {
        $out = $this->open;
        foreach ($this->children as $child) {
            $out .= $child->print();
        }

        return $out.$this->close;
    }

    public function isLeaf(): bool
    {
        return in_array($this->type, ['text', 'atom', 'comment', 'doctype'], true);
    }

    /** True when the opening tag holds a directive or echo outside any attribute value. */
    public function hasCodeAttributes(): bool
    {
        foreach ($this->attrs as $attr) {
            if ($attr['kind'] === 'code') {
                return true;
            }
        }

        return false;
    }

    /**
     * The attribute with this name, when it is written as one (not produced by a directive).
     *
     * @return array{kind: string, name: string, start: int, end: int, valueStart: ?int, valueEnd: ?int, dynamic: bool}|null
     */
    public function attr(string $name): ?array
    {
        foreach ($this->attrs as $attr) {
            if ($attr['kind'] === 'attr' && $attr['name'] === $name) {
                return $attr;
            }
        }

        return null;
    }

    /**
     * Every node below this one, depth first.
     *
     * @return \Generator<int, Node>
     */
    public function walk(): \Generator
    {
        foreach ($this->children as $child) {
            yield $child;
            yield from $child->walk();
        }
    }

    public function index(): int
    {
        return (int) array_search($this, $this->parent->children ?? [], true);
    }
}
