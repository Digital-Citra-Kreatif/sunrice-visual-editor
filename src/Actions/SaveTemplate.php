<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Actions;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Sunrice\VisualEditor\Blade\Syntax;
use Sunrice\VisualEditor\Support\SunriceBridge;
use Sunrice\VisualEditor\Templates\Conflict;
use Sunrice\VisualEditor\Templates\DoesNotCompile;
use Sunrice\VisualEditor\Templates\TemplateNotFound;
use Sunrice\VisualEditor\Templates\TemplateRepository;

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
        $lock = $this->lock($file->file);
        try {
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
            $this->replace($file->file, $newSource);

            return TemplateRepository::version($newSource);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Writes beside the file and renames over it, so the file is never
     * half written.
     */
    private function replace(string $file, string $source): void
    {
        $temporary = dirname($file).'/.'.basename($file).'.'.bin2hex(random_bytes(6)).'.tmp';

        if (file_put_contents($temporary, $source) !== strlen($source)) {
            @unlink($temporary);
            throw new RuntimeException("Could not write beside \"{$file}\". Is the directory writable?");
        }
        $permissions = @fileperms($file);
        if ($permissions !== false) {
            @chmod($temporary, $permissions & 0777);
        }
        if (! @rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException("Could not replace \"{$file}\".");
        }
        clearstatcache(true, $file);
    }

    /**
     * Waits for, and takes, the lock for a file. The lock is a file of its
     * own, since the template itself is replaced by the save.
     *
     * @return resource
     */
    private function lock(string $file)
    {
        $directory = storage_path('framework/cache/sunrice-visual-editor');
        File::ensureDirectoryExists($directory);

        $lock = fopen($directory.'/'.sha1($file).'.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the template for saving.');
        }

        return $lock;
    }
}
