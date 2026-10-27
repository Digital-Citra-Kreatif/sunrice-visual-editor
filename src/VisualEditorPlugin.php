<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
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
use Sunrice\VisualEditor\Support\SunriceBridge;

/**
 * The editor, added to the panel in the app's panel provider, after the
 * CMS whose templates it edits:
 *
 *     $panel->plugin(CmsPlugin::make())->plugin(VisualEditorPlugin::make());
 *
 * While the package is off (`sunrice-visual-editor.enabled`) it adds
 * nothing: no permissions, routes, sidebar item, script or tools.
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
        if (! Access::enabled()) {
            return;
        }

        $dist = dirname(__DIR__).'/dist';

        $panel
            // Given to roles in Administration → Roles.
            ->permissions(...array_map(
                fn (string $name, string $label): Permission => Permission::make($name, $label, 'Visual editor'),
                array_keys(Access::LABELS),
                Access::LABELS,
            ))
            // Writing code in a template runs it on the server, as the build command does: worth an alert.
            ->privilegedPermissions(Access::CODE, Access::BUILD)
            ->navigation(NavigationItem::make('visual-editor')->label('Visual editor')->url('visual-editor')
                ->icon('layout-template')->group('Structure')->sort(95)
                ->visible(fn (Authorizable $user): bool => $user instanceof Authenticatable && Access::allowed($user)))
            // Under the panel's path, named sunrice.admin.…, for signed-in users who may open the panel.
            ->routes(fn () => require dirname(__DIR__).'/routes/admin.php')
            // Loaded only when the editor's page opens.
            ->bundle(PluginBundle::make(self::ID)->named('Sunrice Visual Editor')
                ->module(asset(VisualEditorServiceProvider::asset('visual-editor.js')))
                ->style(asset(VisualEditorServiceProvider::asset('visual-editor.css')))
                ->pages('VisualEditor/')
                ->publishedFrom($dist, public_path(VisualEditorServiceProvider::ASSETS)))
            // Cmd/Ctrl+K on every page of the admin: "Edit … in the visual editor", for the site's pages.
            ->commandSearch('visual-editor.pages', fn (): string => route('sunrice.admin.visual-editor.api.pages'))
            // A template read as a tree, and changed by operations on it.
            ->tools(GetTemplateTree::class, EditTemplate::class)
            ->aiInstructions(<<<'MD'
                Visual editor: visual_editor_get_template_tree reads a template of resources/views/cms as a tree of nodes, each with its id; visual_editor_edit_template changes it by operations on those ids (set an attribute or a text, insert, move, wrap, delete), onto the version that was read. Prefer these to rewriting a whole file: they keep every byte they don't touch.
                MD)
            ->docs('Visual editor', dirname(__DIR__).'/docs');
    }

    public function boot(Panel $panel): void
    {
        if (! Access::enabled()) {
            return;
        }

        // The ⋮ menu of an entry's page in the admin: "Edit with Visual Editor", on its page in that language.
        Content::entryLinksUsing(fn (EntryTranslation $entry, Authorizable $user): array => self::entryLinks($entry, $user));
    }

    /**
     * The editor on an entry's page in the language being edited, for
     * those who may open the editor, while the entry has a page on the
     * site to open it on.
     *
     * @return list<array{label: string, href: string}>
     */
    private static function entryLinks(EntryTranslation $entry, Authorizable $user): array
    {
        if (! $user instanceof Authenticatable || ! Access::allowed($user)) {
            return [];
        }
        $path = SunriceBridge::pagePath($entry);

        return $path === null ? [] : [[
            'label' => 'Edit with Visual Editor',
            'href' => route('sunrice.admin.visual-editor.show', ['url' => $path], false),
        ]];
    }
}
