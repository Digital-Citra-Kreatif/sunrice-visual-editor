<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Honeypot\HoneypotServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Sitemap\SitemapServiceProvider;
use Sunrice\SunriceServiceProvider;
use Sunrice\VisualEditor\VisualEditorServiceProvider;
use Workbench\App\Models\User;

class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** Whether the editor is on for this test; set before the app boots. */
    protected bool $editorEnabled = true;

    protected function getPackageProviders($app): array
    {
        return [
            SunriceServiceProvider::class,
            InertiaServiceProvider::class,
            PermissionServiceProvider::class,
            HoneypotServiceProvider::class,
            SitemapServiceProvider::class,
            McpServiceProvider::class,
            VisualEditorServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('sunrice.auth.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fve4Qp/sF3rJf2fDR2lHo4SY9mZhJb+s=');
        $app['config']->set('inertia.testing.ensure_pages_exist', false);
        $app['config']->set('sunrice-visual-editor.enabled', $this->editorEnabled);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }
}
