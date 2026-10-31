<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\View\FileViewFinder;
use Sunrice\Cms\Fields\CustomField;
use Sunrice\Cms\Fields\Field as CmsField;
use Sunrice\Cms\Fields\FieldTypes;
use Sunrice\Cms\Fields\Schema;
use Sunrice\Cms\Models\Blueprint;
use Sunrice\Cms\Models\Collection;
use Sunrice\Cms\Models\Entry;
use Sunrice\Cms\Models\EntryTranslation;
use Sunrice\Cms\Models\Fieldset;
use Sunrice\Cms\Models\Form;
use Sunrice\Cms\Models\GlobalSet;
use Sunrice\Cms\Models\Menu;
use Sunrice\Cms\Models\Taxonomy;
use Sunrice\Cms\Site\SiteCsp;
use Sunrice\Cms\Support\Access as CmsAccess;
use Sunrice\Cms\Support\SiteCache;
use Sunrice\Cms\Support\Templates;

/**
 * Everything the editor needs from the CMS that the CMS does not publish
 * as API: where its templates are and which one each page uses
 * (Sunrice\Cms\Support\Templates, @internal there). This is the only file
 * allowed to touch the CMS's internals: when the CMS makes these public,
 * only this file changes. Its @api classes (models, Schema, FieldTypes,
 * SiteCache) are used here too, so the editor reads the CMS in one place.
 */
final class SunriceBridge
{
    /** The CMS's own views: `sunrice-cms::entry`. */
    public const PACKAGE_NAMESPACE = 'sunrice-cms';

    /** The site's templates: `cms.collections.show`, in resources/views/cms. */
    public const VIEW_PREFIX = 'cms';

    /**
     * An entry as the editor links to it: its id, its title and the address
     * of its page in the admin. Null for anything that is no entry.
     *
     * @return array{id: int, title: string, edit: string}|null
     */
    public static function entryLink(mixed $entry): ?array
    {
        $translation = $entry instanceof EntryTranslation ? $entry : null;
        $entry = $translation->entry ?? $entry;
        if (! $entry instanceof Entry || ! is_int($entry->getKey()) || ! Route::has('sunrice.admin.cms.entries.edit')) {
            return null;
        }
        $locale = $translation?->locale;

        return [
            'id' => $entry->getKey(),
            'title' => (string) ($translation->title ?? $entry->mainTranslation()->title ?? ''),
            'edit' => route('sunrice.admin.cms.entries.edit', ['entry' => $entry->getKey(), ...($locale === null ? [] : ['locale' => $locale])], false),
        ];
    }

    /**
     * Live pages whose title has these words, in any language: each
     * entry's title, its collection's and the page's path on the site.
     *
     * @return list<array{id: int, title: string, collection: string, path: string}>
     */
    public static function pages(string $search, int $limit = 8): array
    {
        $words = addcslashes($search, '%_\\');
        $found = [];
        foreach (EntryTranslation::query()->live()->with('entry.collection')->where('title', 'like', '%'.$words.'%')
            ->orderByRaw('length(title)')->limit($limit * 2)->get() as $translation) {
            $path = self::sitePath($translation);
            if ($path !== null && count($found) < $limit) {
                $found[] = ['id' => $translation->id, 'title' => $translation->title, 'collection' => $translation->entry->collection->title, 'path' => $path];
            }
        }

        return $found;
    }

    /**
     * The path of an entry's page on the site in its language, with any
     * query (/about, /id/tentang): null while it has none to open, being
     * not yet live (a draft, or dated to come), in the trash, or of a
     * collection without pages.
     */
    public static function pagePath(EntryTranslation $translation): ?string
    {
        $entry = $translation->entry;
        if (! $translation->isLive() || $entry->trashed() || $entry->collection->trashed()) {
            return null;
        }

        return self::sitePath($translation);
    }

