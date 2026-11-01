<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Actions;

use Sunrice\Admin\Activity\ActivityLog;
use Sunrice\VisualEditor\Rendering\CustomJs;
use Sunrice\VisualEditor\Support\AssetSyntax;
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

            AssetSyntax::check('js', $newSource);
            // An external editor may not use our lock while a checker runs.
            clearstatcache(true, $file->file);
            $latest = CustomJs::version($file->source());
            if ($latest !== $expectedVersion) {
                throw new Conflict($file->path, $latest);
            }

            if ($existed && config('sunrice-visual-editor.backups', true)) {
                SunriceBridge::backup($file->file, $file->path, 'theme');
            }
            Files::replace($file->file, $newSource);

            app(ActivityLog::class)->record($existed ? 'updated' : 'created', 'template', null, SunriceBridge::themeFolder().'/'.$file->path, ['note' => 'Visual editor']);

            SunriceBridge::changed(SunriceBridge::themeFolder().'/'.$file->path);

            return CustomJs::version($newSource);
        });
    }
}
