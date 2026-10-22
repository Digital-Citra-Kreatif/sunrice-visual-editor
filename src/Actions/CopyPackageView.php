<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Actions;

use RuntimeException;
use Sunrice\VisualEditor\Support\Files;
use Sunrice\VisualEditor\Support\SunriceBridge;
use Sunrice\VisualEditor\Templates\CopyRefused;
use Sunrice\VisualEditor\Templates\TemplateNotFound;
use Sunrice\VisualEditor\Templates\TemplateRepository;

/**
 * "Copy to site": puts a copy of one of the CMS's fallback views into the
 * site's templates, at the path the CMS looks at first, so the site uses
 * the copy from then on and it can be edited.
 */
final class CopyPackageView
{
    public function __construct(private readonly TemplateRepository $templates) {}

    /**
     * @param  string  $path  the view's path inside Sunrice's views: "entry.blade.php"
     * @return string the path of the new template inside the site's template directory
     *
     * @throws TemplateNotFound
     * @throws CopyRefused
     */
    public function handle(string $path): string
    {
        $view = $this->templates->find($path, 'package');
        if ($view->copyTo === null) {
            throw new CopyRefused("The CMS doesn't look for \"{$path}\" in the site, so a copy would never be used.");
        }

        $target = SunriceBridge::templateDirectory().'/'.$view->copyTo;
        $source = file_get_contents($view->file);
        if ($source === false) {
            throw new RuntimeException("Could not read \"{$view->file}\".");
        }

        // From the moment the file is there the site uses it in place of Sunrice's view, so it is
        // never there half written: the check and the write under the lock a save takes, and the
        // write in one step. A copy that fails leaves nothing, and can be asked for again.
        Files::locked($target, function () use ($target, $source, $view): void {
            clearstatcache(true, $target);
            if (file_exists($target)) {
                throw new CopyRefused("The site already has \"{$view->copyTo}\".");
            }
            Files::replace($target, $source);
        });

        SunriceBridge::changed('created', 'template', "{$view->copyTo} (a copy of the CMS's {$view->path})");

        return $view->copyTo;
    }
}