    /** A live translation's page as a path on the site, with any query; null when it has none. */
    private static function sitePath(EntryTranslation $translation): ?string
    {
        $url = $translation->url();
        if ($url === null || ($parts = parse_url($url)) === false) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        $path = $path === '' ? '/' : $path;
        $query = $parts['query'] ?? null;

        return $path.(is_string($query) ? '?'.$query : '');
    }

    /** The site's templates: views "cms.…", in resources/views/cms. */
    public static function templateDirectory(): string
    {
        return rtrim((string) (config('sunrice-visual-editor.paths.templates') ?? Templates::directory()), '/');
    }

    /** Theme files (CSS, JS…) of the site: public/cms, where the starter templates' site.css is. */
    public static function themeDirectory(): string
    {
        return rtrim((string) (config('sunrice-visual-editor.paths.theme') ?? public_path(self::VIEW_PREFIX)), '/');
    }

    /** Where pages link a theme file from: asset('cms/site.css'). */
    public static function themeUrl(string $path): string
    {
        return asset(self::themeFolder().'/'.ltrim($path, '/'));
    }

    /** The theme directory as a path under public/ ("cms"), as pages link it. */
    public static function themeFolder(): string
    {
        $folder = config('sunrice-visual-editor.paths.theme_url');
        if (is_string($folder) && trim($folder, '/') !== '') {
            return trim($folder, '/');
        }
        $public = rtrim(public_path(), '/').'/';
        $directory = self::themeDirectory();

        return str_starts_with($directory, $public) ? substr($directory, strlen($public)) : self::VIEW_PREFIX;
    }

    /**
     * The CMS's own views, shown read-only: the pages it draws when the
     * site has no template for them (sunrice-cms::entry …) and the views of
     * its Blade components.
     *
     * @return list<string>
     */
    public static function packageViewDirectories(): array
    {
        $finder = View::getFinder();
        $hints = $finder instanceof FileViewFinder ? $finder->getHints() : [];

        return array_values(array_filter((array) ($hints[self::PACKAGE_NAMESPACE] ?? []), is_dir(...)));
    }

    /**
     * The views that draw the site's pages, as View::composer() takes
     * them: the site's own (under each of `sunrice-cms.site.template_prefixes`)
     * and the CMS's pages, not its mail.
     *
     * @return list<string>
     */
    public static function pageViews(): array
    {
        $prefixes = config('sunrice-cms.site.template_prefixes', ['cms.', 'pages.']);
        $site = is_array($prefixes) ? array_map(fn (mixed $prefix): string => rtrim((string) $prefix, '.').'.*', array_filter($prefixes, is_string(...))) : ['*'];

        return array_values(array_unique([self::VIEW_PREFIX.'.*', ...$site, ...array_map(fn (string $view) => self::PACKAGE_NAMESPACE.'::'.$view, ['entry', 'listing', 'term', 'taxonomy-index', 'search'])]));
    }

    /**
     * The view each collection's and taxonomy's pages use right now.
     *
     * @return list<array{view: string, role: 'entry'|'listing'|'term'|'index'|'search', subject: 'collection'|'taxonomy'|'site', handle: string}>
     */
    public static function templateMap(): array
    {
        $map = Templates::map();
        $uses = [];
        $collections = is_array($map['collections'] ?? null) ? $map['collections'] : [];
        foreach ($collections as $handle => $pages) {
            foreach (['entry_page' => 'entry', 'listing_page' => 'listing'] as $key => $role) {
                if (is_array($pages) && isset($pages[$key]['uses'])) {
                    $uses[] = ['view' => (string) $pages[$key]['uses'], 'role' => $role, 'subject' => 'collection', 'handle' => (string) $handle];
                }
            }
        }
        foreach (['taxonomies' => 'term', 'taxonomy_indexes' => 'index'] as $key => $role) {
            $taxonomies = is_array($map[$key] ?? null) ? $map[$key] : [];
            foreach ($taxonomies as $handle => $page) {
                if (is_array($page) && isset($page['uses'])) {
                    $uses[] = ['view' => (string) $page['uses'], 'role' => $role, 'subject' => 'taxonomy', 'handle' => (string) $handle];
                }
            }
        }
        if (is_array($map['search'] ?? null) && isset($map['search']['uses'])) {
            $uses[] = ['view' => (string) $map['search']['uses'], 'role' => 'search', 'subject' => 'site', 'handle' => 'search'];
        }

        return $uses;
    }

