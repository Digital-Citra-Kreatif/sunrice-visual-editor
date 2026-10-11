<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Css;

/** A stylesheet of the site's theme: a CSS file in public/sunrice-theme. */
final class ThemeFile
{
    /**
     * @param  string  $path  inside the theme directory: "app.css", "css/print.css"
     * @param  string  $file  where it is on disk
     * @param  string  $url  where a page links it from
     * @param  bool  $exists  false for the editor's own stylesheet before its first rule
     */
    public function __construct(
        public readonly string $path,
        public readonly string $file,
        public readonly string $url,
        public readonly bool $exists = true,
    ) {}

    /** What it holds; nothing, for one that is not there yet. */
    public function source(): string
    {
        clearstatcache(true, $this->file);

        return is_file($this->file) ? (string) file_get_contents($this->file) : '';
    }
}
