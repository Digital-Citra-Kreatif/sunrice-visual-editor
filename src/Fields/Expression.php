<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Fields;

/**
 * Reads a PHP expression as far as it says what its value is:
 * `$entry->get('related') ?? []` is a list of articles when `$entry` is a
 * thing. It knows variables, the ways the CMS's values are read (the
 * catalog), `??`, `?:`, and a few functions and list methods. Anything
 * else comes out as unknown (null), never as a guess.
 *
 * It never runs the code.
 */
final class Expression
{
    /** List methods that give one of the items. */
    private const ONE_OF = ['first', 'last', 'sole', 'firstOrFail', 'random', 'pop', 'shift', 'byKey', 'firstWhere', 'current'];

    /** List methods (and those of a query for entries) that give the list again, or part of it. */
    private const SAME_LIST = [
        'take', 'skip', 'slice', 'reject', 'filter', 'where', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'whereTerm', 'search',
        'sortBy', 'sortByDesc', 'sort', 'sortDesc', 'sortKeys', 'reverse', 'values', 'unique', 'shuffle', 'forPage', 'nth', 'only', 'except',
        'get', 'all', 'items', 'paginate', 'limit', 'orderBy', 'orderByDesc', 'latest', 'oldest', 'locale', 'withQueryString', 'collect', 'toBase', 'tap', 'each',
    ];

    private const COMPARING = [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SPACESHIP, T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR];

    /** @var list<array{0: int, 1: string}|string> */
    private array $tokens;

    private int $at = 0;

    /** @var array<string, Shape|null> what the expression sets on its way: `($image =->get('image')) && …` */
    private array $set = [];

    /**
     * @param  array<string, Shape|null>  $known  variables by name, without the "$"
     */
    private function __construct(string $code, private array $known, private readonly Catalog $catalog)
    {
        $this->tokens = self::tokens($code);
    }

    /**
     * What the expression's value is, and the variables it sets.
     *
     * @param  array<string, Shape|null>  $known
     * @return array{0: Shape|null, 1: array<string, Shape|null>}
     */
    public static function read(string $code, array $known, Catalog $catalog): array
    {
        $reading = new self($code, $known, $catalog);
        $shape = $reading->expression();

        return [$reading->done() ? $shape : null, $reading->set];
    }

    /**
     * What a piece of PHP sets, statement by statement: the body of a
     * `@php … @endphp`, or what is between the brackets of `@php(…)`.
     *
     * @param  array<string, Shape|null>  $known
     * @return array<string, Shape|null>
     */
    public static function sets(string $php, array $known, Catalog $catalog): array
    {
        $set = [];
        foreach (self::split($php, ';') as $statement) {
            [, $here] = self::read($statement, [...$known, ...$set], $catalog);
            $set = [...$set, ...$here];
        }

        return $set;
    }

    /**
     * What a loop goes over: from `$entry->get('steps') ?? [] as $index => $step`
     * the name of each item, of its key if it has one, and what an item is.
     *
     * @param  array<string, Shape|null>  $known
     * @return array{item: string, key: string|null, shape: Shape|null}|null null when it is no such loop
     */
    public static function loop(string $arguments, array $known, Catalog $catalog): ?array
    {
        $reading = new self($arguments, $known, $catalog);
        $over = $reading->expression();
        if (! $reading->is(T_AS)) {
            return null;
        }
        $reading->at++;
        $names = [];
        while ($reading->is(T_VARIABLE) || $reading->is(T_DOUBLE_ARROW) || $reading->is('&')) {
            if ($reading->is(T_VARIABLE)) {
                $names[] = substr($reading->text(), 1);
            }
            $reading->at++;
        }
        if ($names === [] || ! $reading->done()) {
            // `as [$a, $b]`, or something that is not a loop at all.
            return null;
        }

        return ['item' => $names[count($names) - 1], 'key' => count($names) > 1 ? $names[0] : null, 'shape' => $over?->isList() ? $over->item : null];
    }

    /**
     * The arguments of a call, each as it is written: from
     * `'cms.partials.card', ['item' => $item]` two pieces.
     *
     * @return list<string>
     */
    public static function arguments(string $code): array
    {
        return self::split($code, ',');
    }

