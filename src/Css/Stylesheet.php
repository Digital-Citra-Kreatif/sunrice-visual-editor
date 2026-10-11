<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Css;

/**
 * A CSS file read for where things are in it, and changed in place.
 *
 * It is no CSS parser: it does not know what a property means or whether
 * a value is valid. It finds the rules and the declarations in each, with
 * their places in the file, minding comments, quoted text, brackets and
 * `url()`. A change is a cut into the text at one of those places:
 * everything else in the file, its spacing and comments included, is
 * left as it was written. After a change the file is read again, and the
 * change refused unless it did what it meant and nothing more.
 */
final class Stylesheet
{
    /** What a property is named: "color", "-webkit-box-shadow", "--accent". */
    private const PROPERTY = '/^(--[\w-]*|-?[A-Za-z_][\w-]*)$/';

    /** @var list<Rule> the rules at the top of the file */
    public array $rules = [];

    private int $at = 0;

    /** @var list<array{0: string, 1: list<array{0: int, 1: string, 2: string}>}>|null what the file says, once it has been asked */
    private ?array $says = null;

    private readonly int $length;

    private function __construct(public readonly string $source)
    {
        $this->length = strlen($source);
    }

    public static function read(string $source): self
    {
        $sheet = new self($source);
        // A byte order mark is not part of the first selector.
        $sheet->at = str_starts_with($source, "\xEF\xBB\xBF") ? 3 : 0;
        $top = new Rule('', 0, null, $sheet->length, $sheet->length);
        $sheet->block($top, top: true);
        foreach ($top->rules as $rule) {
            $rule->parent = null;
        }
        $sheet->rules = $top->rules;

        return $sheet;
    }

    /**
     * Every rule, at any depth, in the order of the file.
     *
     * @return list<Rule>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->walk() as $each) {
                $all[] = $each;
            }
        }

        return $all;
    }

    /**
     * The rules that style what a selector matches: one of their
     * selectors is exactly it. Under any `@media` or the like.
     *
     * @return list<Rule>
     */
    public function styling(string $selector): array
    {
        $wanted = trim((string) preg_replace('/\s+/', ' ', $selector));

        return array_values(array_filter($this->all(), fn (Rule $rule) => in_array($wanted, $rule->selectors(), true)));
    }

    // --- Changes ------------------------------------------------------------------

    /** The file with one declaration's value changed. */
    public function withValue(Declaration $declaration, string $value): string
    {
        $value = self::value($value, custom: str_starts_with($declaration->property, '--'));
        $changed = substr($this->source, 0, $declaration->valueStart).$value.substr($this->source, $declaration->valueEnd);

        return $this->checked($changed, fn (array $before) => self::replaced($before, $declaration->start, [$declaration->property, $value]), 'The value would not stay inside its declaration.');
    }

    /** The file with one declaration's property named otherwise: its value stays. */
    public function withProperty(Declaration $declaration, string $property): string
    {
        $property = self::property($property);
        if (str_starts_with($property, '--') !== str_starts_with($declaration->property, '--')) {
            // What a custom property may hold, another may not: the value is read by other rules.
            self::value($declaration->value, custom: str_starts_with($property, '--'));
        }
        $changed = substr($this->source, 0, $declaration->start).$property.substr($this->source, $declaration->start + strlen($declaration->property));

        return $this->checked($changed, fn (array $before) => self::replaced($before, $declaration->start, [$property, $declaration->value]), 'The property would not stay inside its declaration.');
    }

