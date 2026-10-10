<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

use Illuminate\Container\Container;
use Illuminate\View\Compilers\BladeCompiler;
use ReflectionClass;
use SplObjectStorage;
use Stillat\BladeParser\Nodes\AbstractNode;
use Stillat\BladeParser\Nodes\CommentNode;
use Stillat\BladeParser\Nodes\Components\ComponentNode;
use Stillat\BladeParser\Nodes\DirectiveNode;
use Stillat\BladeParser\Nodes\EchoNode;
use Stillat\BladeParser\Nodes\LiteralNode;
use Stillat\BladeParser\Nodes\PhpBlockNode;
use Stillat\BladeParser\Nodes\PhpTagNode;
use Stillat\BladeParser\Nodes\VerbatimNode;
use Stillat\BladeParser\Parser\DocumentParser;

/**
 * Finds the Blade constructs in a template with stillat/blade-parser and
 * returns them as atoms with byte offsets and open/branch/close roles.
 */
final class BladeScanner
{
    /** @var (callable(): list<string>)|null */
    private static $customDirectives = null;

    /**
     * Where the names of the application's own Blade directives (@bodyClass…)
     * come from. Without a resolver they are read from Laravel's Blade
     * compiler when there is an application, so nothing needs calling in an
     * app. Pass null to go back to that.
     *
     * @param  (callable(): list<string>)|null  $resolver
     */
    public static function resolveCustomDirectivesUsing(?callable $resolver): void
    {
        self::$customDirectives = $resolver;
    }

    /** @return list<string> */
    public static function customDirectives(): array
    {
        if (self::$customDirectives !== null) {
            return (self::$customDirectives)();
        }

        $container = Container::getInstance();
        if ($container->bound('blade.compiler')) {
            return array_map(strval(...), array_keys($container->make('blade.compiler')->getCustomDirectives()));
        }

        return [];
    }

    /**
     * Every directive the installed Blade compiles. Blade runs "@name" when
     * its compiler has a compileName() method, so the methods are the list;
     * the parser's own list misses newer ones (@includeIsolated), which it
     * would otherwise show as text.
     *
     * @return list<string>
     */
    public static function compilerDirectives(): array
    {
        static $names = null;

        if ($names === null) {
            $names = [];
            if (class_exists(BladeCompiler::class)) {
                foreach ((new ReflectionClass(BladeCompiler::class))->getMethods() as $method) {
                    if (preg_match('/^compile([A-Z]\w*)$/', $method->getName(), $match) === 1) {
                        $names[] = lcfirst($match[1]);
                    }
                }
            }
        }

        return $names;
    }

    /** @return list<Atom> in source order, never overlapping */
    public function scan(string $source): array
    {
        // Built here, not taken from the container: the same parser in an
        // app, in tests and on the command line. It knows Blade's own
        // directives; the application's are added by name.
        $parser = new DocumentParser;
        $parser->setDirectiveNames([...self::customDirectives(), ...self::compilerDirectives()]);
        $parser->parse(self::asBladeReadsIt($source));
        $document = $parser->toDocument();

        if ($document->getErrors()->count() > 0) {
            $error = $document->getErrors()->first();
            throw new Unsupported('Blade syntax error: '.(method_exists($error, 'getErrorMessage') ? $error->getErrorMessage() : 'unknown'));
        }

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

    /**
     * Blade only takes "@name" as a directive when the character before it
     * isn't a letter, digit or underscore: "joe@if.org" is an address. The
     * parser would take it as @if, so those "@" are hidden from it. One
     * byte for one byte: every position stays where it was.
     */
    private static function asBladeReadsIt(string $source): string
    {
        return (string) preg_replace('/(?<=[A-Za-z0-9_])@(?=[A-Za-z_])/', '-', $source);
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
