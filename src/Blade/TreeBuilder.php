<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * Builds the template tree from the Blade atoms and the HTML tokens.
 *
 * It is strict on purpose. When HTML and Blade don't nest cleanly (a tag
 * opened inside @if and closed outside it, an end tag left out), it throws
 * Unsupported instead of guessing, and the file is edited as code.
 */
final class TreeBuilder
{
    /** Single directives that divide their block into arms. */
    private const SEPARATORS = ['forelse' => ['empty'], 'switch' => ['case', 'default']];

    public function build(string $source): Node
    {
        $atoms = (new BladeScanner)->scan($source);
        $tokens = (new HtmlLexer)->lex($source, $atoms);

        $root = new Node('root');
        $root->end = strlen($source);
        $current = $root;
        $position = 0;

        foreach ($this->events($atoms, $tokens) as [$start, $end, $event]) {
            if ($start > $position) {
                $this->leaf($current, 'text', $source, $position, $start);
            }
            $position = $end;
            $text = substr($source, $start, $end - $start);

            if ($event instanceof Atom) {
                $current = $this->atom($current, $event, $text, $start, $end);

                continue;
            }

            switch ($event['type']) {
                case 'comment':
                case 'doctype':
                    $this->leaf($current, $event['type'], $source, $start, $end);
                    break;

                case 'open':
                    $element = $current->append(new Node('element'));
                    $element->name = $event['name'];
                    $element->open = $text;
                    $element->start = $start;
                    $element->end = $end;
                    foreach ($event['attrs'] as $attr) {
                        foreach (['start', 'end', 'valueStart', 'valueEnd'] as $key) {
                            $attr[$key] = $attr[$key] === null ? null : $attr[$key] - $start;
                        }
                        $element->attrs[] = $attr;
                    }
                    if (! in_array($event['name'], Node::VOID, true) && ! ($event['selfClosing'] && $this->inForeignContent($element))) {
                        $current = $element;
                    }
                    break;

                case 'close':
                    if ($current->type !== 'element' || $current->name !== $event['name']) {
                        throw new Unsupported("</{$event['name']}> does not close ".$this->describe($current), $start);
                    }
                    $current->close = $text;
                    $current->end = $end;
                    $current = $current->parent;
                    break;
            }
        }

        if ($position < strlen($source)) {
            $this->leaf($current, 'text', $source, $position, strlen($source));
        }
        if ($current !== $root) {
            throw new Unsupported($this->describe($current).' is never closed', $current->start);
        }

        return $root;
    }

    /**
     * Atoms and tokens in source order. Atoms inside a tag, an HTML comment
     * or a <script>/<style> body belong to that token.
     *
     * @param  list<Atom>  $atoms
     * @param  list<array<string, mixed>>  $tokens
     * @return list<array{0: int, 1: int, 2: Atom|array<string, mixed>}>
     */
    private function events(array $atoms, array $tokens): array
    {
        $events = [];
        $owned = [];
        $rawFrom = null;
        foreach ($tokens as $token) {
            if ($rawFrom !== null) {
                $owned[] = [$rawFrom, $token['start']];
                $rawFrom = null;
            }
            $owned[] = [$token['start'], $token['end']];
            $events[] = [$token['start'], $token['end'], $token];
            if ($token['type'] === 'open' && ! $token['selfClosing'] && in_array($token['name'], HtmlLexer::RAW_TEXT, true)) {
                $rawFrom = $token['end'];
            }
        }

        foreach ($atoms as $atom) {
            foreach ($owned as [$from, $to]) {
                if ($atom->start >= $from && $atom->end <= $to) {
                    continue 2;
                }
            }
            $events[] = [$atom->start, $atom->end, $atom];
        }

        usort($events, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $events;
    }

    private function atom(Node $current, Atom $atom, string $text, int $start, int $end): Node
    {
        if ($atom->role === Atom::SINGLE) {
            if ($this->isSeparator($current, $atom) === false) {
                throw new Unsupported("@{$atom->name} is inside ".$this->describe($current), $start);
            }
            $leaf = $this->leafFromText($current, 'atom', $text, $start, $end);
            $leaf->kind = $atom->kind;
            $leaf->name = $atom->name;
            $leaf->args = $atom->args;

            return $current;
        }

        if ($atom->role === Atom::OPEN) {
            $block = $current->append(new Node('block'));
            $block->kind = $atom->kind;
            $block->name = $atom->name;
            $block->start = $start;

            return $this->branch($block, $atom, $text, $start);
        }

        // @else, @elseif, @endif…: only valid directly inside the arm they follow.
        if ($current->type !== 'branch') {
            throw new Unsupported($this->label($atom).' arrives while '.$this->describe($current).' is still open', $start);
        }
        $block = $current->parent;
        $current->end = $start;

        if ($atom->role === Atom::BRANCH) {
            return $this->branch($block, $atom, $text, $start);
        }

        $block->close = $text;
        $block->end = $end;

        return $block->parent;
    }

    private function branch(Node $block, Atom $atom, string $text, int $start): Node
    {
        $branch = $block->append(new Node('branch'));
        $branch->kind = $atom->kind;
        $branch->name = $atom->name;
        $branch->args = $atom->args;
        $branch->open = $text;
        $branch->start = $start;

        return $branch;
    }

    /**
     * Null when the directive isn't a separator; true when it is one and
     * sits directly in its block; false when an element is open around it.
     */
    private function isSeparator(Node $current, Atom $atom): ?bool
    {
        if ($atom->kind !== 'directive') {
            return null;
        }
        for ($node = $current; $node !== null; $node = $node->parent) {
            if ($node->type === 'block' && in_array($atom->name, self::SEPARATORS[$node->name] ?? [], true)) {
                // @empty($x) with arguments is its own block, not a @forelse arm.
                if ($atom->name === 'empty' && $atom->args !== null) {
                    return null;
                }

                return $current->type === 'branch' && $current->parent === $node;
            }
        }

        return null;
    }

    private function leaf(Node $parent, string $type, string $source, int $start, int $end): Node
    {
        return $this->leafFromText($parent, $type, substr($source, $start, $end - $start), $start, $end);
    }

    private function leafFromText(Node $parent, string $type, string $text, int $start, int $end): Node
    {
        $leaf = $parent->append(new Node($type));
        $leaf->open = $text;
        $leaf->start = $start;
        $leaf->end = $end;

        return $leaf;
    }

    /** <path />, <circle /> and friends self-close inside <svg> and <math> only. */
    private function inForeignContent(Node $element): bool
    {
        for ($node = $element; $node !== null; $node = $node->parent) {
            if ($node->type === 'element' && in_array($node->name, ['svg', 'math'], true)) {
                return true;
            }
        }

        return false;
    }

    private function label(Atom $atom): string
    {
        return $atom->kind === 'component' ? "</x-{$atom->name}>" : '@'.$atom->name;
    }

    private function describe(Node $node): string
    {
        return match ($node->type) {
            'element' => "<{$node->name}>",
            'branch', 'block' => $node->kind === 'component' ? "<x-{$node->name}>" : '@'.$node->name,
            default => 'the template root',
        };
    }
}
