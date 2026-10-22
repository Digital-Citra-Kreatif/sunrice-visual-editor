<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor;

use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Sunrice\Cms\Site\PageCache;
use Sunrice\VisualEditor\Fields\Catalog;
use Sunrice\VisualEditor\Http\Middleware\EditorRender;
use Sunrice\VisualEditor\Rendering\CustomCss;
use Sunrice\VisualEditor\Rendering\CustomJs;
use Sunrice\VisualEditor\Support\Access;

/**
 * What the editor does whether or not a panel is set up: its settings, its
 * built files to publish, the site's custom CSS and script on every page,
 * and pages drawn for its frame. What it adds to the panel is the
 * plugin's (VisualEditorPlugin).
 */
class VisualEditorServiceProvider extends ServiceProvider
{
    /** Where the admin script and stylesheet are published, under public/. */
    public const ASSETS = 'vendor/sunrice-visual-editor';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sunrice-visual-editor.php', 'sunrice-visual-editor');

        // The site's blueprints are read once for a request, whoever asks.
        $this->app->scoped(Catalog::class);

        // The CMS reads its site middleware when it adds the site's routes, in its
        // provider's boot(): before any provider boots, every config is merged.
        $this->app->booting(function (): void {
            if (! Access::enabled()) {
                return;
            }
            $middleware = config('sunrice-cms.site.middleware', ['web']);
            config(['sunrice-cms.site.middleware' => [...(is_array($middleware) ? $middleware : ['web']), EditorRender::class]]);
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

        if (Access::enabled()) {
            // A page drawn for the editor is never served from the CMS's page cache, nor kept in it.
            $this->callAfterResolving(PageCache::class, fn (PageCache $cache) => $cache->skipWhen(
                fn (Request $request): bool => $request->query(EditorRender::PARAMETER) === '1',
            ));
        }
    }

    /** @var array<string, string|null> each built file's version, looked at once per process */
    private static array $versions = [];

    /**
     * Public path of a built file, with the build's version so browsers
     * fetch the new one after an upgrade. The panel asks on every page, so
     * the file is only looked at, not read.
     */
    public static function asset(string $file): string
    {
        if (! array_key_exists($file, self::$versions)) {
            $stat = @stat(__DIR__.'/../dist/'.$file);
            self::$versions[$file] = $stat === false ? null : substr(md5($stat['mtime'].'-'.$stat['size']), 0, 10);
        }
        $version = self::$versions[$file];

        return self::ASSETS.'/'.$file.($version === null ? '' : '?v='.$version);
    }
}
