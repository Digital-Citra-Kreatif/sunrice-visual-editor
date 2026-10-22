<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Sunrice\Admin\Panel;
use Sunrice\Admin\PanelProvider;
use Sunrice\Cms\CmsPlugin;
use Sunrice\VisualEditor\VisualEditorPlugin;

/** The demo site's panel and the tests', set up the way a site's would be: the CMS, then the editor. */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->brand('Sunrice')
            ->plugin(CmsPlugin::make())
            ->plugin(VisualEditorPlugin::make());
    }
}