    /** The file with a declaration added at the end of a rule's block, written as the ones before it are. */
    public function withDeclaration(Rule $rule, string $property, string $value): string
    {
        if ($rule->open === null) {
            throw new CssRejected('This rule has no block to put a declaration in.');
        }
        $property = self::property($property);
        $value = self::value($value, custom: str_starts_with($property, '--'));

        $last = $rule->declarations === [] ? null : $rule->declarations[count($rule->declarations) - 1];
        if ($last === null) {
            $inside = substr($this->source, $rule->open + 1, $rule->close - $rule->open - 1);
            $lines = str_contains($inside, "\n");
            $piece = $lines ? $this->newline().$this->indentOf($rule->start).'    '.$property.': '.$value.';' : ' '.$property.': '.$value.';'.(trim($inside) === '' && $inside !== '' ? '' : ' ');
            $at = $rule->open + 1;
        } else {
            $closed = $this->source[$last->end - 1] === ';';
            // Between this one and the one before it stands what is put before the new one.
            $previous = count($rule->declarations) > 1 ? $rule->declarations[count($rule->declarations) - 2]->end : $rule->open + 1;
            $between = substr($this->source, $previous, $last->start - $previous);
            if (trim($between) !== '') {
                // A comment or a rule stands between them: go by the line the last one is on.
                $between = str_contains(substr($this->source, $rule->open, $rule->close - $rule->open), "\n") ? $this->newline().$this->indentOf($last->start) : ' ';
            }
            $colon = substr($this->source, $last->start + strlen($last->property), $last->valueStart - $last->start - strlen($last->property));
            // The last one may have no ";": it gets one, right after its value, and the new one is then the one without.
            $piece = ($closed ? '' : ';').$between.$property.(trim($colon) === ':' ? $colon : ': ').$value.($closed ? ';' : '');
            $at = $closed ? $last->end : $last->valueEnd;
        }
        $changed = substr($this->source, 0, $at).$piece.substr($this->source, $at);

        return $this->checked($changed, fn (array $before) => self::inserted($before, $rule, $this, [$property, $value]), 'The declaration would not stay inside its rule.');
    }

    /** The file without one declaration: gone with its line when it had the line to itself. */
    public function without(Declaration $declaration): string
    {
        $from = $declaration->start;
        $to = $declaration->end;
        $newline = strrpos(substr($this->source, 0, $from), "\n");
        $lineStart = $newline === false ? 0 : $newline + 1;
        $lineEnd = strpos($this->source, "\n", $to);
        $alone = trim(substr($this->source, $lineStart, $from - $lineStart)) === '' && $lineEnd !== false && trim(substr($this->source, $to, $lineEnd - $to)) === '';
        if ($alone) {
            $from = $lineStart;
            $to = $lineEnd + 1;
        } else {
            $to += strspn($this->source, " \t", $to);
        }
        $changed = substr($this->source, 0, $from).substr($this->source, $to);

        return $this->checked($changed, fn (array $before) => self::replaced($before, $declaration->start, null), 'Taking the declaration out would change what is around it.');
    }

    /**
     * The file with a new rule at its end.
     *
     * @param  array<string, string>|list<array{0: string, 1: string}>  $declarations  values by property, or [property, value] pairs
     */
    public function withRule(string $selector, array $declarations = []): string
    {
        $selector = trim((string) preg_replace('/\s+/', ' ', $selector));
        if ($selector === '' || preg_match('/[{};]|\/\*/', $selector) === 1) {
            throw new CssRejected('That is not a selector.');
        }
        if (str_starts_with($selector, '@')) {
            throw new CssRejected('A new rule needs a selector: "'.$selector.'" is not one.');
        }
        $newline = $this->newline();
        $lines = '';
        $meant = [];
        foreach ($declarations as $key => $each) {
            [$property, $value] = is_array($each) ? $each : [(string) $key, $each];
            $property = self::property($property);
            $value = self::value($value, custom: str_starts_with($property, '--'));
            $lines .= '    '.$property.': '.$value.';'.$newline;
            $meant[] = [$property, $value];
        }
        $ending = $this->source === '' || str_ends_with($this->source, $newline.$newline) ? '' : (str_ends_with($this->source, $newline) ? $newline : $newline.$newline);
        $changed = $this->source.$ending.$selector.' {'.$newline.$lines.'}'.$newline;

        $after = self::read($changed)->all();
        $new = $after === [] ? null : $after[count($after) - 1];
        if (count($after) !== count($this->all()) + 1 || $new === null || $new->prelude !== $selector || $new->parent !== null
            || array_map(fn (Declaration $each) => [$each->property, $each->value], $new->declarations) !== $meant) {
            throw new CssRejected('The rule would not be read back as it was meant: the file above it may not be closed.');
        }

        return $changed;
    }

    // --- Checking a change ----------------------------------------------------------

    /** The name of a property as it will be written, or a refusal. */
    private static function property(string $property): string
    {
        $property = trim($property);
        if (preg_match(self::PROPERTY, $property) !== 1) {
            throw new CssRejected($property === '' ? 'A declaration needs a property.' : "\"{$property}\" is not the name of a property.");
        }

        return $property;
    }

