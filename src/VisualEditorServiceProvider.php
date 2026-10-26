<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor;

use Illuminate\Support\ServiceProvider;
use Sunrice\Admin\Support\PackageConfig;
use Sunrice\VisualEditor\Fields\Catalog;
use Sunrice\VisualEditor\Http\Middleware\EditorRender;
use Sunrice\VisualEditor\Rendering\CustomCss;
use Sunrice\VisualEditor\Rendering\CustomJs;
use Sunrice\VisualEditor\Support\Access;

/**
 * The package's Laravel side: its config, its published files, the custom
 * CSS and JS on the site's pages, and the middleware that draws a page for
 * the editor's frame. What it adds to the admin is the plugin's
 * (VisualEditorPlugin), added in the app's panel provider.
 */
class VisualEditorServiceProvider extends ServiceProvider
{
    /** Where the admin script and stylesheet are published, under public/. */
    public const ASSETS = 'vendor/sunrice-visual-editor';

    public function register(): void
    {
        PackageConfig::merge($this->app, __DIR__.'/../config/sunrice-visual-editor.php', 'sunrice-visual-editor', whole: ['widths']);

        // The site's blueprints are read once for a request, whoever asks.
        $this->app->scoped(Catalog::class);

        // The CMS reads its site middleware when it boots: added before any
        // provider boots, the editor's is on every page of the site. It does
        // nothing unless a page is asked for by the editor (see EditorRender).
        $this->app->booting(function (): void {
            if (Access::enabled()) {
                $middleware = config('sunrice-cms.site.middleware', ['web']);
                config(['sunrice-cms.site.middleware' => [...(is_array($middleware) ? $middleware : ['web']), EditorRender::class]]);
            }
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/sunrice-visual-editor.php' => config_path('sunrice-visual-editor.php'),
        ], 'sunrice-visual-editor-config');

        // Also "laravel-assets", which Laravel apps publish on every composer update.
        $this->publishes([
            __DIR__.'/../dist' => public_path(self::ASSETS),
        ], ['sunrice-visual-editor-assets', 'laravel-assets']);

        // The site's custom CSS and script are added to its pages whether or not the editor
        // is on: the deployed site has what was written on a local copy.
        CustomCss::register();
        CustomJs::register();
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
