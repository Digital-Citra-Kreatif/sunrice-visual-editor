<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Actions;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Sunrice\Activity\ActivityLogger;
use Sunrice\VisualEditor\Support\SunriceBridge;
use Sunrice\VisualEditor\Templates\CopyRefused;
use Sunrice\VisualEditor\Templates\TemplateNotFound;
use Sunrice\VisualEditor\Templates\TemplateRepository;

/**
 * "Copy to site": puts a copy of one of Sunrice's fallback views into the
 * site's templates, at the path Sunrice looks at first, so the site uses
 * the copy from then on and it can be edited.
 */
final class CopyPackageView
{
    public function __construct(private readonly TemplateRepository $templates) {}

    /**
     * @param  string  $path  the view's path inside Sunrice's views: "defaults/show.blade.php"
     * @return string the path of the new template inside the site's template directory
     *
     * @throws TemplateNotFound
     * @throws CopyRefused
     */
    public function handle(string $path): string
    {
        $view = $this->templates->find($path, 'package');
        if ($view->copyTo === null) {
            throw new CopyRefused("Sunrice doesn't look for \"{$path}\" in the site, so a copy would never be used.");
        }

        $target = SunriceBridge::templateDirectory().'/'.$view->copyTo;
        File::ensureDirectoryExists(dirname($target));

        // "x": fails when the file is there, so two copies at once can't both win.
        $handle = @fopen($target, 'x');
        if ($handle === false) {
            if (is_file($target)) {
                throw new CopyRefused("The site already has \"{$view->copyTo}\".");
            }
            throw new RuntimeException("Could not create \"{$target}\". Is the directory writable?");
        }
        fwrite($handle, (string) file_get_contents($view->file));
        fclose($handle);

        app(ActivityLogger::class)->record('created', $view->copyTo, ['note' => "Visual editor: copied from Sunrice's {$view->path}"], 'template');

        return $view->copyTo;
    }
}