    /**
     * A value as it will be written, or a refusal: it has to be whole on
     * its own. Every quote, bracket and comment it opens it closes, and
     * it holds no ";" or brace that would end the declaration, wherever
     * in a file it is put. A custom property may hold braces, in pairs.
     */
    private static function value(string $value, bool $custom): string
    {
        $value = trim($value);
        if ($value === '' && ! $custom) {
            throw new CssRejected('A declaration needs a value.');
        }
        $alone = self::read('a{'.($custom ? '--p' : 'p').':'.$value.'}');
        $rule = $alone->rules[0] ?? null;
        if (count($alone->all()) !== 1 || $rule === null || $rule->close !== strlen($alone->source) - 1 || count($rule->declarations) !== 1 || $rule->declarations[0]->value !== $value) {
            throw new CssRejected('That is not one value: it has to close every quote and bracket it opens, and hold no ";", "{" or "}" of its own.');
        }

        return $value;
    }

    /**
     * Reads the changed file and compares what it says with what the
     * file said before, changed the way that was meant.
     *
     * @param  callable(list<array{0: string, 1: list<array{0: int, 1: string, 2: string}>}>): list<array{0: string, 1: list<array{0: int, 1: string, 2: string}>}>  $meant
     */
    private function checked(string $changed, callable $meant, string $otherwise): string
    {
        $strip = fn (array $said): array => array_map(fn (array $rule) => [$rule[0], array_map(fn (array $declaration) => [$declaration[1], $declaration[2]], $rule[1])], $said);
        if ($strip(self::said(self::read($changed))) !== $strip($meant($this->says ??= self::said($this)))) {
            throw new CssRejected($otherwise);
        }

        return $changed;
    }

    /**
     * What a file says, in order: each rule with its declarations.
     *
     * @return list<array{0: string, 1: list<array{0: int, 1: string, 2: string}>}> each rule's prelude, and for each declaration where it starts, its property and its value
     */
    private static function said(self $sheet): array
    {
        return array_map(fn (Rule $rule) => [$rule->prelude, array_map(fn (Declaration $each) => [$each->start, $each->property, $each->value], $rule->declarations)], $sheet->all());
    }

    /**
     * @param  list<array{0: string, 1: list<array{0: int, 1: string, 2: string}>}>  $said
     * @param  array{0: string, 1: string}|null  $with  the property and value in its place; null: nothing
     * @return list<array{0: string, 1: list<array{0: int, 1: string, 2: string}>}>
     */
    private static function replaced(array $said, int $start, ?array $with): array
    {
        foreach ($said as &$rule) {
            foreach ($rule[1] as $index => $declaration) {
                if ($declaration[0] === $start) {
                    array_splice($rule[1], $index, 1, $with === null ? [] : [[$start, $with[0], $with[1]]]);
                }
            }
        }

        return $said;
    }

    /**
     * @param  list<array{0: string, 1: list<array{0: int, 1: string, 2: string}>}>  $said
     * @param  array{0: string, 1: string}  $new
     * @return list<array{0: string, 1: list<array{0: int, 1: string, 2: string}>}>
     */
    private static function inserted(array $said, Rule $into, self $sheet, array $new): array
    {
        foreach ($sheet->all() as $index => $rule) {
            if ($rule === $into) {
                $said[$index][1][] = [0, $new[0], $new[1]];
            }
        }

        return $said;
    }

    /** The space a line starts with: that of the line this place is on. */
    private function indentOf(int $offset): string
    {
        $before = substr($this->source, 0, $offset);
        $line = substr($before, (int) strrpos("\n".$before, "\n"));

        return substr($line, 0, strspn($line, " \t"));
    }

    /** How the file ends its lines. */
    private function newline(): string
    {
        return str_contains($this->source, "\r\n") ? "\r\n" : "\n";
    }

    // --- Reading ------------------------------------------------------------------

