<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Css;

/** Selectors for the classes of an element. */
final class Selector
{
    /**
     * The selector for a class, as CSS wants it written: "card" → ".card",
     * "sm:flex" → ".sm\:flex", "10px" → ".\31 0px" (what CSS.escape does).
     */
    public static function forClass(string $class): string
    {
        $out = '';
        $chars = preg_split('//u', $class, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $index => $char) {
            $code = mb_ord($char, 'UTF-8');
            $digit = $code >= 0x30 && $code <= 0x39;
            $out .= match (true) {
                $code === 0 => "\u{FFFD}",
                $code <= 0x1F || $code === 0x7F,
                $digit && ($index === 0 || ($index === 1 && $chars[0] === '-')) => '\\'.dechex($code).' ',
                $char === '-' && count($chars) === 1 => '\\-',
                $code >= 0x80 || $char === '-' || $char === '_' || $digit || ctype_alpha($char) => $char,
                default => '\\'.$char,
            };
        }

        return '.'.$out;
    }

    /** Whether a selector names the class anywhere in it: ".card:hover", ".panel > .card", not ".card-title". */
    public static function mentions(string $selector, string $class): bool
    {
        return preg_match('/(?<!\\\\)'.preg_quote(self::forClass($class), '/').'(?![\w\\\\-]|[\x80-\xff])/', $selector) === 1;
    }
}
