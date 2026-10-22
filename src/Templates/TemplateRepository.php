<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Templates;

use Illuminate\Support\Facades\File;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Blade\Unsupported;
use Sunrice\VisualEditor\Support\SunriceBridge;

/**
 * The Blade files the editor works with: the site's templates, which it
 * edits, and the CMS's own views, which it shows read-only.
 *
 * A template is named by its path inside its directory and where it is
 * from: ("partials/card.blade.php", site), ("entry.blade.php",
 * package). Only files found by listing those directories are ever read, so
 * a path can't lead anywhere else.
 */
final class TemplateRepository
{
    /** What of the CMS's own views draws pages of the site: its fallback pages (at the top), its components and comments. Mail is not. */
    private const PACKAGE_FOLDERS = ['', 'components', 'comments'];

    /** Where a copy of a fallback view goes in the site: the view the CMS tries before it. */
    private const COPIES = [
        'entry.blade.php' => 'entry.blade.php',
        'listing.blade.php' => 'listing.blade.php',
        'term.blade.php' => 'term.blade.php',
        'search.blade.php' => 'search.blade.php',
        'comment-unsubscribe.blade.php' => 'comment-unsubscribe.blade.php',
    ];

    private const PATH = '#^(?:[A-Za-z0-9][A-Za-z0-9_-]*/)*[A-Za-z0-9][A-Za-z0-9_.-]*\.blade\.php$#';

    /** The same bytes, the same version: what a save is checked against. */
    public static function version(string $source): string
    {
        return sha1($source);
    }

    /**
     * Every template, the site's first, each with whether it opens as a tree.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map(fn (TemplateFile $file) => $file->toArray() + $this->status((string) file_get_contents($file->file))[0], $this->files());
    }

    /**
     * One template with its source, the version of that source and, when
     * it opens, its tree.
     *
     * @return array<string, mixed>
     *
     * @throws TemplateNotFound
     */
    public function read(string $path, string $origin = TemplateFile::SITE): array
    {
        $file = $this->find($path, $origin);
        $source = (string) file_get_contents($file->file);
        [$status, $template] = $this->status($source);

        return $file->toArray() + $status + [
            // Text that isn't UTF-8 can't be sent to the screen or typed back from it.
            'source' => mb_check_encoding($source, 'UTF-8') ? $source : null,
            'version' => self::version($source),
            'tree' => $template?->toArray()['tree'],
        ];
    }

    /** @throws TemplateNotFound */
    public function find(string $path, string $origin = TemplateFile::SITE): TemplateFile
    {
        if (preg_match(self::PATH, $path) === 1 && ! str_contains($path, '..')) {
            foreach ($this->files() as $file) {
                if ($file->path === $path && $file->origin === $origin) {
                    return $file;
                }
            }
        }

        throw new TemplateNotFound($origin === TemplateFile::PACKAGE ? "The CMS has no view \"{$path}\"." : "The site has no template \"{$path}\".");
    }

    /**
     * @return list<TemplateFile>
     */
    public function files(): array
    {
        $uses = [];
        foreach (SunriceBridge::templateMap() as $use) {
            $uses[$use['view']][] = ['role' => $use['role'], 'subject' => $use['subject'], 'handle' => $use['handle']];
        }

        $files = [];
        $directory = SunriceBridge::templateDirectory();
        foreach ($this->scan($directory) as $path) {
            $view = SunriceBridge::VIEW_PREFIX.'.'.self::dotted($path);
            $files[] = new TemplateFile($path, TemplateFile::SITE, $view, self::role($path, $uses[$view] ?? []), $uses[$view] ?? [], $directory.'/'.$path);
        }

        // Laravel looks through these in order: the first that has a file is the one Blade uses.
        $seen = [];
        foreach (SunriceBridge::packageViewDirectories() as $directory) {
            foreach (self::PACKAGE_FOLDERS as $folder) {
                foreach ($this->scan($directory.($folder === '' ? '' : '/'.$folder), deep: $folder !== '') as $path) {
                    $path = $folder === '' ? $path : $folder.'/'.$path;
                    if (isset($seen[$path])) {
                        continue;
                    }
                    $seen[$path] = true;
                    $view = SunriceBridge::PACKAGE_NAMESPACE.'::'.self::dotted($path);
                    $files[] = new TemplateFile($path, TemplateFile::PACKAGE, $view, self::role($path, $uses[$view] ?? []), $uses[$view] ?? [], $directory.'/'.$path, self::COPIES[$path] ?? null);
                }
            }
        }

        return $files;
    }

    /**
     * Whether the source opens as a tree, and the tree.
     *
     * @return array{0: array{opens: bool, reason: ?string, strays: int}, 1: ?Template}
     */
    private function status(string $source): array
    {
        if (! mb_check_encoding($source, 'UTF-8')) {
            return [['opens' => false, 'reason' => 'The file is not UTF-8 text: save it as UTF-8 in a code editor first', 'strays' => 0], null];
        }

        try {
            $template = Template::open($source);
        } catch (Unsupported $e) {
            return [['opens' => false, 'reason' => $e->getMessage(), 'strays' => 0], null];
        }

        return [['opens' => true, 'reason' => null, 'strays' => count($template->strays())], $template];
    }

    /**
     * The Blade files under a directory, as paths inside it, in order.
     *
     * @return list<string>
     */
    private function scan(string $directory, bool $deep = true): array
    {
        if (! is_dir($directory)) {
            return [];
        }
        $paths = [];
        foreach ($deep ? File::allFiles($directory) : File::files($directory) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $paths[] = str_replace('\\', '/', $file->getRelativePathname());
            }
        }
        sort($paths);

        return $paths;
    }

    /** "partials/header.blade.php" → "partials.header". */
    private static function dotted(string $path): string
    {
        return str_replace('/', '.', substr($path, 0, -strlen('.blade.php')));
    }

    /**
     * What a template is for. Where it sits says most; which pages use it
     * says the rest.
     *
     * @param  list<array{role: string, subject: string, handle: string}>  $usedBy
     */
    private static function role(string $path, array $usedBy): string
    {
        $folder = str_contains($path, '/') ? explode('/', $path)[0] : '';
        $name = basename($path, '.blade.php');

        return match (true) {
            $folder === 'layouts' || $path === 'layout.blade.php' => 'layout',
            $folder === 'partials' || $folder === 'comments' => 'partial',
            $folder === 'blocks' => 'block',
            $folder === 'errors' => 'error',
            $folder === 'components' => 'component',
            $usedBy !== [] => $usedBy[0]['role'],
            // Not used right now, but named the way the CMS looks for it.
            $name === 'term' || str_starts_with($name, 'taxonomy-') => 'term',
            $name === 'search' => 'search',
            $name === 'listing' || str_ends_with($name, '-listing') => 'listing',
            $name === 'entry' => 'entry',
            default => 'other',
        };
    }
}
