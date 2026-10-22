<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use ReflectionClass;
use Sunrice\Cms\CmsServiceProvider;
use Workbench\App\Seeders\DemoSeeder;

/**
 * A working site for a test: the CMS's starter templates and stylesheet,
 * with views for the hero and text blocks (tests/fixtures/site), in directories of the test's own (so a test may change them) and the
 * workbench content.
 */
trait WithStarterSite
{
    /** The site's template directory for this test (view names "cms.…"). */
    protected string $templates = '';

    /** The site's theme directory for this test (what /cms serves). */
    protected string $theme = '';

    protected function setUpStarterSite(): void
    {
        $views = sys_get_temp_dir().'/sunrice-visual-editor-'.uniqid();
        $this->templates = $views.'/cms';
        File::copyDirectory(self::starterTemplates(), $this->templates);
        // And a view for two kinds of block, as a site makes them (cms/blocks/{fieldset}.blade.php).
        File::copyDirectory(dirname(__DIR__).'/fixtures/site', $this->templates);
        View::getFinder()->prependLocation($views);
        // The starter's stylesheet too, as sunrice-cms-templates publishes it to public/cms/site.css.
        $this->theme = $views.'/theme';
        File::ensureDirectoryExists($this->theme);
        File::copy(dirname(self::starterTemplates(), 2).'/stubs/public/site.css', $this->theme.'/site.css');
        config(['sunrice-visual-editor.paths.templates' => $this->templates, 'sunrice-visual-editor.paths.theme' => $this->theme]);

        $this->seed(DemoSeeder::class);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($views));
    }

    /** The starter templates of the installed CMS (what sunrice-cms-templates publishes to resources/views/cms). */
    public static function starterTemplates(): string
    {
        return dirname((string) (new ReflectionClass(CmsServiceProvider::class))->getFileName(), 2).'/stubs/templates';
    }
}
