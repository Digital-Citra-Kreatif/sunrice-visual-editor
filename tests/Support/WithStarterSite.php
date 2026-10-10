<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use ReflectionClass;
use Sunrice\Frontend\RouteMatcher;
use Sunrice\SunriceServiceProvider;
use Workbench\App\Seeders\DemoSeeder;

/**
 * A working site for a test: Sunrice's starter templates in a directory of
 * the test's own (so a test may change them) and the workbench content.
 */
trait WithStarterSite
{
    /** The site's template directory for this test (view names "sunrice.…"). */
    protected string $templates = '';

    protected function setUpStarterSite(): void
    {
        $views = sys_get_temp_dir().'/sunrice-visual-editor-'.uniqid();
        $this->templates = $views.'/sunrice';
        File::copyDirectory(self::starterTemplates(), $this->templates);
        View::getFinder()->prependLocation($views);
        config(['sunrice-visual-editor.paths.templates' => $this->templates]);

        $this->seed(DemoSeeder::class);
        RouteMatcher::flush();

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($views));
    }

    /** The starter templates of the installed Sunrice (what sunrice-templates publishes). */
    public static function starterTemplates(): string
    {
        return dirname((string) (new ReflectionClass(SunriceServiceProvider::class))->getFileName(), 2).'/stubs/templates';
    }
}
