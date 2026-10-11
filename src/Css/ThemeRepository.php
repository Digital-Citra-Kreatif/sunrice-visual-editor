<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Css;

use Illuminate\Support\Facades\File;
use Sunrice\VisualEditor\Support\SunriceBridge;

/**
 * The stylesheets the editor works with: the CSS files of the site's
 * theme, in public/sunrice-theme. Only files found by listing that
 * directory are ever read or written, so a path can't lead anywhere else;
 * the one file that may be created is the editor's own (config
 * `theme_css`), where rules that do not exist yet go.
 */
final class ThemeRepository
{
    /** Larger than this is not a stylesheet written by hand: it is left alone. */
    public const MAX_BYTES = 2_000_000;

    private const PATH = '#^(?:[A-Za-z0-9][A-Za-z0-9_-]*/)*[A-Za-z0-9][A-Za-z0-9_.-]*\.css$#';

    /** The same bytes, the same version: what a change is checked against. */
    public static function version(string $source): string
    {
        return sha1($source);
    }

    /**
     * The theme's stylesheets, in order of their paths.
     *
     * @return list<ThemeFile>
     */
    public function files(): array
    {
        $directory = SunriceBridge::themeDirectory();
        if (! is_dir($directory)) {
            return [];
        }
        $paths = [];
        foreach (File::allFiles($directory) as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());
            if (preg_match(self::PATH, $path) === 1 && $file->getSize() <= self::MAX_BYTES) {
                $paths[] = $path;
            }
        }
        sort($paths);

        return array_map(fn (string $path) => new ThemeFile($path, $directory.'/'.$path, self::url($path)), $paths);
    }

    /**
     * A stylesheet by its path: one of those there are, or the editor's
     * own before it exists.
     *
     * @throws StylesheetNotFound
     */
    public function find(string $path): ThemeFile
    {
        foreach ($this->files() as $file) {
            if ($file->path === $path) {
                return $file;
            }
        }
        $own = $this->own();
        if ($own !== null && $own->path === $path) {
            return $own;
        }

        throw new StylesheetNotFound("The theme has no stylesheet \"{$path}\".");
    }

    /** The stylesheet new rules go into; null when the site's configuration names none that can be one. */
    public function own(): ?ThemeFile
    {
        $path = ltrim((string) config('sunrice-visual-editor.theme_css', 'editor.css'), '/');
        if (preg_match(self::PATH, $path) !== 1) {
            return null;
        }
        $file = SunriceBridge::themeDirectory().'/'.$path;

        return new ThemeFile($path, $file, self::url($path), is_file($file));
    }

    /** Where pages link a theme file from, as the templates write it: asset('sunrice-theme/…'). */
    private static function url(string $path): string
    {
        return asset('sunrice-theme/'.$path);
    }
}
