<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Rendering;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use RuntimeException;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Blade\Unsupported;
use Sunrice\VisualEditor\Support\Files;
use Sunrice\VisualEditor\Templates\TemplateFile;

/**
 * Blade's engine for one editor render. It stands in for Laravel's while a
 * page is rendered for the editor's frame, and only then.
 *
 * For a template the editor knows it notes the template in the manifest,
 * and when the template opens as a tree it runs a marked copy (see Marker)
 * instead of the template itself. The marked copies are compiled into a
 * directory of their own: Laravel's compiled views are never touched, so
 * no visitor is ever served a mark.
 */
final class EditorEngine extends CompilerEngine
{
    /** Part of every marked copy's name: change it when what Marker writes changes. */
    private const VERSION = '1';

    /** Seconds a copy of an earlier version of a template is left where it is: a page being drawn may still run it. */
    private const KEEP = 60;

    /** @var array<string, string|null> the marked copy of each template rendered so far in this page; null for none */
    private array $copies = [];

    /** @var array<string, string> Versions of the exact bytes used for each marked copy. */
    private array $versions = [];

    public function __construct(
        private readonly BladeCompiler $blade,
        Filesystem $files,
        private readonly Manifest $manifest,
        /** Where marked copies are compiled to. */
        private readonly string $directory,
    ) {
        parent::__construct($blade, $files);
    }

    /**
     * @param  string  $path
     * @param  array<string, mixed>  $data
     * @return string
     */
    public function get($path, array $data = [])
    {
        $file = $this->manifest->fileAt($path);
        if ($file === null) {
            // Not a template of the site or of Sunrice: rendered as always.
            return parent::get($path, $data);
        }

        // A partial in a loop is rendered many times: its copy is looked up once.
        if (! array_key_exists($path, $this->copies)) {
            $this->copies[$path] = $this->marked($path, $file);
        }
        $marked = $this->copies[$path];
        $this->manifest->record($file, $marked !== null, $this->versions[$path]);
        if ($file->role === 'entry') {
            // The entry the page is of: its content can be edited beside the page.
            $this->manifest->showing($data['entry'] ?? null);
        }
        if ($marked === null) {
            return parent::get($path, $data);
        }

        // As Laravel's engine does, so an error names the right view.
        $this->lastCompiled[] = $path;
        $results = $this->evaluatePath($marked, $data);
        array_pop($this->lastCompiled);

        return $results;
    }

    /**
     * The compiled, marked copy of a template; null when the template
     * doesn't open as a tree and so can't be marked.
     *
     * The copy is named after the template's path and its bytes: a changed
     * template gets a new copy, and the old one goes.
     */
    private function marked(string $path, TemplateFile $file): ?string
    {
        $source = $this->files->get($path);
        $this->versions[$path] = sha1($source);
        $key = Manifest::key($file);
        $stem = $this->directory.'/'.hash('xxh128', $path);
        $name = $stem.'-'.hash('xxh128', self::VERSION.$key.$source);

        if (is_file($name.'.php')) {
            return $name.'.php';
        }
        if (is_file($name.'.refused')) {
            return null;
        }

        try {
            $template = Template::open($source);
        } catch (Unsupported) {
            $this->replace($stem, $name.'.refused', '');

            return null;
        }

        $this->blade->setPath($path);
        $compiled = $this->blade->compileString(Marker::mark($template, $key));
        $this->replace($stem, $name.'.php', $this->withPath($compiled, $path));

        return $name.'.php';
    }

    /** What Laravel ends a compiled view with: error pages use it to name the template. */
    private function withPath(string $compiled, string $path): string
    {
        $open = false;
        foreach (token_get_all($compiled) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true)) {
                $open = true;
            } elseif (is_array($token) && $token[0] === T_CLOSE_TAG) {
                $open = false;
            }
        }

        return $compiled.($open ? ' ?>' : '')."<?php /**PATH {$path} ENDPATH**/ ?>";
    }

    /**
     * Writes a copy in one step, and removes the copies of earlier versions
     * of the template.
     *
     * Several pages are drawn for the editor at once (its frames side by
     * side, each drawn again after a change), so another request may be
     * writing this same copy, or be about to run the copy of the version
     * before. Neither is touched: not this version's files, and nothing
     * younger than a minute.
     */
    private function replace(string $stem, string $file, string $contents): void
    {
        Files::directory($this->directory);

        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        $this->files->put($temporary, $contents);
        if (! @rename($temporary, $file)) {
            @unlink($temporary);
            // Another request may have put the same copy there meanwhile, which is as good.
            if (! is_file($file)) {
                throw new RuntimeException("Could not write the editor's copy of a template to \"{$this->directory}\". Is it writable?");
            }
        }

        $name = substr($file, 0, (int) strrpos($file, '.'));
        foreach (glob($stem.'-*') ?: [] as $old) {
            if (! str_starts_with($old, $name.'.') && (int) @filemtime($old) < time() - self::KEEP) {
                @unlink($old);
            }
        }
    }
}
