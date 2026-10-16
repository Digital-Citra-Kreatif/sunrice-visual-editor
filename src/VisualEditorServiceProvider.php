<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Sunrice\Facades\Sunrice;
use Sunrice\Permissions\PermissionRegistry;
use Sunrice\Permissions\SyncPermissions;
use Sunrice\VisualEditor\Fields\Catalog;
use Sunrice\VisualEditor\Http\Middleware\EditorRender;
use Sunrice\VisualEditor\Mcp\EditTemplate;
use Sunrice\VisualEditor\Mcp\GetTemplateTree;
use Sunrice\VisualEditor\Rendering\CustomCss;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Support\RegisteredPermissions;
use Sunrice\VisualEditor\Support\SunriceBridge;

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

        // The site's custom CSS is linked from its pages whether or not the editor is on:
        // the deployed site has the CSS that was written on a local copy.
        CustomCss::register();

        // Off: no routes, no admin script, nothing else different about rendering.
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

        // Tools for AI agents, on Sunrice's MCP server.
        SunriceBridge::registerMcpTools(self::mcpTools());

        Sunrice::registerAdminScript(self::asset('visual-editor.js'));
        Sunrice::registerAdminStyle(self::asset('visual-editor.css'));

        // The admin's pages are told whether whoever is signed in may open the editor: an entry's
        // page then offers "Edit with Visual Editor" (resources/js/launch.ts).
        Inertia::share('visualEditor', fn (): ?array => Access::allowed(request()->user(config('sunrice.auth.guard'))) ? ['open' => true] : null);

        Sunrice::addNavigationItem(
            label: 'Visual editor',
            href: 'visual-editor',
            icon: 'layout-template',
            group: 'Manage',
            can: fn (?Authenticatable $user): bool => Access::allowed($user),
        );
    }

    /**
     * The tools the package has for AI agents: a template read as a tree,
     * and changed by operations on it.
     *
     * @return list<class-string>
     */
    public static function mcpTools(): array
    {
        return [GetTemplateTree::class, EditTemplate::class];
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
