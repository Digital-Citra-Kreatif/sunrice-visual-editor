<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\ServiceProvider;
use Sunrice\Facades\Sunrice;
use Sunrice\VisualEditor\Support\Access;

class VisualEditorServiceProvider extends ServiceProvider
{
    /** Where the admin script and stylesheet are published, under public/. */
    public const ASSETS = 'vendor/sunrice-visual-editor';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sunrice-visual-editor.php', 'sunrice-visual-editor');
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

        Sunrice::adminRoutes(fn () => $this->loadRoutesFrom(__DIR__.'/../routes/admin.php'));

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
    protected static function asset(string $file): string
    {
        $built = __DIR__.'/../dist/'.$file;

        return self::ASSETS.'/'.$file.(is_file($built) ? '?v='.substr((string) md5_file($built), 0, 10) : '');
    }
}
