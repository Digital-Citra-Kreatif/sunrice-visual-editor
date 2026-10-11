<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Actions;

use Illuminate\Support\Facades\View;
use Illuminate\View\Compilers\BladeCompiler;
use Sunrice\Activity\ActivityLogger;
use Sunrice\VisualEditor\Blade\Syntax;
use Sunrice\VisualEditor\Support\Files;
use Sunrice\VisualEditor\Support\SunriceBridge;
use Sunrice\VisualEditor\Templates\Conflict;
use Sunrice\VisualEditor\Templates\DoesNotCompile;
use Sunrice\VisualEditor\Templates\TemplateFile;
use Sunrice\VisualEditor\Templates\TemplateNotFound;
use Sunrice\VisualEditor\Templates\TemplateRepository;
use Throwable;

/**
 * Writes a new version of one of the site's templates. It is the only
 * place a template is written from.
 *
 * The new source replaces the file only if the file is still the version
 * the change was made for and the new source compiles. The old version is
 * kept as a backup, and the file changes in one step: a page rendered
 * meanwhile sees the old file or the new one, never part of each.
 */
final class SaveTemplate
{
    public function __construct(private readonly TemplateRepository $templates) {}

    /**
     * @param  string  $path  the template's path inside the site's template directory
     * @param  string  $expectedVersion  the version of the file the new source was made from
     * @return string the version the file has now
     *
     * @throws TemplateNotFound
     * @throws Conflict
     * @throws DoesNotCompile
     */
    public function handle(string $path, string $expectedVersion, string $newSource): string
    {
        $file = $this->templates->find($path);

        // One save at a time per file: the check and the write belong together.
        return Files::locked($file->file, function () use ($file, $path, $expectedVersion, $newSource): string {
            clearstatcache(true, $file->file);
            $current = (string) file_get_contents($file->file);
            if (TemplateRepository::version($current) !== $expectedVersion) {
                throw new Conflict($path, TemplateRepository::version($current));
            }
            if ($newSource === $current) {
                return $expectedVersion;
            }

            $error = Syntax::brokenBy($current, $newSource);
            if ($error !== null) {
                throw new DoesNotCompile($error);
            }

            if (config('sunrice-visual-editor.backups', true)) {
                SunriceBridge::backup($file->file, $file->path);
            }
            Files::replace($file->file, $newSource);
            $this->forgetCompiled($file);

            // Beside the content changes in Manage → Activity log.
            app(ActivityLogger::class)->record('updated', $file->path, ['note' => 'Visual editor'], 'template');

            return TemplateRepository::version($newSource);
        });
    }

    /**
     * Drops Laravel's compiled copy of the template, so that the next page
     * is drawn from the new file whatever the server remembers. Laravel
     * would notice the newer file by itself, unless it was told not to look
     * (view.check_cache_timestamps). PHP's opcache would not: it holds on
     * to a compiled view for two seconds by default, and for good on a
     * server set never to look at file times.
     */
    private function forgetCompiled(TemplateFile $file): void
    {
        $sources = [$file->file];
        try {
            // The path as Laravel spells it, which is what the compiled copy is named after.
            $sources[] = View::getFinder()->find($file->view);
        } catch (Throwable) {
            // Not a view Laravel finds by that name: the file's own path is all there is.
        }

        /** @var BladeCompiler $blade */
        $blade = app('blade.compiler');
        foreach (array_unique($sources) as $source) {
            $compiled = $blade->getCompiledPath($source);
            @unlink($compiled);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($compiled, true);
            }
        }
    }
}
