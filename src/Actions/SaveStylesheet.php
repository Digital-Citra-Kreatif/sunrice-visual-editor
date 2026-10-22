<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Actions;

use Sunrice\VisualEditor\Css\StylesheetNotFound;
use Sunrice\VisualEditor\Css\ThemeRepository;
use Sunrice\VisualEditor\Support\Files;
use Sunrice\VisualEditor\Support\SunriceBridge;
use Sunrice\VisualEditor\Templates\Conflict;

/**
 * Writes a new version of one of the theme's stylesheets. It is the only
 * place a stylesheet is written from.
 *
 * As for a template: the new source replaces the file only if the file is
 * still the version the change was made for, the old version is kept as a
 * backup, and the file changes in one step. The editor's own stylesheet
 * is made by its first save.
 */
final class SaveStylesheet
{
    public function __construct(private readonly ThemeRepository $theme) {}

    /**
     * @param  string  $path  the stylesheet's path inside the theme directory
     * @param  string  $expectedVersion  the version of the file the new source was made from
     * @return string the version the file has now
     *
     * @throws StylesheetNotFound
     * @throws Conflict
     */
    public function handle(string $path, string $expectedVersion, string $newSource): string
    {
        $file = $this->theme->find($path);

        return Files::locked($file->file, function () use ($file, $expectedVersion, $newSource): string {
            $existed = is_file($file->file);
            $current = $file->source();
            if (ThemeRepository::version($current) !== $expectedVersion) {
                throw new Conflict($file->path, ThemeRepository::version($current));
            }
            if ($newSource === $current && $existed) {
                return $expectedVersion;
            }

            if ($existed && config('sunrice-visual-editor.backups', true)) {
                SunriceBridge::backup($file->file, $file->path, 'theme');
            }
            Files::replace($file->file, $newSource);

            // Beside the content changes in Manage → Activity log.
            SunriceBridge::changed($existed ? 'updated' : 'created', 'stylesheet', SunriceBridge::THEME_URL.'/'.$file->path);

            return ThemeRepository::version($newSource);
        });
    }
}
