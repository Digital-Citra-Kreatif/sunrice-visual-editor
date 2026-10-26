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
        // No calls to Have I Been Pwned from the tests.
        $app['config']->set('sunrice-admin.auth.password_uncompromised', false);
        $app['config']->set('sunrice-visual-editor.enabled', $this->editorEnabled);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }
}
