<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Throwable;

/**
 * The variables a template reads without setting them itself: what has to
 * be there, from the page or from the template that includes it, for it to
 * be drawn.
 *
 * Read from the compiled PHP, in the order of the file and without
 * following what the code does: a variable counts by where it first
 * appears. Set there (assigned, the item of a loop, a parameter) it is the
 * template's own. Asked about there with isset() or empty() it may be
 * missing, and what follows is taken to be inside that question. Read with
 * a ?? after it, it may be missing there, which says nothing about the
 * next place. Anything else has to exist. A variable that only a function
 * fills in (the matches of preg_match) is taken for one that has to exist.
 */
final class Variables
{
    /** What Blade and Laravel give every template of a page. */
    private const GIVEN = ['this', 'loop', 'slot', 'component', 'attributes', 'errors', 'app', 'locale'];

    /**
     * @return list<string> names without the "$", in the order they are first read
     */
    public static function needed(string $blade): array
    {
        try {
            $tokens = token_get_all(self::compile($blade));
        } catch (Throwable) {
            return [];
        }

        // Only what means something: no whitespace, comments or the HTML between the PHP.
        $tokens = array_values(array_filter($tokens, fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)));
        $set = self::set($tokens);

        $first = [];
        foreach ($tokens as $at => $token) {
            if (! self::is($token, T_VARIABLE)) {
                continue;
            }
            $name = substr($token[1], 1);
            if (isset($first[$name]) || str_starts_with($name, '__') || in_array($name, self::GIVEN, true) || self::is($tokens[$at - 1] ?? null, T_DOUBLE_COLON)) {
                continue;
            }
            if (isset($set[$at]) || self::asked($tokens, $at)) {
                $first[$name] = 'own';
            } elseif (! self::withFallback($tokens, $at)) {
                $first[$name] = 'needed';
            }
        }

        return array_keys(array_filter($first, fn (string $how) => $how === 'needed'));
    }

    /**
     * Where a variable is given a value: the positions, as keys.
     *
     * @param  list<array{int, string, int}|string>  $tokens
     * @return array<int, true>
     */
    private static function set(array $tokens): array
    {
        $set = [];
        $mark = function (int $from, int $to) use ($tokens, &$set): void {
            for ($at = $from; $at <= $to; $at++) {
                if (self::is($tokens[$at], T_VARIABLE)) {
                    $set[$at] = true;
                }
            }
        };

        foreach ($tokens as $at => $token) {
            $next = $tokens[$at + 1] ?? null;
            if (self::is($token, T_VARIABLE) && ($next === '=' || self::is($next, T_COALESCE_EQUAL))) {
                // $a = …
                $set[$at] = true;
            } elseif (($token === ']' || $token === ')') && $next === '=') {
                // [$a, $b] = … and list($a, $b) = …
                $open = self::opening($tokens, $at);
                if ($token === ']' || self::is($tokens[$open - 1] ?? null, T_LIST)) {
                    $mark($open, $at);
                }
            } elseif (self::is($token, T_AS)) {
                // foreach (… as $key => $item)
                $mark($at, self::closing($tokens, $at) ?? $at);
            } elseif (self::is($token, T_FUNCTION) || self::is($token, T_FN) || self::is($token, T_CATCH)) {
                // function ($a, $b), fn ($a), catch (Exception $e)
                for ($open = $at + 1; isset($tokens[$open]) && $tokens[$open] !== '(' && $tokens[$open] !== '{'; $open++);
                if (($tokens[$open] ?? null) === '(') {
                    $mark($open, self::closing($tokens, $open + 1) ?? $open);
                }
            }
        }

        return $set;
    }

    /**
     * Whether the variable here is only asked about: inside the brackets
     * of an isset() or empty() that is still open here.
     *
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function asked(array $tokens, int $at): bool
    {
        $depth = 0;
        for ($before = $at - 1; $before >= 0; $before--) {
            $token = $tokens[$before];
            if ($token === ')') {
                $depth++;
            } elseif ($token === '(') {
                if ($depth === 0) {
                    if (self::is($tokens[$before - 1] ?? null, T_ISSET) || self::is($tokens[$before - 1] ?? null, T_EMPTY)) {
                        return true;
                    }

                    continue;
                }
                $depth--;
            } elseif ($token === ';' || $token === '{' || $token === '}' || self::is($token, T_OPEN_TAG) || self::is($token, T_OPEN_TAG_WITH_ECHO)) {
                break;
            }
        }

        return false;
    }

    /**
     * Whether the variable here is read with a fallback: $a ?? …,
     * $a->b ?? …, $a['b'] ?? …
     *
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function withFallback(array $tokens, int $at): bool
    {
        for ($after = $at + 1; isset($tokens[$after]); $after++) {
            $token = $tokens[$after];
            if (self::is($token, T_OBJECT_OPERATOR) || self::is($token, T_NULLSAFE_OBJECT_OPERATOR)) {
                $after++;
            } elseif ($token === '[') {
                $after = self::closing($tokens, $after + 1, '[', ']') ?? $after;
            } else {
                return self::is($token, T_COALESCE);
            }
        }

        return false;
    }

    /**
     * The bracket that opens what the one at $at closes.
     *
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function opening(array $tokens, int $at): int
    {
        [$close, $open] = $tokens[$at] === ']' ? [']', '['] : [')', '('];
        $depth = 0;
        for ($before = $at; $before >= 0; $before--) {
            if ($tokens[$before] === $close) {
                $depth++;
            } elseif ($tokens[$before] === $open && --$depth === 0) {
                return $before;
            }
        }

        return $at;
    }

    /**
     * The bracket that closes the one open at $from; null when none does.
     *
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function closing(array $tokens, int $from, string $open = '(', string $close = ')'): ?int
    {
        $depth = 0;
        for ($at = $from; isset($tokens[$at]); $at++) {
            if ($tokens[$at] === $open) {
                $depth++;
            } elseif ($tokens[$at] === $close && $depth-- === 0) {
                return $at;
            }
        }

        return null;
    }

    /** @param  array{int, string, int}|string|null  $token */
    private static function is(array|string|null $token, int $kind): bool
    {
        return is_array($token) && $token[0] === $kind;
    }

    private static function compile(string $blade): string
    {
        $container = Container::getInstance();
        $application = $container->bound('blade.compiler') ? $container->make('blade.compiler') : null;
        if ($application instanceof BladeCompiler) {
            try {
                return $application->compileString($blade);
            } catch (Throwable) {
                // A component this application doesn't have: read the rest.
            }
        }

        $compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());
        $compiler->withoutComponentTags();

        return $compiler->compileString($blade);
    }
}