    /**
     * The entries of an array written out, by key: from
     * `['entry' => $item, 'wide' => true]` the code of each value. Entries
     * without a plain text key are left out.
     *
     * @return array<string, string>
     */
    public static function pairs(string $array): array
    {
        $array = trim($array);
        if (! str_starts_with($array, '[') || ! str_ends_with($array, ']')) {
            return [];
        }
        $pairs = [];
        foreach (self::split(substr($array, 1, -1), ',') as $entry) {
            $sides = self::split($entry, T_DOUBLE_ARROW);
            $key = count($sides) === 2 ? self::literal($sides[0]) : null;
            if ($key !== null) {
                $pairs[$key] = trim($sides[1]);
            }
        }

        return $pairs;
    }

    /** The text of a plain quoted string, as PHP would read it; null for anything else. */
    public static function literal(string $code): ?string
    {
        $tokens = self::tokens($code);
        if (count($tokens) !== 1 || ! is_array($tokens[0]) || $tokens[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        $text = $tokens[0][1];

        return $text[0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], substr($text, 1, -1)) : stripcslashes(substr($text, 1, -1));
    }

    // --- Reading ------------------------------------------------------------------

    /** `a ? b : c`, `a ?: c`, and everything below. */
    private function expression(): ?Shape
    {
        $left = $this->operators();
        if (! $this->is('?')) {
            return $left;
        }
        $this->at++;
        if ($this->is(':')) {
            $this->at++;
            $otherwise = $this->expression();

            return $left ?? $otherwise;
        }
        $then = $this->expression();
        $else = null;
        if ($this->is(':')) {
            $this->at++;
            $else = $this->expression();
        }

        return self::either($then, $else);
    }

    /** `a ?? b`, comparisons and arithmetic. */
    private function operators(): ?Shape
    {
        $left = $this->value();
        while (true) {
            if ($this->is(T_COALESCE)) {
                $this->at++;
                $right = $this->value();
                $left ??= $right;
            } elseif ($this->is(T_INSTANCEOF)) {
                $this->at++;
                $this->value();
                $left = Shape::of(Shape::BOOL);
            } elseif ($this->isAny(self::COMPARING) || $this->is('<') || $this->is('>')) {
                $this->at++;
                $this->value();
                $left = Shape::of(Shape::BOOL);
            } elseif ($this->is('+') || $this->is('-') || $this->is('*') || $this->is('/') || $this->is('%')) {
                $this->at++;
                $this->value();
                $left = Shape::of(Shape::NUMBER);
            } elseif ($this->is('.')) {
                $this->at++;
                $this->value();
                $left = Shape::of(Shape::TEXT);
            } else {
                return $left;
            }
        }
    }

    /** One value with everything read from it: `$entry->get('cover')?->url('large')`. */
    private function value(): ?Shape
    {
        if ($this->is('!')) {
            $this->at++;
            $this->value();

            return Shape::of(Shape::BOOL);
        }
        if ($this->is('-') || $this->is('+')) {
            $this->at++;
            $this->value();

            return Shape::of(Shape::NUMBER);
        }
        foreach ([[T_STRING_CAST, Shape::TEXT], [T_INT_CAST, Shape::NUMBER], [T_DOUBLE_CAST, Shape::NUMBER], [T_BOOL_CAST, Shape::BOOL]] as [$cast, $kind]) {
            if ($this->is($cast)) {
                $this->at++;
                $this->value();

                return Shape::of($kind);
            }
        }
        if ($this->is(T_ARRAY_CAST)) {
            $this->at++;
            $shape = $this->value();

            return $shape?->isList() ? $shape : null;
        }

        $shape = $this->first();
        while (true) {
            if ($this->is(T_OBJECT_OPERATOR) || $this->is(T_NULLSAFE_OBJECT_OPERATOR)) {
                $this->at++;
                if (! $this->is(T_STRING)) {
                    // $a->$b, $a->{'b'}: read from something worked out when the page is drawn.
                    $this->skipOne();
                    $shape = null;

                    continue;
                }
                $name = $this->text();
                $this->at++;
                if ($this->is('(')) {
                    [$written, $literal] = $this->call();
                    $shape = $this->member($shape, 'call', $name, $written, $literal);
                } else {
                    $shape = $this->member($shape, 'property', $name, '', null);
                }
            } elseif ($this->is('[')) {
                $from = $this->at + 1;
                $this->at++;
                $this->expression();
                $key = $this->at === $from + 1 && is_array($this->tokens[$from]) && $this->tokens[$from][0] === T_CONSTANT_ENCAPSED_STRING ? self::literal($this->tokens[$from][1]) : null;
                if (! $this->is(']')) {
                    // Not closed: not read.
                    return null;
                }
                $this->at++;
                $shape = $key === null ? ($shape?->isList() ? $shape->item : null) : $this->member($shape, 'key', $key, '', $key);
            } elseif ($this->is(T_DOUBLE_COLON)) {
                $this->at++;
                $this->skipOne();
                if ($this->is('(')) {
                    $this->call();
                }
                $shape = null;
            } else {
                return $shape;
            }
        }
    }

    /** What a value starts with: a variable, a call, something in brackets, a text or a number. */
    private function first(): ?Shape
    {
        if ($this->is(T_VARIABLE)) {
            $name = substr($this->text(), 1);
            $this->at++;
            if ($this->is('=')) {
                // $image = $entry->get('image'): its value is what is set, and the variable is that from here on.
                $this->at++;
                $shape = $this->expression();
                $this->set[$name] = $shape;
                $this->known[$name] = $shape;

                return $shape;
            }

            return $this->known[$name] ?? null;
        }
        if ($this->is('(')) {
            $this->at++;
            $shape = $this->expression();
            if ($this->is(')')) {
                $this->at++;
            }

            return $shape;
        }
        if ($this->is(T_STRING) || $this->is(T_NAME_QUALIFIED) || $this->is(T_NAME_FULLY_QUALIFIED)) {
            $name = ltrim($this->text(), '\\');
            $this->at++;
            // Cms::global('footer'), the same as cms_global('footer').
            if (in_array($name, ['Cms', 'Sunrice\\Cms\\Content'], true) && $this->is(T_DOUBLE_COLON) && is_array($this->tokens[$this->at + 1] ?? null) && preg_match('/^[A-Za-z_]\w*$/', $this->tokens[$this->at + 1][1]) === 1) {
                // Its method's name may be a keyword to PHP's tokens: Cms::global().
                $method = $this->tokens[$this->at + 1][1];
                $this->at += 2;
                if (! $this->is('(')) {
                    return null;
                }
                $arguments = [];
                [, $literal] = $this->call($arguments);

                return in_array($method, ['global', 'menu', 'page', 'localeUrls', 'bodyClass'], true)
                    ? $this->called('cms_'.strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $method)), $literal, $arguments)
                    : null;
            }
            if (! $this->is('(')) {
                return in_array(strtolower($name), ['true', 'false'], true) ? Shape::of(Shape::BOOL) : null;
            }
            $arguments = [];
            [, $literal] = $this->call($arguments);

            return $this->called($name, $literal, $arguments);
        }
        if ($this->is(T_CONSTANT_ENCAPSED_STRING)) {
            $this->at++;

            return Shape::of(Shape::TEXT);
        }
        if ($this->is('"')) {
            // "A text with {$variables} in it"
            for ($this->at++; ! $this->over() && ! $this->is('"'); $this->at++);
            $this->at++;

            return Shape::of(Shape::TEXT);
        }
        if ($this->is(T_LNUMBER) || $this->is(T_DNUMBER)) {
            $this->at++;

            return Shape::of(Shape::NUMBER);
        }
        if ($this->is(T_ISSET) || $this->is(T_EMPTY)) {
            $this->at++;
            $this->skipBrackets('(', ')');

            return Shape::of(Shape::BOOL);
        }
        if ($this->is(T_FN)) {
            // fn ($item) => …: what it gives is not this value's.
            $this->at++;
            $this->skipBrackets('(', ')');
            if ($this->is(T_DOUBLE_ARROW)) {
                $this->at++;
                $this->expression();
            }

            return null;
        }
        if ($this->is(T_FUNCTION)) {
            for ($this->at++; ! $this->over() && ! $this->is('{'); $this->at++);
            $this->skipBrackets('{', '}');

            return null;
        }
        if ($this->is('[')) {
            $this->skipBrackets('[', ']');

            return null;
        }
        if ($this->is(T_NEW)) {
            $this->at++;
            $this->skipOne();
            if ($this->is('(')) {
                $this->call();
            }

            return null;
        }
        $this->skipOne();

