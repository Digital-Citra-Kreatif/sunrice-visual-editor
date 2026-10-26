<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Templates;

use Sunrice\VisualEditor\Support\SunriceBridge;

/**
 * The copies kept of a template each time it was written
 * (SunriceBridge::backups()): what the file has been.
 */
final class Backups
{
    /** The most copies looked through, the newest first. */
    private const MOST = 400;

    /**
     * Whether a template was exactly this text not long ago: an undo or
     * a redo of what was done in this sitting. Not a version from weeks
     * back, which may hold what was taken out for a reason.
     *
     * @param  int  $within  how many seconds back a copy counts
     */
    public static function had(TemplateFile $file, string $source, int $within = 43200): bool
    {
        $copies = glob(SunriceBridge::backups('*', $file->path)) ?: [];
        rsort($copies);
        $earliest = date('Y-m-d_His', time() - $within);

        foreach (array_slice($copies, 0, self::MOST) as $copy) {
            // Each copy is in a folder named after when it was made: 2026-10-11_093012, or …-2 for a second in the same second.
            $made = basename(dirname($copy, substr_count($file->path, '/') + 2));
            if (strcmp(substr($made, 0, 17), $earliest) < 0) {
                break;
            }
            if (filesize($copy) === strlen($source) && hash_equals(sha1($source), (string) sha1_file($copy))) {
                return true;
            }
        }

        return false;
    }
}
