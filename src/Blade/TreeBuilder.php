<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * Builds the template tree from the Blade atoms and the HTML tokens.
 *
 * It doesn't guess. Where HTML and Blade don't nest cleanly (a tag opened
 * inside @if and closed outside it, an end tag left out), the tag that has
 * no other half in the same place is kept as it is written: a leaf marked
 * stray, shown as code and left where it is. What is between such tags is
 * read as usual. A snippet about to be put into a template is read
 * strictly instead: there, the same things throw Unsupported.
 *
 * Blade's own blocks have to pair up in either case. A block that is never
 * closed throws Unsupported, and the file is edited as code.
 */
final class TreeBuilder
{
    /** Single directives that divide their block into arms: each starts the next arm. */
    private const SEPARATORS = ['forelse' => ['empty'], 'switch' => ['case', 'default']];

    /** @var array<string, true>|null */
    private ?array $directives = null;

    private bool $foreign = false;

    private bool $strict = false;

    /** @var array<int, Node> the block each opening directive or component tag started, by the atom's object id */
    private array $blocks = [];

    /**
     * @param  bool  $foreign  the source belongs inside <svg> or <math>, where a tag may close itself (<path />)
     * @param  bool  $strict  refuse what doesn't nest instead of keeping it as stray tags
     */
    public function build(string $source, bool $foreign = false, bool $strict = false): Node
    {
        $this->foreign = $foreign;
        $this->strict = $strict;
        $this->blocks = [];
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
                    $element = $this->openElement($current, $event['name']);
                    if ($element !== $current) {
                        if ($this->strict) {
                            throw new Unsupported("</{$event['name']}> does not close ".$this->describe($current), $start);
                        }
                        if ($element === null) {
                            // Nothing here for it to close: its opening tag is in another arm, another file, or missing.
                            $this->stray($current, 'tag', $event['name'], $text, $start, $end, "</{$event['name']}> closes nothing here");
                            break;
                        }
                        // It closes an element further out: the ones in between were left open.
                        $current = $this->leaveOpen($current, $element);
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
        if (! $this->strict) {
            $current = $this->leaveOpen($current, $this->arm($current));
        }
        if ($current !== $root) {
            throw new Unsupported($this->describe($current).' is never closed', $current->start);
        }

        return $root;
    }

    /** The element this end tag closes: the nearest one of that name still open in the same arm, or null. */
    private function openElement(Node $current, string $name): ?Node
    {
        $node = $current;
        while ($node->type === 'element') {
            if ($node->name === $name) {
                return $node;
            }
            if ($node->parent === null) {
                break;
            }
            $node = $node->parent;
        }

        return null;
    }

    /** What the elements open at this point are in: an arm of a block, or the root. */
    private function arm(Node $current): Node
    {
        while ($current->type === 'element' && $current->parent !== null) {
            $current = $current->parent;
        }

        return $current;
    }

    /**
     * Gives up on the elements open between $current and $until, which were
     * never closed where they could be. Each opening tag stays as a stray
     * leaf, and what the element held follows it in its parent.
     */
    private function leaveOpen(Node $current, Node $until): Node
    {
        while ($current !== $until && $current->type === 'element' && $current->parent !== null) {
            $parent = $current->parent;

            $tag = new Node('atom');
            $tag->kind = 'tag';
            $tag->name = $current->name;
            $tag->open = $current->open;
            $tag->attrs = $current->attrs;
            $tag->start = $current->start;
            $tag->end = $current->start + strlen($current->open);
            $tag->stray = "<{$current->name}> is not closed here";
            $tag->parent = $parent;
            foreach ($current->children as $child) {
                $child->parent = $parent;
            }
            array_splice($parent->children, $current->index(), 1, [$tag, ...$current->children]);

            $current = $parent;
        }

        return $current;
    }

    private function stray(Node $parent, string $kind, string $name, string $text, int $start, int $end, string $reason): Node
    {
        $leaf = $this->leafFromText($parent, 'atom', $text, $start, $end);
        $leaf->kind = $kind;
        $leaf->name = $name;
        $leaf->stray = $reason;

        return $leaf;
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
            $block = $this->separates($current, $atom);
            if ($block !== null) {
                // @case, @default and @empty start the next arm of their block, as @else does.
                $arm = $this->arm($current);
                $direct = $arm->type === 'branch' && $arm->parent === $block;
                if ($this->strict && ($arm !== $current || ! $direct)) {
                    throw new Unsupported("@{$atom->name} is inside ".$this->describe($current), $start);
                }
                if (! $direct) {
                    // Inside another block that is inside its own: it can't start an arm from there.
                    $this->stray($current, 'directive', $atom->name, $text, $start, $end, "@{$atom->name} is inside ".$this->describe($arm))->args = $atom->args;

                    return $current;
                }
                $current = $this->leaveOpen($current, $arm);
                $current->end = $start;

                return $this->branch($block, $atom, $text, $start);
            }

            $problem = $atom->kind === 'directive' ? $this->unpaired($atom) : null;
            if ($problem !== null && $this->strict) {
                throw new Unsupported($problem, $start);
            }
            $leaf = $this->leafFromText($current, 'atom', $text, $start, $end);
            $leaf->kind = $atom->kind;
            $leaf->name = $atom->name;
            $leaf->args = $atom->args;
            // Half of a block the parser didn't pair up: kept as it is.
            $leaf->stray = $problem;

            return $current;
        }

        if ($atom->role === Atom::OPEN) {
            $block = $current->append(new Node('block'));
            $block->kind = $atom->kind;
            $block->name = $atom->name;
            $block->start = $start;
            $this->blocks[spl_object_id($atom)] = $block;

            return $this->branch($block, $atom, $text, $start);
        }

        // @else, @elseif, @endif…: only valid directly inside the arm they follow.
        $block = $this->blockOf($atom);
        $arm = $this->arm($current);
        if ($block === null || $arm->type !== 'branch' || $arm->parent !== $block) {
            // Its block isn't the one open here: it was opened inside a tag, or another block is in the way.
            if ($this->strict || ($block !== null && $this->isOpen($block, $current))) {
                throw new Unsupported($this->label($atom).' arrives while '.$this->describe($current).' is still open', $start);
            }
            $this->stray($current, $atom->kind, $atom->name, $text, $start, $end, $this->label($atom).' has nothing here to '.($atom->role === Atom::BRANCH ? 'continue' : 'close'))->args = $atom->args;

            return $current;
        }
        if ($arm !== $current) {
            if ($this->strict) {
                throw new Unsupported($this->label($atom).' arrives while '.$this->describe($current).' is still open', $start);
            }
            $current = $this->leaveOpen($current, $arm);
        }
        $current->end = $start;

        if ($atom->role === Atom::BRANCH) {
            return $this->branch($block, $atom, $text, $start);
        }

        $block->close = $text;
        $block->end = $end;

        return $block->parent ?? $block;
    }

