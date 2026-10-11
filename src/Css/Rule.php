<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Css;

/**
 * A rule of a stylesheet with where it is in the file: a style rule
 * (`.card { … }`), an at-rule with a block (`@media (…) { … }`) or one
 * without (`@import "a.css";`). Offsets are bytes; ends are exclusive.
 */
final class Rule
{
    public ?Rule $parent = null;

    /** @var list<Declaration> the declarations directly in its block */
    public array $declarations = [];

    /** @var list<Rule> the rules directly in its block */
    public array $rules = [];

    /**
     * @param  string  $prelude  what stands before the block, without the space around it: ".card, .tile", "@media (min-width: 40em)"
     * @param  int  $start  of the prelude
     * @param  int|null  $open  where its "{" is; null for a rule without a block
     * @param  int  $close  where its "}" is (the file's end when it is never closed); for a rule without a block, where its ";" is
     * @param  int  $end  after the "}" or ";"
     */
    public function __construct(
        public readonly string $prelude,
        public readonly int $start,
        public readonly ?int $open,
        public int $close,
        public int $end,
    ) {}

    /** `@media`, `@supports`, `@font-face`…: not a rule that styles what its selector matches. */
    public function isAt(): bool
    {
        return str_starts_with($this->prelude, '@');
    }

    /** The at-rule's name, lower case: "media", "font-face". Empty for a style rule. */
    public function at(): string
    {
        return preg_match('/^@([A-Za-z-]+)/', $this->prelude, $match) === 1 ? strtolower($match[1]) : '';
    }

    /**
     * The selectors of a style rule, one for each between its commas.
     *
     * @return list<string>
     */
    public function selectors(): array
    {
        if ($this->isAt()) {
            return [];
        }
        $selectors = [''];
        $depth = 0;
        $quote = null;
        $prelude = (string) preg_replace('~/\*.*?\*/~s', ' ', $this->prelude);
        for ($at = 0, $length = strlen($prelude); $at < $length; $at++) {
            $char = $prelude[$at];
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
                if ($char === '\\') {
                    $selectors[count($selectors) - 1] .= $char.($prelude[++$at] ?? '');

                    continue;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '\\') {
                $selectors[count($selectors) - 1] .= $char.($prelude[++$at] ?? '');

                continue;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $selectors[] = '';

                continue;
            }
            $selectors[count($selectors) - 1] .= $char;
        }

        return array_values(array_filter(array_map(fn (string $selector) => trim((string) preg_replace('/\s+/', ' ', $selector)), $selectors), fn (string $selector) => $selector !== ''));
    }

    /**
     * The at-rules it is inside, outermost first: ["@media (min-width: 40em)"].
     *
     * @return list<string>
     */
    public function within(): array
    {
        $within = [];
        for ($up = $this->parent; $up !== null; $up = $up->parent) {
            array_unshift($within, $up->prelude);
        }

        return $within;
    }

    public function declaration(string $property): ?Declaration
    {
        // The last one counts, as it does for the browser.
        foreach (array_reverse($this->declarations) as $declaration) {
            if (strcasecmp($declaration->property, $property) === 0 || $declaration->property === $property) {
                return $declaration;
            }
        }

        return null;
    }

    /**
     * It and every rule inside it, in the order of the file.
     *
     * @return \Generator<int, Rule>
     */
    public function walk(): \Generator
    {
        yield $this;
        foreach ($this->rules as $rule) {
            yield from $rule->walk();
        }
    }
}
