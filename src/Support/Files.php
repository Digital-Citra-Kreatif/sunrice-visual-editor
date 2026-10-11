<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Support;

use RuntimeException;

/** Writing a file of the site the way the editor does: one writer at a time, and never half a file. */
final class Files
{
    /**
     * Runs the work while holding the lock for a file: the check of what
     * the file is and the write that follows belong together. The lock is
     * a file of its own, since the file itself is replaced by a save.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function locked(string $file, callable $work): mixed
    {
        $directory = storage_path('framework/cache/sunrice-visual-editor');
        self::directory($directory);

        $lock = fopen($directory.'/'.sha1($file).'.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the file for saving.');
        }
        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Makes a directory that is not there yet. Two requests may find it
     * missing at the same moment: the one that comes second finds it made,
     * which is what it wanted.
     */
    public static function directory(string $directory): void
    {
        // Twice: the other request may have been making one of the directories above it.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if (is_dir($directory) || @mkdir($directory, 0755, true) || is_dir($directory)) {
                return;
            }
        }

        throw new RuntimeException("Could not make the directory \"{$directory}\". Is the one above it writable?");
    }

    /**
     * Writes beside the file and renames over it, so the file is never
     * half written: whoever reads it meanwhile sees the old file or the
     * new one. A file that is not there yet is made, with its directory.
     */
    public static function replace(string $file, string $source): void
    {
        self::directory(dirname($file));
        $temporary = dirname($file).'/.'.basename($file).'.'.bin2hex(random_bytes(6)).'.tmp';

        if (file_put_contents($temporary, $source) !== strlen($source)) {
            @unlink($temporary);
            throw new RuntimeException("Could not write beside \"{$file}\". Is the directory writable?");
        }
        $permissions = @fileperms($file);
        if ($permissions !== false) {
            @chmod($temporary, $permissions & 0777);
        } else {
            // A new file: as any file made here would be, not as a temporary one.
            @chmod($temporary, 0666 & ~umask());
        }
        if (! @rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException("Could not replace \"{$file}\".");
        }
        clearstatcache(true, $file);
    }
}