    /** Reads what a block holds, to its "}" (left unread) or the end of the file. */
    private function block(Rule $into, bool $top): void
    {
        while (true) {
            $this->skipSpace();
            if ($this->at >= $this->length) {
                return;
            }
            $char = $this->source[$this->at];
            if ($char === '}') {
                if (! $top) {
                    return;
                }
                $this->at++;   // A "}" that closes nothing.

                continue;
            }
            if ($char === ';') {
                $this->at++;

                continue;
            }

            $start = $this->at;
            // A custom property's value may hold braces of its own: --theme: { … };
            $custom = ! $top && substr($this->source, $start, 2) === '--' && preg_match('/\G--[\w-]*\s*:/', $this->source, $match, 0, $start) === 1;
            $end = $this->until($custom);
            $text = substr($this->source, $start, $end - $start);

            if (! $custom && ($this->source[$end] ?? '') === '{') {
                $rule = new Rule(rtrim($text), $start, $end, $this->length, $this->length);
                $rule->parent = $into;
                $this->at = $end + 1;
                $this->block($rule, top: false);
                $rule->close = min($this->at, $this->length);
                $this->at = min($this->at + 1, $this->length);
                $rule->end = $this->at;
                $into->rules[] = $rule;

                continue;
            }

            $closed = ($this->source[$end] ?? '') === ';';
            $this->at = $closed ? $end + 1 : $end;
            if (str_starts_with($text, '@')) {
                $rule = new Rule(rtrim($text), $start, null, $end, $this->at);
                $rule->parent = $into;
                $into->rules[] = $rule;
            } elseif (! $top && preg_match('/^([^\s:;{}()\[\]"\'\/]+)\s*:\s*/', $text, $match) === 1) {
                $value = rtrim(substr($text, strlen($match[0])));
                $valueStart = $start + strlen($match[0]);
                $into->declarations[] = new Declaration($match[1], $value, $start, $valueStart, $valueStart + strlen($value), $this->at);
            }
            // Anything else is not a declaration and not a rule: left where it is, unread.
        }
    }

    /**
     * From where it stands, the place of the ";", "{" or "}" that ends
     * what starts there; the end of the file when nothing does.
     *
     * @param  bool  $custom  a custom property: braces in its value are its own
     */
    private function until(bool $custom): int
    {
        $round = 0;
        $braces = 0;
        for ($at = $this->at; $at < $this->length; $at++) {
            $char = $this->source[$at];
            if ($char === '\\') {
                $at++;
            } elseif ($char === '/' && ($this->source[$at + 1] ?? '') === '*') {
                $end = strpos($this->source, '*/', $at + 2);
                if ($end === false) {
                    return $this->length;
                }
                $at = $end + 1;
            } elseif ($char === '"' || $char === "'") {
                $at = $this->endOfText($at);
            } elseif ($char === '(') {
                // url(…) without quotes: whatever is in it is the address.
                if (preg_match('/url$/i', substr($this->source, max(0, $at - 3), 3)) === 1 && preg_match('/\G\(\s*["\']/', $this->source, $match, 0, $at) !== 1) {
                    $end = strpos($this->source, ')', $at);
                    if ($end === false) {
                        return $this->length;
                    }
                    $at = $end;
                } else {
                    $round++;
                }
            } elseif ($char === '[') {
                $round++;
            } elseif ($char === ')' || $char === ']') {
                $round = max(0, $round - 1);
            } elseif ($round > 0) {
                continue;
            } elseif ($custom && $char === '{') {
                $braces++;
            } elseif ($custom && $char === '}' && $braces > 0) {
                $braces--;
            } elseif ($char === ';' && $braces > 0) {
                continue;
            } elseif ($char === ';' || $char === '}' || (! $custom && $char === '{')) {
                return $at;
            }
        }

        return $this->length;
    }

    /** The place of the quote that closes the text opened at $at; an unclosed text ends with its line. */
    private function endOfText(int $at): int
    {
        $quote = $this->source[$at];
        for ($at++; $at < $this->length; $at++) {
            $char = $this->source[$at];
            if ($char === '\\') {
                $at++;
            } elseif ($char === $quote) {
                return $at;
            } elseif ($char === "\n") {
                return $at - 1;
            }
        }

        return $this->length;
    }

    private function skipSpace(): void
    {
        while ($this->at < $this->length) {
            $this->at += strspn($this->source, " \t\r\n\f", $this->at);
            if (substr($this->source, $this->at, 2) === '/*') {
                $end = strpos($this->source, '*/', $this->at + 2);
                $this->at = $end === false ? $this->length : $end + 2;
            } elseif (substr($this->source, $this->at, 4) === '<!--') {
                $this->at += 4;
            } elseif (substr($this->source, $this->at, 3) === '-->') {
                $this->at += 3;
            } else {
                return;
            }
        }
    }
}
