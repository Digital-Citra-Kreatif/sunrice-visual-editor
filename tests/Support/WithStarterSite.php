<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Workbench\App\Seeders\DemoSeeder;

/**
 * A working site for a test: the CMS's starter templates and stylesheet,
 * with the blocks' templates of workbench/templates, in directories of the
 * test's own (so a test may change them), and the workbench content.
 */
trait WithStarterSite
{
    /** The site's template directory for this test (view names "cms.…"). */
    protected string $templates = '';

    /** The site's theme directory for this test (what /cms serves: site.css). */
    protected string $theme = '';

    protected function setUpStarterSite(): void
    {
        $views = sys_get_temp_dir().'/sunrice-visual-editor-'.uniqid();
        $this->templates = $views.'/cms';
        File::copyDirectory(StarterTemplates::directory(), $this->templates);
        // And what a site adds to them: a template for each kind of block of the demo content.
        File::copyDirectory(dirname(__DIR__, 2).'/workbench/templates', $this->templates);
        // In place of the app's own views: the workbench site (`composer workbench`) publishes
        // the starter templates there too, and a test that takes one away must not find it.
        $finder = View::getFinder();
        $finder->setPaths([$views, ...array_filter($finder->getPaths(), fn (string $path): bool => realpath($path) !== realpath(resource_path('views')))]);
        // The starter's stylesheet too, as sunrice-cms-templates publishes it to public/cms.
        $this->theme = $views.'/theme';
        File::copyDirectory(dirname(StarterTemplates::directory()).'/public', $this->theme);
        config(['sunrice-visual-editor.paths.templates' => $this->templates, 'sunrice-visual-editor.paths.theme' => $this->theme, 'sunrice-visual-editor.paths.theme_url' => 'cms']);

        $this->seed(DemoSeeder::class);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($views));
    }
}
