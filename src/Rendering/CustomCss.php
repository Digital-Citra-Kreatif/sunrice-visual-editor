<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Rendering;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as Views;
use Sunrice\VisualEditor\Css\ThemeRepository;
use Sunrice\VisualEditor\Support\SunriceBridge;

/**
 * Links the site's custom CSS (config `custom_css.file`, written in the
 * editor's Custom CSS tab) from every page of the site.
 *
 * The link is pushed onto the layout's stack (`@stack('head')` in the
 * starter layout) while a site template renders, so no template is
 * changed for it. It is pushed once a page, and only while the file has
 * something in it. Its address carries the file's version, so a browser
 * fetches it again after each save.
 */
final class CustomCss
{
    /** Says which of the pushes is this one: a page pushes the link once, however many of its views render. */
    private const MARK = 'data-sunrice-custom-css';

    public static function register(): void
    {
        if (! self::injected()) {
            return;
        }
        // The site's templates, and Sunrice's fallbacks for them; not its admin page or its mail.
        Views::composer([SunriceBridge::VIEW_PREFIX.'.*', SunriceBridge::PACKAGE_NAMESPACE.'::*'], fn (View $view) => self::push());
    }

    /** Whether the configuration has the link added to pages. */
    public static function injected(): bool
    {
        return filter_var(config('sunrice-visual-editor.custom_css.inject', true), FILTER_VALIDATE_BOOLEAN);
    }

    /** The stack the link is pushed onto. */
    public static function stack(): string
    {
        $stack = config('sunrice-visual-editor.custom_css.stack', 'head');

        return is_string($stack) && $stack !== '' ? $stack : 'head';
    }

    /** The <link> for the custom CSS as it is now; null while there is none, or nothing in it. */
    public static function link(): ?string
    {
        $file = app(ThemeRepository::class)->custom();
        if ($file === null || ! $file->exists) {
            return null;
        }
        $source = $file->source();
        if (trim($source) === '') {
            return null;
        }
        $href = $file->url.'?v='.substr(ThemeRepository::version($source), 0, 10);

        return '<link rel="stylesheet" href="'.e($href).'" '.self::MARK.'>'."\n";
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
        $link = self::link();
        if ($link !== null) {
            $views->startPush(self::stack(), $link);
        }
    }
}