    /** The block a continuing or closing atom belongs to, when its opening is in the tree. */
    private function blockOf(Atom $atom): ?Node
    {
        for ($opener = $atom->openedBy; $opener !== null; $opener = $opener->openedBy) {
            if (isset($this->blocks[spl_object_id($opener)])) {
                return $this->blocks[spl_object_id($opener)];
            }
        }

        return null;
    }

    /** Whether the block is among what is open around the current node. */
    private function isOpen(Node $block, Node $current): bool
    {
        for ($node = $current; $node !== null; $node = $node->parent) {
            if ($node === $block) {
                return true;
            }
        }

        return false;
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
     * The block a single directive divides into arms: the nearest @switch
     * around a @case, the nearest @forelse around an @empty. Null for any
     * other directive.
     */
    private function separates(Node $current, Atom $atom): ?Node
    {
        if ($atom->kind !== 'directive') {
            return null;
        }
        for ($node = $current; $node !== null; $node = $node->parent) {
            if ($node->type === 'block' && in_array($atom->name, self::SEPARATORS[$node->name] ?? [], true)) {
                // @empty($x) with arguments is its own block, not a @forelse arm.
                return $atom->name === 'empty' && $atom->args !== null ? null : $node;
            }
        }

        return null;
    }

    /**
     * Why a directive standing alone is half of something, or null when it
     * is whole. The parser pairs @if with @endif and the rest; one it left
     * unpaired has lost its other half, and the file would not compile.
     */
    private function unpaired(Atom $atom): ?string
    {
        $name = strtolower($atom->name);
        $known = $this->directives();

        return match (true) {
            $name === 'empty' && $atom->args === null => '@empty is outside any @forelse',
            in_array($name, ['case', 'default'], true) => "@{$atom->name} is outside any @switch",
            // @section may end with @show or @stop, and with @php, @slot and @lang it has a one-line form.
            in_array($name, ['section', 'php', 'slot', 'lang'], true) => null,
            str_starts_with($name, 'else') && isset($known[$name]) => "@{$atom->name} has nothing before it to continue",
            str_starts_with($name, 'end') && isset($known[substr($name, 3)]) => "@{$atom->name} has nothing to close",
            isset($known['end'.$name]) => "@{$atom->name} is never closed",
            default => null,
        };
    }

    /** @return array<string, true> every directive name Blade knows here, lower-cased */
    private function directives(): array
    {
        return $this->directives ??= array_fill_keys(
            array_map(strtolower(...), [...BladeScanner::compilerDirectives(), ...BladeScanner::customDirectives()]),
            true,
        );
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
        if ($this->foreign) {
            return true;
        }
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
