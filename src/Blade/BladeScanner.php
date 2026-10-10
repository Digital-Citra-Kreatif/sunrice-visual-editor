<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

use SplObjectStorage;
use Stillat\BladeParser\Document\Document;
use Stillat\BladeParser\Nodes\AbstractNode;
use Stillat\BladeParser\Nodes\CommentNode;
use Stillat\BladeParser\Nodes\Components\ComponentNode;
use Stillat\BladeParser\Nodes\DirectiveNode;
use Stillat\BladeParser\Nodes\EchoNode;
use Stillat\BladeParser\Nodes\LiteralNode;
use Stillat\BladeParser\Nodes\PhpBlockNode;
use Stillat\BladeParser\Nodes\PhpTagNode;
use Stillat\BladeParser\Nodes\VerbatimNode;

/**
 * Finds the Blade constructs in a template with stillat/blade-parser and
 * returns them as atoms with byte offsets and open/branch/close roles.
 */
final class BladeScanner
{
    /** @return list<Atom> in source order, never overlapping */
    public function scan(string $source): array
    {
        $document = Document::fromText($source);
        if ($document->getErrors()->count() > 0) {
            $error = $document->getErrors()->first();
            throw new Unsupported('Blade syntax error: '.(method_exists($error, 'getErrorMessage') ? $error->getErrorMessage() : 'unknown'));
        }
        $document->resolveStructures();

        $byte = $this->byteOffsets($source);
        /** @var SplObjectStorage<AbstractNode, Atom> $atoms */
        $atoms = new SplObjectStorage;
        $list = [];

        foreach ($document->getNodes() as $node) {
            if ($node instanceof LiteralNode || $node->position === null) {
                continue;
            }
            $start = $byte($node->position->startOffset);
            $end = $byte($node->position->endOffset + 1);
            $atom = match (true) {
                $node instanceof EchoNode => new Atom($node->isRaw() ? 'raw_echo' : 'echo', $start, $end, '', trim($node->innerContent)),
                $node instanceof CommentNode => new Atom('comment', $start, $end),
                $node instanceof PhpBlockNode, $node instanceof PhpTagNode => new Atom('php', $start, $end, 'php'),
                $node instanceof VerbatimNode => new Atom('verbatim', $start, $end, 'verbatim'),
                $node instanceof DirectiveNode => new Atom('directive', $start, $end, $node->content, $node->arguments?->innerContent, $this->role($node->isOpenedBy !== null, $node->isClosedBy !== null)),
                $node instanceof ComponentNode => new Atom('component', $start, $end, $node->tagName !== '' ? $node->tagName : (string) $node->name, null, $this->componentRole($node)),
                default => throw new Unsupported('Unknown Blade node '.$node::class, $start),
            };
            $atoms[$node] = $atom;
            $list[] = [$node, $atom];
        }

        foreach ($list as [$node, $atom]) {
            if (($node instanceof DirectiveNode || $node instanceof ComponentNode) && $node->isOpenedBy !== null && isset($atoms[$node->isOpenedBy])) {
                $atom->openedBy = $atoms[$node->isOpenedBy];
            }
        }

        $result = array_column($list, 1);
        usort($result, fn (Atom $a, Atom $b) => $a->start <=> $b->start ?: $b->end <=> $a->end);

        // An echo inside a component tag's attributes belongs to that tag.
        $flat = [];
        $coveredUntil = 0;
        foreach ($result as $atom) {
            if ($atom->start < $coveredUntil) {
                continue;
            }
            $flat[] = $atom;
            $coveredUntil = $atom->end;
        }

        return $flat;
    }

    private function role(bool $opened, bool $closed): string
    {
        return match (true) {
            $opened && $closed => Atom::BRANCH,
            $closed => Atom::OPEN,
            $opened => Atom::CLOSE,
            default => Atom::SINGLE,
        };
    }

    private function componentRole(ComponentNode $node): string
    {
        if ($node->isSelfClosing) {
            return Atom::SINGLE;
        }
        if ($node->isClosingTag) {
            if ($node->isOpenedBy === null) {
                throw new Unsupported("</x-{$node->tagName}> has no opening tag", 0);
            }

            return Atom::CLOSE;
        }
        if ($node->isClosedBy === null) {
            throw new Unsupported("<x-{$node->tagName}> is never closed", 0);
        }

        return Atom::OPEN;
    }

    /**
     * The parser counts characters; the tree works in bytes.
     *
     * @return callable(int): int
     */
    private function byteOffsets(string $source): callable
    {
        if (strlen($source) === mb_strlen($source, 'UTF-8')) {
            return fn (int $offset): int => $offset;
        }
        $map = [];
        $bytes = 0;
        foreach (mb_str_split($source, 1, 'UTF-8') as $i => $char) {
            $map[$i] = $bytes;
            $bytes += strlen($char);
        }
        $map[count($map)] = $bytes;

        return fn (int $offset): int => $map[$offset] ?? $bytes;
    }
}
