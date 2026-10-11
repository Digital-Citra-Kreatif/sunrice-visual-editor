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
 * A working site for a test: Sunrice's starter templates and theme in
 * directories of the test's own (so a test may change them) and the
 * workbench content.
 */
trait WithStarterSite
{
    /** The site's template directory for this test (view names "sunrice.…"). */
    protected string $templates = '';

    /** The site's theme directory for this test (what /sunrice-theme serves). */
    protected string $theme = '';

    protected function setUpStarterSite(): void
    {
        $views = sys_get_temp_dir().'/sunrice-visual-editor-'.uniqid();
        $this->templates = $views.'/sunrice';
        File::copyDirectory(self::starterTemplates(), $this->templates);
        View::getFinder()->prependLocation($views);
        // The starter's theme too (app.css, app.js), as sunrice-templates publishes it to public/sunrice-theme.
        $this->theme = $views.'/theme';
        File::copyDirectory(dirname(self::starterTemplates()).'/theme', $this->theme);
        config(['sunrice-visual-editor.paths.templates' => $this->templates, 'sunrice-visual-editor.paths.theme' => $this->theme]);

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
