<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Rendering;

use Sunrice\VisualEditor\Support\SunriceBridge;

/**
 * Tailwind's own browser build, for pages drawn for the editor and for no
 * others: a class added in the editor shows at once, on a site whose CSS
 * is built and has not been built again yet.
 *
 * It is a preview. The browser build reads the site's CSS entry file for
 * the theme, and does not load plugins or other files; what the site's
 * visitors get is what the build makes.
 */
final class TailwindPreview
{
    /** Whether the site's own Vite dev server is running: it serves the real CSS as it changes, and nothing is to be added. */
    public static function devServer(): bool
    {
        return is_file(public_path('hot'));
    }

    /** Whether a page drawn for the editor gets the preview. */
    public static function wanted(): bool
    {
        return (bool) config('sunrice-visual-editor.tailwind.preview', false) && ! self::devServer();
    }

    /** What is put into the page's head: the browser build, and the site's entry file for it to read. */
    public static function tags(): string
    {
        $script = (string) config('sunrice-visual-editor.tailwind.script', '');
        if ($script === '') {
            return '';
        }
        $entry = self::entry();

        $nonce = SunriceBridge::nonceAttribute();

        return '<script src="'.e($script).'"'.$nonce.' data-sve-preview></script>'
            .($entry === null ? '' : '<style type="text/tailwindcss"'.$nonce.' data-sve-preview>'.$entry.'</style>');
    }

    /** The site's CSS entry file as the browser build can read it; null when there is none. */
    public static function entry(): ?string
    {
        $path = trim((string) config('sunrice-visual-editor.tailwind.entry', ''));
        $file = $path === '' ? null : (str_starts_with($path, '/') ? $path : base_path($path));
        if ($file === null || ! is_file($file) || filesize($file) > 1_000_000) {
            return null;
        }

        return self::fit((string) file_get_contents($file));
    }

    /**
     * A CSS entry file made fit for the browser build: without what it
     * refuses (plugins, a config file, other files brought in) and what
     * means nothing in a browser (where to look for classes), and with
     * nothing that could end the element it is put in.
     */
    public static function fit(string $css): string
    {
        $css = (string) preg_replace([
            // @source "…"; @config "…"; @plugin "…"; and @plugin "…" { … }
            '/@(?:source|config)\b[^;{}]*;/',
            '/@plugin\b[^;{}]*(?:;|\{[^{}]*\})/',
            // Any @import but Tailwind's own.
            '/@import\s+(?:url\()?(["\'])(?!tailwindcss(?:\/[^"\']*)?\1)[^"\']*\1[^;]*;/',
        ], '', $css);

        // "</style" inside it would end the element: a "<" is nothing CSS needs unescaped.
        return str_ireplace('</style', '<\\/style', $css);
    }
}
