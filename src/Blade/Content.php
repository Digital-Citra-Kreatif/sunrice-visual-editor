<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

use ParseError;

/**
 * Changes to what a node says: the words of a text, the expression of an
 * echo, the arguments of a directive, or the node rewritten as code.
 */
final class Content
{
    /** Directives that don't compile without brackets. */
    private const NEEDS_ARGUMENTS = ['if', 'elseif', 'unless', 'isset', 'foreach', 'forelse', 'for', 'while', 'switch', 'case'];

    public static function setText(Node $text, string $words): void
    {
        if ($text->type !== 'text' || $text->parent === null) {
            throw new EditRejected('Only text can be retyped.');
        }
        if ($text->parent->type === 'element' && in_array($text->parent->name, HtmlLexer::RAW_TEXT, true)) {
            throw new EditRejected("The content of <{$text->parent->name}> is code, not text.");
        }

        $leading = substr($text->open, 0, strlen($text->open) - strlen(ltrim($text->open)));
        $trailing = ltrim($text->open) === '' ? '' : substr($text->open, strlen(rtrim($text->open)));
        $text->open = $leading.Inert::text($words).$trailing;

        Seams::repair($text->parent, [$text->index(), $text->index() + 1]);
    }

    public static function setExpression(Node $echo, string $expression): void
    {
        [$open, $before, , $after, $close] = self::echoParts($echo);
        $expression = trim($expression);
        if ($expression === '') {
            throw new EditRejected('An echo needs something to print.');
        }
        self::checkPhp($expression);

        self::replaceEcho($echo, $open.$before.$expression.$after.$close, $echo->kind, $expression);
    }

    public static function setEchoRaw(Node $echo, bool $raw): void
    {
        [, $before, $expression, $after] = self::echoParts($echo);

        self::replaceEcho($echo, ($raw ? '{!!' : '{{').$before.$expression.$after.($raw ? '!!}' : '}}'), $raw ? 'raw_echo' : 'echo', $expression);
    }

    public static function setArguments(Node $directive, ?string $arguments): void
    {
        $isAtom = $directive->type === 'atom' && $directive->kind === 'directive';
        $isArm = $directive->type === 'branch' && $directive->kind === 'directive';
        if ((! $isAtom && ! $isArm) || preg_match('/^@(\w+(?:::\w+)?)([ \t]*)/', $directive->open, $match) !== 1) {
            throw new EditRejected('Only a directive has arguments.');
        }
        // With brackets @empty($x) opens a block; without, it is an arm of @forelse.
        if ($directive->name === 'empty' && ($arguments === null) !== ($directive->args === null)) {
            throw new EditRejected('@empty means something else with and without brackets.');
        }
        if ($arguments !== null && in_array($directive->name, ['else', 'default'], true)) {
            throw new EditRejected("@{$directive->name} takes no arguments.");
        }
        if ($arguments === null && in_array($directive->name, self::NEEDS_ARGUMENTS, true)) {
            throw new EditRejected("@{$directive->name} needs something between its brackets.");
        }

        $new = '@'.$match[1].($arguments === null ? '' : ($directive->args === null ? '' : $match[2]).'('.$arguments.')');
        $atoms = self::scan($new);
        $atom = $atoms[0] ?? null;
        if (count($atoms) !== 1 || $atom->kind !== 'directive' || $atom->start !== 0 || $atom->end !== strlen($new) || $atom->name !== $directive->name || $atom->args !== $arguments) {
            throw new EditRejected('The brackets or quotes in that don\'t match up.');
        }

        $directive->open = $new;
        $directive->args = $arguments;

        // What follows the directive: its first child for an arm, its next sibling otherwise.
        $isArm ? Seams::repair($directive, [0]) : Seams::repair($directive->parent ?? $directive, [$directive->index() + 1]);
    }

    /** @return list<Node> the nodes now in its place */
    public static function setCode(Node $node, string $code): array
    {
        if (! Rules::movable($node) || $node->parent === null) {
            throw new EditRejected('This can\'t be rewritten on its own.');
        }

        $parent = $node->parent;
        $index = $node->index();
        $nodes = Fragment::parse($code, $parent);
        foreach ($nodes as $new) {
            $new->parent = $parent;
        }
        array_splice($parent->children, $index, 1, $nodes);
        $node->parent = null;

        Seams::repair($parent, [$index, $index + count($nodes)]);

        return $nodes;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string} opening delimiter, space, expression, space, closing delimiter
     */
    private static function echoParts(Node $echo): array
    {
        if ($echo->type !== 'atom' || ! in_array($echo->kind, ['echo', 'raw_echo'], true)
            || preg_match('/^(\{\{\{|\{\{|\{!!)(\s*)(.*?)(\s*)(\}\}\}|\}\}|!!\})$/s', $echo->open, $match) !== 1) {
            throw new EditRejected('Only an echo ({{ … }}) prints an expression.');
        }

        return [$match[1], $match[2], $match[3], $match[4], $match[5]];
    }

    private static function replaceEcho(Node $echo, string $new, string $kind, string $expression): void
    {
        $atoms = self::scan($new);
        $atom = $atoms[0] ?? null;
        if (count($atoms) !== 1 || $atom->kind !== $kind || $atom->start !== 0 || $atom->end !== strlen($new) || $atom->args !== $expression) {
            throw new EditRejected('The expression can\'t contain the end of the echo.');
        }

        $echo->open = $new;
        $echo->kind = $kind;
        $echo->args = $expression;
    }

    private static function checkPhp(string $expression): void
    {
        try {
            // In brackets: one expression, not a statement or two.
            $tokens = token_get_all('<?php echo ('.$expression.');', TOKEN_PARSE);
            unset($tokens);
        } catch (ParseError $e) {
            throw new EditRejected('That is not a PHP expression: '.$e->getMessage().'.');
        }
    }

    /** @return list<Atom> */
    private static function scan(string $blade): array
    {
        try {
            return (new BladeScanner)->scan($blade);
        } catch (Unsupported $e) {
            throw new EditRejected($e->getMessage().'.');
        }
    }
}
