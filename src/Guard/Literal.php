<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Guard;

/**
 * PHP that is a plain value written out and nothing else: a number, a
 * text in single quotes, true, false, null, or a list of such. Reading it
 * runs nothing, and neither does Blade make anything of it: a text with
 * "@", "{{" or a PHP tag in it is not taken for one.
 */
final class Literal
{
    /** One value: `10`, `-2.5`, `'a text'`, `true`. */
    public static function isScalar(string $php): bool
    {
        return self::is($php, lists: false);
    }

    /** One value, or a list of values, lists in lists included: `[['price', '>', 10]]`. */
    public static function is(string $php, bool $lists = true): bool
    {
        // Nothing Blade reads either: it goes through a template's text before PHP does, and takes
        // a PHP tag, an echo or a directive for one wherever it stands, inside a quoted text too.
        if (preg_match('/<\?|\?>|@|\{\{|\}\}|\{!!|!!\}/', $php) === 1) {
            return false;
        }
        $tokens = [];
        foreach (@token_get_all('<?php '.$php) as $at => $token) {
            if ($at === 0 || (is_array($token) && $token[0] === T_WHITESPACE)) {
                continue;
            }
            $tokens[] = $token;
        }
        $at = 0;

        return $tokens !== [] && self::value($tokens, $at, $lists, 0) && $at === count($tokens);
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function value(array $tokens, int &$at, bool $lists, int $depth): bool
    {
        $token = $tokens[$at] ?? null;
        if ($token === '-') {
            $token = $tokens[++$at] ?? null;

            return is_array($token) && in_array($token[0], [T_LNUMBER, T_DNUMBER], true) && self::plainNumber($token[1]) && ++$at > 0;
        }
        if (is_array($token) && in_array($token[0], [T_LNUMBER, T_DNUMBER], true)) {
            return self::plainNumber($token[1]) && ++$at > 0;
        }
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            // Single quotes only: nothing in them is read as code.
            return $token[1][0] === "'" && ++$at > 0;
        }
        if (is_array($token) && $token[0] === T_STRING) {
            return in_array(strtolower($token[1]), ['true', 'false', 'null'], true) && ++$at > 0;
        }
        if ($token !== '[' || ! $lists || $depth >= 4) {
            return false;
        }
        for ($at++; ($tokens[$at] ?? null) !== ']';) {
            if (! self::value($tokens, $at, $lists, $depth + 1)) {
                return false;
            }
            if (is_array($tokens[$at] ?? null) && $tokens[$at][0] === T_DOUBLE_ARROW) {
                // 'key' => value
                $at++;
                if (! self::value($tokens, $at, $lists, $depth + 1)) {
                    return false;
                }
            }
            if (($tokens[$at] ?? null) === ',') {
                $at++;
            } elseif (($tokens[$at] ?? null) !== ']') {
                return false;
            }
        }
        $at++;

        return true;
    }

    /** A number as people write one: digits, with a point if need be. */
    private static function plainNumber(string $text): bool
    {
        return preg_match('/^\d+(\.\d+)?$/', $text) === 1;
    }
}
