<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Honeypot\HoneypotServiceProvider;
use Sunrice\Admin\AdminServiceProvider;
use Sunrice\Cms\CmsServiceProvider;
use Sunrice\VisualEditor\VisualEditorServiceProvider;
use Workbench\App\Models\User;
use Workbench\App\Providers\AdminPanelProvider;

use function Orchestra\Testbench\default_migration_path;
use function Orchestra\Testbench\load_migration_paths;

/** A Laravel app with the panel, the CMS and the editor, set up as a site would be (AdminPanelProvider). */
class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** Whether the editor is on for this test; set before the app boots. */
    protected bool $editorEnabled = true;

    protected function getPackageProviders($app): array
    {
        return [
            AdminServiceProvider::class,
            CmsServiceProvider::class,
            HoneypotServiceProvider::class,
            VisualEditorServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fve4Qp/sF3rJf2fDR2lHo4SY9mZhJb+s=');
        $app['config']->set('inertia.testing.ensure_pages_exist', false);
        $app['config']->set('sunrice-visual-editor.enabled', $this->editorEnabled);
        // Laravel's users table; the panel's and the CMS's migrations load themselves.
        load_migration_paths($app, default_migration_path());
    }
}