    /**
     * Keeps a copy of a file about to be changed, beside the ones the CMS
     * keeps for templates written by AI agents. Returns the copy's path.
     *
     * @param  string  $file  absolute path of the file
     * @param  string  $relative  its path inside the template or theme directory
     * @param  'templates'|'theme'  $kind
     */
    public static function backup(string $file, string $relative, string $kind = 'templates'): string
    {
        $now = date('Y-m-d_His');
        $place = fn (string $stamp): string => self::backups($stamp, $relative, $kind);
        // A second save of the same file within the second gets a place of its own.
        $target = $place($now);
        for ($n = 2; is_file($target); $n++) {
            $target = $place($now.'-'.$n);
        }
        Files::directory(dirname($target));
        File::copy($file, $target);

        return $target;
    }

    /**
     * Where the copy of a file made at a time is kept:
     * storage/app/sunrice-cms/template-backups/{time}/cms/{path}, or
     * public-cms/{path} for a theme file. A time of "*" is a pattern for
     * all of them.
     *
     * @param  'templates'|'theme'  $kind
     */
    public static function backups(string $time, string $relative, string $kind = 'templates'): string
    {
        $folder = $kind === 'theme' ? 'public-'.str_replace('/', '-', self::themeFolder()) : self::VIEW_PREFIX;

        return storage_path('app/sunrice-cms/template-backups/'.$time.'/'.$folder.'/'.ltrim($relative, '/'));
    }

    /**
     * ` nonce="…"` for a tag the editor adds to a page of the site, when
     * the site sends a Content-Security-Policy that asks for one
     * (sunrice-cms.site.csp); '' otherwise.
     */
    public static function nonceAttribute(): string
    {
        $nonce = SiteCsp::nonce();

        return $nonce === '' ? '' : ' nonce="'.e($nonce).'"';
    }

    /** A template or a theme file changed: pages the CMS keeps for visitors are drawn again. */
    public static function changed(string $what): void
    {
        app(SiteCache::class)->changed('visual editor: '.$what, SiteCache::PAGES);
    }

    /**
     * Field definitions with fieldset includes expanded.
     *
     * @param  string  $kind  collection, listing, fieldset, taxonomy, index (a taxonomy's index page) or global; anything else has no fields
     * @return list<array<string, mixed>> each with handle, type, label and, when set, config and fields
     */
    public static function fields(string $kind, string $handle): array
    {
        $schema = match ($kind) {
            'collection' => Collection::query()->where('handle', $handle)->first()?->schema(),
            'listing' => Collection::query()->where('handle', $handle)->first()?->listingSchema(),
            'fieldset' => Fieldset::query()->where('handle', $handle)->first()?->schema(),
            'taxonomy' => Taxonomy::query()->where('handle', $handle)->first()?->schema(),
            'index' => Taxonomy::query()->where('handle', $handle)->first()?->indexSchema(),
            'global' => GlobalSet::query()->where('handle', $handle)->first()?->schema(),
            default => null,
        };

        return $schema instanceof Schema ? self::clean($schema->fields()) : [];
    }

