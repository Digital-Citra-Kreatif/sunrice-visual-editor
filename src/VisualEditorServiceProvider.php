<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\ServiceProvider;
use Sunrice\Facades\Sunrice;
use Sunrice\Permissions\PermissionRegistry;
use Sunrice\Permissions\SyncPermissions;
use Sunrice\VisualEditor\Fields\Catalog;
use Sunrice\VisualEditor\Http\Middleware\EditorRender;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Support\RegisteredPermissions;

class VisualEditorServiceProvider extends ServiceProvider
{
    /** Where the admin script and stylesheet are published, under public/. */
    public const ASSETS = 'vendor/sunrice-visual-editor';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sunrice-visual-editor.php', 'sunrice-visual-editor');

        // The site's blueprints are read once for a request, whoever asks.
        $this->app->scoped(Catalog::class);

        // Sunrice's sync creates a package's permission a second time and fails (see RegisteredPermissions).
        $this->app->when(SyncPermissions::class)->needs(PermissionRegistry::class)->give(RegisteredPermissions::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/sunrice-visual-editor.php' => config_path('sunrice-visual-editor.php'),
        ], 'sunrice-visual-editor-config');

        $this->publishes([
            __DIR__.'/../dist' => public_path(self::ASSETS),
        ], 'sunrice-visual-editor-assets');

        // Off: no routes, no admin script, nothing different about rendering.
        if (! Access::enabled()) {
            return;
        }

        // Roles are given these in Sunrice's admin; `sunrice:sync-permissions` creates them.
        foreach (Access::LABELS as $permission => $label) {
            Sunrice::registerPermission($permission, $label, 'Visual editor');
        }

        Sunrice::adminRoutes(fn () => $this->loadRoutesFrom(__DIR__.'/../routes/admin.php'));

        // Sunrice reads this list once every provider has booted, when it
        // adds its frontend routes. The middleware does nothing unless a
        // page is asked for by the editor (see EditorRender).
        config(['sunrice.frontend.middleware' => [
            ...(array) config('sunrice.frontend.middleware', ['web']),
            EditorRender::class,
        ]]);

        Sunrice::registerAdminScript(self::asset('visual-editor.js'));
        Sunrice::registerAdminStyle(self::asset('visual-editor.css'));

        Sunrice::addNavigationItem(
            label: 'Visual editor',
            href: 'visual-editor',
            icon: 'layout-template',
            group: 'Manage',
            can: fn (?Authenticatable $user): bool => Access::allowed($user),
        );
    }

    /**
     * Public path of a built file, with the build's fingerprint so browsers
     * fetch the new one after an upgrade.
     */
    public static function asset(string $file): string
    {
        $built = __DIR__.'/../dist/'.$file;

        return self::ASSETS.'/'.$file.(is_file($built) ? '?v='.substr((string) md5_file($built), 0, 10) : '');
    }
}
