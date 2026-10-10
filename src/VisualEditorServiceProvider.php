<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor;

use Illuminate\Support\ServiceProvider;
use Sunrice\VisualEditor\Support\Access;

class VisualEditorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sunrice-visual-editor.php', 'sunrice-visual-editor');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/sunrice-visual-editor.php' => config_path('sunrice-visual-editor.php'),
        ], 'sunrice-visual-editor-config');

        // Off: no routes, no admin script, nothing different about rendering.
        if (! Access::enabled()) {
            return;
        }

        // The editor's routes, admin script and sidebar link are added by
        // later tasks (EXECUTION_PLAN.md V0.3).
    }
}
