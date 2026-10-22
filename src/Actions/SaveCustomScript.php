<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Actions;

use Sunrice\VisualEditor\Rendering\CustomJs;
use Sunrice\VisualEditor\Support\Files;
use Sunrice\VisualEditor\Support\SunriceBridge;
use Sunrice\VisualEditor\Templates\Conflict;

/**
 * Writes a new version of the site's custom script. It is the only place
 * that file is written from.
 *
 * As for a stylesheet: the new source replaces the file only if the file
 * is still the version the change was made for, the old version is kept
 * as a backup, and the file changes in one step. Its first save makes it.
 */
final class SaveCustomScript
{
    /**
     * @param  string  $expectedVersion  the version of the file the new source was made from
     * @return string the version the file has now
     *
     * @throws Conflict
     */
    public function handle(string $expectedVersion, string $newSource): string
    {
        $file = CustomJs::file();
        if ($file === null) {
            throw new \RuntimeException('The configuration names no file for custom JS (custom_js.file).');
        }

        return Files::locked($file->file, function () use ($file, $expectedVersion, $newSource): string {
            clearstatcache(true, $file->file);
            $existed = is_file($file->file);
            $current = $file->source();
            if (CustomJs::version($current) !== $expectedVersion) {
                throw new Conflict($file->path, CustomJs::version($current));
            }
            if ($newSource === $current && $existed) {
                return $expectedVersion;
            }

            if ($existed && config('sunrice-visual-editor.backups', true)) {
                SunriceBridge::backup($file->file, $file->path, 'theme');
            }
            Files::replace($file->file, $newSource);

            SunriceBridge::changed($existed ? 'updated' : 'created', 'script', SunriceBridge::THEME_URL.'/'.$file->path);

            return CustomJs::version($newSource);
        });
    }
}
