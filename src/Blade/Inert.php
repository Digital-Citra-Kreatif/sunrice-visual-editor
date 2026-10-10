<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * Turns words someone typed into text that is safe to put in a Blade file:
 * HTML sees the same characters, and Blade sees nothing to run.
 *
 * Typed text must never become code. "{{ $x }}", "@php" or "<script>" in a
 * heading are a heading that says so.
 */
final class Inert
{
    /** For a text node. */
    public static function text(string $words): string
    {
        return self::blade(htmlspecialchars($words, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
    }

    /** For an attribute value, whichever quote surrounds it. */
    public static function attribute(string $value): string
    {
        return self::blade(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
    }

    /** What a browser shows for text written by text() or attribute(). */
    public static function decode(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Blade reacts to "{{", "{!!" and to "@" before a name. Each is broken
     * with a character reference, which HTML reads as the same character.
     * (Blade's own escapes, "@{{" and "@@", don't cover comments: "{{--".)
     *
     * An "@" straight after a letter or digit is left alone, as Blade
     * leaves it: "me@example.com" stays readable in the file.
     */
    private static function blade(string $html): string
    {
        $html = str_replace(['{{', '{!!'], ['&#123;&#123;', '&#123;!!'], $html);

        return (string) preg_replace('/(?<![A-Za-z0-9_])@(?=[A-Za-z_@{])/', '&#64;', $html);
    }
}
