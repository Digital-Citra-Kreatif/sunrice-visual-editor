<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Sunrice\Admin\Panel;
use Sunrice\Admin\PanelProvider;
use Sunrice\Cms\CmsPlugin;
use Sunrice\VisualEditor\VisualEditorPlugin;

/** The panel of the tests and of the demo site: the CMS, and the editor after it. */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->path('admin')->plugin(CmsPlugin::make())->plugin(VisualEditorPlugin::make());
    }
}