    /**
     * Native CMS editors for content values and field definitions. No values
     * are read here, and visual-editor permissions grant no CMS permissions.
     *
     * @return array{sources: array<string, array<string, mixed>>, entry: array<string, mixed>|null, manage: list<array{label: string, url: string}>}
     */
    public static function fieldEditors(mixed $user, int $entryId = 0, ?string $locale = null): array
    {
        $sources = [];
        $current = null;
        if (! $user instanceof Authorizable) {
            return ['sources' => $sources, 'entry' => $current, 'manage' => []];
        }
        $link = fn (string $name, array $parameters): ?string => Route::has($name) ? route($name, $parameters, false) : null;
        $blueprints = $user->can('cms.blueprints.update') ? Blueprint::query()->get(['handle', 'title'])->keyBy('handle') : collect();
        $definitions = function (array $handles) use ($blueprints, $link): array {
            $links = [];
            foreach (array_unique(array_filter($handles, is_string(...))) as $handle) {
                $blueprint = $blueprints->get($handle);
                $url = $blueprint === null ? null : $link('sunrice.admin.cms.blueprints.edit', ['blueprint' => $handle]);
                if ($url !== null) {
                    $links[] = ['label' => 'Edit fields: '.$blueprint->title, 'url' => $url];
                }
            }

            return $links;
        };
        $language = $locale === null ? [] : ['locale' => $locale];
        foreach (Collection::query()->orderBy('title')->get() as $collection) {
            $canView = CmsAccess::entries($user, 'view', $collection);
            $sources['collection:'.$collection->handle] = ['title' => $collection->title,
                'values' => $canView ? $link('sunrice.admin.cms.collections.entries.index', ['collection' => $collection->handle, ...$language]) : null,
                'definitions' => $definitions($collection->allowedBlueprints())];
            $sources['listing:'.$collection->handle] = ['title' => 'Listing: '.$collection->title,
                'values' => $canView ? $link('sunrice.admin.cms.collections.listing.edit', ['collection' => $collection->handle, ...$language]) : null,
                'definitions' => $definitions([$collection->listing_blueprint])];
        }
        foreach (Taxonomy::query()->orderBy('title')->get() as $taxonomy) {
            $canView = CmsAccess::terms($user, 'view', $taxonomy);
            $sources['taxonomy:'.$taxonomy->handle] = ['title' => $taxonomy->title,
                'values' => $canView ? $link('sunrice.admin.cms.taxonomies.terms.index', ['taxonomy' => $taxonomy->handle, ...$language]) : null,
                'definitions' => $definitions([$taxonomy->blueprint])];
            $sources['index:'.$taxonomy->handle] = ['title' => 'Index: '.$taxonomy->title,
                'values' => $canView ? $link('sunrice.admin.cms.taxonomies.index-page.edit', ['taxonomy' => $taxonomy->handle, ...$language]) : null,
                'definitions' => $definitions([$taxonomy->index_blueprint])];
        }
        foreach (GlobalSet::query()->orderBy('title')->get() as $set) {
            $sources['global:'.$set->handle] = ['title' => $set->title,
                'values' => $user->can('cms.globals.view') ? $link('sunrice.admin.cms.globals.values.edit', ['set' => $set->handle, ...$language]) : null,
                'definitions' => $definitions([$set->blueprint])];
        }
        if ($user->can('cms.fieldsets.update')) {
            foreach (Fieldset::query()->orderBy('title')->get() as $set) {
                $url = $link('sunrice.admin.cms.fieldsets.edit', ['fieldset' => $set->handle]);
                $sources['fieldset:'.$set->handle] = ['title' => $set->title, 'values' => null,
                    'definitions' => $url === null ? [] : [['label' => 'Edit fields: '.$set->title, 'url' => $url]]];
            }
        }
        if ($user->can('cms.menus.view')) {
            foreach (Menu::query()->orderBy('title')->get() as $menu) {
                $sources['menu:'.$menu->handle] = ['title' => $menu->title,
                    'values' => $link('sunrice.admin.cms.menus.links.edit', ['menu' => $menu->handle, ...$language]), 'definitions' => []];
            }
        }
        $entry = $entryId > 0 ? Entry::query()->with('collection')->find($entryId) : null;
        if ($entry !== null && ! $entry->collection->trashed() && CmsAccess::entries($user, 'view', $entry->collection, $entry, $locale)) {
            $translation = $locale === null ? $entry->mainTranslation() : $entry->translations()->where('locale', $locale)->first();
            if ($translation !== null) {
                $current = ['title' => $translation->title,
                    'values' => $link('sunrice.admin.cms.entries.edit', ['entry' => $entry->getKey(), 'locale' => $translation->locale]),
                    'definitions' => $definitions([$entry->blueprintHandle()])];
            }
        }

        $manage = [];
        foreach (['blueprints' => 'Blueprints', 'fieldsets' => 'Fieldsets'] as $area => $label) {
            $url = $user->can('cms.'.$area.'.view') ? $link('sunrice.admin.cms.'.$area.'.index', []) : null;
            if ($url !== null) {
                $manage[] = ['label' => $label.' ↗', 'url' => $url];
            }
        }

        return ['sources' => array_filter($sources, fn (array $source): bool => $source['values'] !== null || $source['definitions'] !== []), 'entry' => $current, 'manage' => $manage];
    }

