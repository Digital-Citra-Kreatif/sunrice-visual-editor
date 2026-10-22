<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Sunrice\Admin\Assets\PluginBundle;
use Sunrice\Admin\BasePlugin;
use Sunrice\Admin\Navigation\NavigationItem;
use Sunrice\Admin\Panel;
use Sunrice\Admin\Permissions\Permission;
use Sunrice\Cms\CmsPlugin;
use Sunrice\Cms\Content;
use Sunrice\Cms\Models\EntryTranslation;
use Sunrice\VisualEditor\Mcp\EditTemplate;
use Sunrice\VisualEditor\Mcp\GetTemplateTree;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Support\Launch;

/**
 * The visual editor, added to the panel after the CMS in the app's panel
 * provider:
 *
 *     $panel->plugin(CmsPlugin::make())->plugin(VisualEditorPlugin::make());
 *
 * Its permissions are always there, so roles keep them on a copy of the
 * site where the editor is off; everything else (the screen, its API, the
 * sidebar item, the tools for agents) only while it is on
 * (config `sunrice-visual-editor.enabled`).
 */
final class VisualEditorPlugin extends BasePlugin
{
    public const ID = 'visual-editor';

    public function id(): string
    {
        return self::ID;
    }

    public function requires(): array
    {
        return [CmsPlugin::ID];
    }

    public function register(Panel $panel): void
    {
        // Roles are given these in Users → Roles; sunrice:sync-permissions creates them.
        $panel->permissions(...array_map(
            fn (string $name, string $label): Permission => Permission::make($name, $label, 'Visual editor'),
            array_keys(Access::LABELS), Access::LABELS,
        ));
        // Writing code runs it on the server: worth an alert, as the CMS's own site code is.
        $panel->privilegedPermissions(Access::CODE, Access::BUILD);
        $panel->docs('Visual editor', dirname(__DIR__).'/docs');

        if (! Access::enabled()) {
            return;
        }

        $panel->routes(fn () => require dirname(__DIR__).'/routes/admin.php');
        $panel->navigation(NavigationItem::make('visual-editor')->label('Visual editor')->url('visual-editor')
            ->icon('layout-template')->group('Structure')->sort(95)
            ->visible(fn (Authorizable $user): bool => Access::allowed($user)));
        $panel->bundle(PluginBundle::make(self::ID)->named('Sunrice visual editor')
            ->module(asset(VisualEditorServiceProvider::asset('visual-editor.js')))
            ->style(asset(VisualEditorServiceProvider::asset('visual-editor.css')))
            ->pages('VisualEditor/')
            ->publishedFrom(dirname(__DIR__).'/dist', public_path(VisualEditorServiceProvider::ASSETS)));
        $panel->tools(GetTemplateTree::class, EditTemplate::class)->aiInstructions(<<<'MD'
            Visual editor: visual_editor_get_template_tree reads a template under resources/views/cms as the tree the editor shows (each node with its id and what may be done to it); visual_editor_edit_template changes it by operations on those nodes (add a class, set a text, insert, move, wrap), checked before it is saved. Prefer them to rewriting a whole file.
            MD);
    }

    public function boot(Panel $panel): void
    {
        if (! Access::enabled()) {
            return;
        }
        // "Edit with Visual Editor" in an entry's ⋮ menu, beside "View page".
        Content::entryLinksUsing(fn (EntryTranslation $entry, Authorizable $user): array => Launch::link($entry, $user));
    }
}
