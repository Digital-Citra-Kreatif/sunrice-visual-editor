<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Rendering;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as Views;
use Sunrice\VisualEditor\Css\ThemeFile;
use Sunrice\VisualEditor\Support\SunriceBridge;

/**
 * Loads the site's custom script (config `custom_js.file`, written in the
 * editor's Custom JS tab) on every page of the site.
 *
 * As CustomCss does for the stylesheet: a deferred <script> is pushed
 * onto the layout's stack (`@stack('scripts')` in the starter layout)
 * while a site template renders, once a page, only while the file has
 * something in it, with the file's version in its address. No template
 * is changed for it.
 */
final class CustomJs
{
    /** Larger than this is not a script written by hand in the editor. */
    public const MAX_BYTES = 1_000_000;

    /** Says which of the pushes is this one: a page pushes the script once, however many of its views render. */
    private const MARK = 'data-sunrice-custom-js';

    private const PATH = '#^(?:[A-Za-z0-9][A-Za-z0-9_-]*/)*[A-Za-z0-9][A-Za-z0-9_.-]*\.js$#';

    public static function register(): void
    {
        if (! self::injected()) {
            return;
        }
        // The site's templates, and Sunrice's fallbacks for them; not its admin page or its mail.
        Views::composer(['sunrice.*', 'sunrice::defaults.*'], fn (View $view) => self::push());
    }

    /** Whether the configuration has the script added to pages. */
    public static function injected(): bool
    {
        return filter_var(config('sunrice-visual-editor.custom_js.inject', true), FILTER_VALIDATE_BOOLEAN);
    }

    /** The stack the script is pushed onto. */
    public static function stack(): string
    {
        $stack = config('sunrice-visual-editor.custom_js.stack', 'scripts');

        return is_string($stack) && $stack !== '' ? $stack : 'scripts';
    }

    /** The custom script's file in the theme directory; null when the configuration names none that can be one. */
    public static function file(): ?ThemeFile
    {
        $path = ltrim((string) config('sunrice-visual-editor.custom_js.file', 'custom.js'), '/');
        if (preg_match(self::PATH, $path) !== 1) {
            return null;
        }
        $file = SunriceBridge::themeDirectory().'/'.$path;

        return new ThemeFile($path, $file, asset('sunrice-theme/'.$path), is_file($file));
    }

    /** The same bytes, the same version: what a save is checked against. */
    public static function version(string $source): string
    {
        return sha1($source);
    }

    /** The <script> for the custom script as it is now; null while there is none, or nothing in it. */
    public static function tag(): ?string
    {
        $file = self::file();
        if ($file === null || ! $file->exists) {
            return null;
        }
        $source = $file->source();
        if (trim($source) === '') {
            return null;
        }
        $src = $file->url.'?v='.substr(self::version($source), 0, 10);

        return '<script src="'.e($src).'" defer '.self::MARK.'></script>'."\n";
    }

    private static function push(): void
    {
        if (! self::injected()) {
            return;
        }
        $views = Views::getFacadeRoot();
        // The stack is the page's until it has been drawn: once is enough.
        if (str_contains($views->yieldPushContent(self::stack()), self::MARK)) {
            return;
        }
        $tag = self::tag();
        if ($tag !== null) {
            $views->startPush(self::stack(), $tag);
        }
    }
}