    /**
     * `single`: whether each entry has a page of its own, and so a URL.
     *
     * @return list<array{handle: string, title: string, single: bool}>
     */
    public static function collections(): array
    {
        return Collection::query()->orderBy('title')->get()
            ->map(fn (Collection $collection) => ['handle' => $collection->handle, 'title' => $collection->title, 'single' => $collection->route !== null])
            ->values()->all();
    }

    /**
     * `archive`: whether each term has a page of its own, and so a URL that leads somewhere.
     *
     * @return list<array{handle: string, title: string, archive: bool}>
     */
    public static function taxonomies(): array
    {
        return Taxonomy::query()->orderBy('title')->get()
            ->map(fn (Taxonomy $taxonomy) => ['handle' => $taxonomy->handle, 'title' => $taxonomy->title, 'archive' => $taxonomy->hasTermPages()])
            ->values()->all();
    }

    /** @return list<array{handle: string, title: string}> */
    public static function fieldsets(): array
    {
        return Fieldset::query()->orderBy('title')->get()
            ->map(fn (Fieldset $fieldset) => ['handle' => (string) $fieldset->handle, 'title' => (string) $fieldset->title])
            ->values()->all();
    }

    /**
     * The built-in field type a type stores and hands to templates like:
     * itself, or for a site's custom field the type it is built on.
     */
    public static function baseType(string $type): string
    {
        $types = app(FieldTypes::class);
        for ($depth = 0; $depth < 5 && $types->has($type); $depth++) {
            $field = $types->get($type);
            if (! $field instanceof CustomField) {
                break;
            }
            $type = $field->baseType();
        }

        return $type;
    }

    /** @return list<array{handle: string, title: string}> */
    public static function forms(): array
    {
        return Form::query()->orderBy('title')->get()
            ->map(fn (Form $form) => ['handle' => $form->handle, 'title' => $form->title])
            ->values()->all();
    }

    /** @return list<array{handle: string, title: string}> */
    public static function globals(): array
    {
        return GlobalSet::query()->orderBy('title')->get()
            ->map(fn (GlobalSet $set) => ['handle' => $set->handle, 'title' => $set->title])
            ->values()->all();
    }

    /** @return list<array{handle: string, title: string}> */
    public static function menus(): array
    {
        return Menu::query()->orderBy('title')->get()
            ->map(fn (Menu $menu) => ['handle' => $menu->handle, 'title' => $menu->title])
            ->values()->all();
    }

    /**
     * Field definitions as plain arrays, without what only the admin's
     * editor reads.
     *
     * @param  list<CmsField>  $fields
     * @return list<array<string, mixed>>
     */
    private static function clean(array $fields): array
    {
        $clean = [];
        foreach ($fields as $field) {
            $clean[] = array_filter([
                'handle' => $field->handle,
                'type' => $field->type,
                'label' => $field->label !== '' ? $field->label : $field->handle,
                'config' => $field->config,
                'fields' => self::clean($field->fields),
            ], fn ($value) => $value !== []);
        }

        return $clean;
    }
}