        return null;
    }

    /**
     * Reads the brackets of a call. Answers how the arguments are written
     * (without spaces) and, when the first is a plain text, that text.
     *
     * @param  list<Shape|null>  $shapes  set to what each argument is
     * @return array{0: string, 1: string|null}
     */
    private function call(array &$shapes = []): array
    {
        $shapes = [];
        $open = $this->at;
        $this->at++;
        $literal = null;
        for ($number = 0; ! $this->over() && ! $this->is(')'); $number++) {
            if ($this->is(T_STRING) && ($this->tokens[$this->at + 1] ?? null) === ':') {
                $this->at += 2;
            }
            if ($this->is(T_ELLIPSIS)) {
                $this->at++;
            }
            $from = $this->at;
            $shapes[] = $this->expression();
            if ($number === 0 && $this->at === $from + 1 && is_array($this->tokens[$from]) && $this->tokens[$from][0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = self::literal($this->tokens[$from][1]);
            }
            if ($this->is(',')) {
                $this->at++;
            } elseif (! $this->is(')')) {
                // Something this doesn't read: leave the brackets where they close.
                $this->at = $open;
                $this->skipBrackets('(', ')');

                return ['?', null];
            }
        }
        $written = '';
        for ($at = $open + 1; $at < $this->at; $at++) {
            $token = $this->tokens[$at];
            $written .= is_array($token) ? ($token[0] === T_CONSTANT_ENCAPSED_STRING ? "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) self::literal($token[1]))."'" : $token[1]) : $token;
        }
        $this->at++;

        return [$written, $literal];
    }

    /**
     * What a function gives.
     *
     * @param  list<Shape|null>  $arguments
     */
    private function called(string $function, ?string $literal, array $arguments): ?Shape
    {
        return match ($function) {
            'cms_global' => $literal === null ? null : Shape::globals($literal),
            'cms_menu' => Shape::listOf(Shape::of(Shape::MENU_ITEM)),
            'cms_page' => Shape::of(Shape::PAGE),
            'cms_locale_urls' => Shape::listOf(Shape::of(Shape::TEXT)),
            'collect', 'array_values', 'array_filter', 'array_reverse', 'array_slice', 'array_unique' => ($arguments[0] ?? null)?->isList() ? $arguments[0] : null,
            'now', 'today' => Shape::of(Shape::DATE),
            '__', 'trans', 'trans_choice', 'e', 'route', 'url', 'asset', 'config', 'implode', 'ucfirst', 'strtoupper', 'strtolower', 'trim', 'nl2br', 'strip_tags', 'number_format', 'cms_body_class' => Shape::of(Shape::TEXT),
            'count', 'strlen', 'mb_strlen', 'round', 'floor', 'ceil', 'abs', 'min', 'max' => Shape::of(Shape::NUMBER),
            'is_array', 'is_string', 'is_null', 'in_array', 'array_key_exists', 'str_contains', 'str_starts_with', 'str_ends_with', 'filled', 'blank' => Shape::of(Shape::BOOL),
            default => null,
        };
    }

    /**
     * What is read from a value: `->title`, `->get('excerpt')`,
     * `['url']`, `->url('large')`, `->first()`.
     *
     * @param  'property'|'call'|'key'  $how
     * @param  string  $written  the arguments of a call as written, without spaces
     * @param  string|null  $literal  the first argument, or the key, when it is a plain text
     */
    private function member(?Shape $owner, string $how, string $name, string $written, ?string $literal): ?Shape
    {
        if ($owner === null) {
            return null;
        }
        if ($owner->isList()) {
            return match (true) {
                $how !== 'call' => null,
                in_array($name, self::ONE_OF, true) => $owner->item,
                in_array($name, self::SAME_LIST, true) => $owner,
                in_array($name, ['count', 'total'], true) => Shape::of(Shape::NUMBER),
                in_array($name, ['isEmpty', 'isNotEmpty', 'contains', 'has', 'hasPages', 'hasMorePages'], true) => Shape::of(Shape::BOOL),
                default => null,
            };
        }

        // $item->values(): an entry's or a term's fields, by handle.
        if ($how === 'call' && $name === 'values' && $written === '') {
            return match ($owner->kind) {
                Shape::ENTRY => Shape::values('collection', $owner->of),
                Shape::TERM_PAGE => Shape::values('taxonomy', $owner->of),
                default => null,
            };
        }

        $read = match ($how) {
            'property' => '%s->'.$name,
            'call' => '%s->'.$name.'('.$written.')',
            default => "%s['".str_replace(['\\', "'"], ['\\\\', "\\'"], $name)."']",
        };
        $fields = [];
        foreach ($this->catalog->members($owner) as $members) {
            foreach ($members->fields as $field) {
                if (str_replace('%%', '%', $field->read) === $read) {
                    return $field->shape;
                }
                $fields[] = $field;
            }
        }

        // A block's fields and a global set's are read as a property, with get() or as a key, all the same.
        $anyWay = in_array($owner->kind, [Shape::BLOCK, Shape::GLOBALS], true);
        $handle = $how === 'call' ? ($name === 'get' ? $literal : null) : ($how === 'property' || $anyWay ? $name : null);
        if ($handle !== null && ($anyWay || $how === 'call')) {
            foreach ($fields as $field) {
                if (! $field->builtIn && $field->handle === $handle) {
                    return $field->shape;
                }
            }
        }
        if ($how === 'call') {
            // The same method with other arguments: an image's URL at a size the site doesn't list.
            foreach ($fields as $field) {
                if ($field->builtIn && str_starts_with($field->read, '%s->'.$name.'(')) {
                    return $field->shape;
                }
            }
            if ($owner->kind === Shape::DATE) {
                return Shape::of(Shape::TEXT);
            }
        }

        return null;
    }

    /** The one of two that says more: of a list and one of its items, the item. */
    private static function either(?Shape $a, ?Shape $b): ?Shape
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }
        if ($b->isList() && $b->item?->kind === $a->kind) {
            return $a;
        }

        return $b;
    }

    // --- Tokens -------------------------------------------------------------------

    /**
     * @return list<array{0: int, 1: string}|string> what means something: no spaces, no comments
     */
    private static function tokens(string $code): array
    {
        $tokens = [];
        foreach (@token_get_all('<?php '.$code) as $at => $token) {
            if ($at === 0 || (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                continue;
            }
            $tokens[] = is_array($token) ? [$token[0], $token[1]] : $token;
        }

        return $tokens;
    }

    /**
     * Cuts code at a token that is not inside brackets: at each "," or ";".
     *
     * @return list<string> the pieces as written, without those that are empty
     */
    private static function split(string $code, string|int $at): array
    {
        $pieces = [''];
        $depth = 0;
        foreach (@token_get_all('<?php '.$code) as $number => $token) {
            if ($number === 0) {
                continue;
            }
            $text = is_array($token) ? $token[1] : $token;
            if (in_array($text, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            }
            if ($depth === 0 && (is_int($at) ? is_array($token) && $token[0] === $at : $token === $at)) {
                $pieces[] = '';

                continue;
            }
            $pieces[count($pieces) - 1] .= $text;
        }

        return array_values(array_filter(array_map(trim(...), $pieces), fn (string $piece) => $piece !== ''));
    }

    /**
     * Whether the token being read is this one.
     *
     * @phpstan-impure it is asked again after every step forward
     */
    private function is(int|string $what): bool
    {
        $token = $this->tokens[$this->at] ?? null;

        return is_int($what) ? is_array($token) && $token[0] === $what : $token === $what;
    }

    /**
     * @param  list<int>  $kinds
     *
     * @phpstan-impure
     */
    private function isAny(array $kinds): bool
    {
        $token = $this->tokens[$this->at] ?? null;

        return is_array($token) && in_array($token[0], $kinds, true);
    }

    private function text(): string
    {
        $token = $this->tokens[$this->at] ?? '';

        return is_array($token) ? $token[1] : $token;
    }

    /** @phpstan-impure */
    private function over(): bool
    {
        return $this->at >= count($this->tokens);
    }

    /** Whether all of it was read: what is left over was not understood. */
    private function done(): bool
    {
        return $this->over() || ($this->is(';') && $this->at === count($this->tokens) - 1);
    }

    private function skipOne(): void
    {
        if ($this->is('(')) {
            $this->skipBrackets('(', ')');
        } elseif ($this->is('{')) {
            $this->skipBrackets('{', '}');
        } elseif (! $this->over()) {
            $this->at++;
        }
    }

    /** From an opening bracket to past the one that closes it. */
    private function skipBrackets(string $open, string $close): void
    {
        if (! $this->is($open)) {
            return;
        }
        $depth = 0;
        for (; ! $this->over(); $this->at++) {
            if ($this->is($open)) {
                $depth++;
            } elseif ($this->is($close) && --$depth === 0) {
                $this->at++;

                return;
            }
        }
    }
}
