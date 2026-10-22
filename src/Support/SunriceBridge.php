<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\View\FileViewFinder;
use Sunrice\Admin\Activity\ActivityLog;
use Sunrice\Admin\Panel;
use Sunrice\Cms\Fields\CustomField;
use Sunrice\Cms\Fields\Field;
use Sunrice\Cms\Fields\FieldTypes;
use Sunrice\Cms\Models\Collection;
use Sunrice\Cms\Models\Entry;
use Sunrice\Cms\Models\EntryTranslation;
use Sunrice\Cms\Models\Fieldset;
use Sunrice\Cms\Models\Form;
use Sunrice\Cms\Models\GlobalSet;
use Sunrice\Cms\Models\Menu;
use Sunrice\Cms\Models\Taxonomy;
use Sunrice\Cms\Support\SiteCache;

/**
 * Everything the editor reads from Sunrice, in one place. It uses the
 * panel's and the CMS's public API (classes marked `@api`) and the
 * conventions their docs promise (templates are the views "cms.…" under
 * resources/views/cms; an entry renders with its own template, then its
 * collection's, then "cms.{handle}", then "cms.entry"), never their
 * internals.
 */
final class SunriceBridge
{
    /** The CMS's view namespace: its fallbacks and components. */
    public const PACKAGE_NAMESPACE = 'sunrice-cms';

    /** The prefix of the site's own templates: "cms.partials.card". */
    public const VIEW_PREFIX = 'cms';

    /** Where the site's theme files are served from: /cms/site.css. */
    public const THEME_URL = 'cms';

    /**
     * An entry as the editor links to it: its id, its title and the address
     * of its page in the panel, in the language shown. Null for anything
     * that is no entry: an entry's page gives its template the entry in its
     * language (an EntryTranslation).
     *
     * @return array{id: int, title: string, edit: string}|null
     */
    public static function entryLink(mixed $translation): ?array
    {
        if (! $translation instanceof EntryTranslation || ! Route::has('sunrice.admin.cms.collections.entries.edit')) {
            return null;
        }
        $entry = $translation->entry;

        return [
            'id' => $entry->id,
            'title' => (string) ($translation->title ?? $translation->draft_title),
            'edit' => route('sunrice.admin.cms.collections.entries.edit', [
                'collection' => $entry->collection->handle,
                'entry' => $entry->id,
                'locale' => $translation->locale,
            ], false),
        ];
    }

    /** The site's templates: the views "cms.…" (resources/views/cms). */
    public static function templateDirectory(): string
    {
        $configured = config('sunrice-visual-editor.paths.templates');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }
        // The first of view.paths, as Laravel looks there first.
        $paths = config('view.paths');
        $first = is_array($paths) ? reset($paths) : false;

        return rtrim(is_string($first) && $first !== '' ? $first : resource_path('views'), '/').'/'.self::VIEW_PREFIX;
    }

    /** The site's theme files (CSS, JS…), served from /cms: where the starter stylesheet goes (public/cms/site.css). */
    public static function themeDirectory(): string
    {
        $configured = config('sunrice-visual-editor.paths.theme');

        return rtrim(is_string($configured) && $configured !== '' ? $configured : public_path('cms'), '/');
    }

    /** A theme file's address, as templates write it: asset('cms/site.css'). */
    public static function themeUrl(string $path): string
    {
        return asset(self::THEME_URL.'/'.ltrim($path, '/'));
    }

    /**
     * The CMS's own views, shown read-only: its fallback pages and the
     * views of its Blade components.
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
     * The view each kind of page uses right now: the first of the CMS's
     * candidates that exists, else its fallback. An entry or a term with a
     * template of its own uses that.
     *
     * @return list<array{view: string, role: 'entry'|'listing'|'term'|'search', subject: 'collection'|'taxonomy'|'site', handle: string}>
     */
    public static function templateMap(): array
    {
        $first = function (array $candidates, string $fallback): string {
            foreach ($candidates as $view) {
                if (is_string($view) && $view !== '' && View::exists($view)) {
                    return $view;
                }
            }

            return $fallback;
        };
        $prefix = self::VIEW_PREFIX.'.';
        $package = self::PACKAGE_NAMESPACE.'::';
        $uses = [];
        $collections = Collection::query()->orderBy('title')->get(['id', 'handle', 'title', 'route', 'listing_route', 'template']);
        foreach ($collections as $collection) {
            if ($collection->route !== null) {
                $uses[] = ['view' => $first([$collection->template, $prefix.$collection->handle, $prefix.'entry'], $package.'entry'), 'role' => 'entry', 'subject' => 'collection', 'handle' => $collection->handle];
            }
            if ($collection->listing_route !== null) {
                $uses[] = ['view' => $first([$prefix.$collection->handle.'-listing', $prefix.'listing'], $package.'listing'), 'role' => 'listing', 'subject' => 'collection', 'handle' => $collection->handle];
            }
        }
        // Entries with a template of their own (Page settings), by collection: one query for all.
        $handles = $collections->pluck('handle', 'id')->all();
        $own = Entry::query()->whereNotNull('template')->where('template', '!=', '')->distinct()->get(['collection_id', 'template']);
        foreach ($own as $entry) {
            $handle = $handles[$entry->collection_id] ?? null;
            if (is_string($handle) && is_string($entry->template) && str_starts_with($entry->template, $prefix) && View::exists($entry->template)) {
                $uses[] = ['view' => $entry->template, 'role' => 'entry', 'subject' => 'collection', 'handle' => $handle];
            }
        }
        foreach (Taxonomy::query()->whereNotNull('route')->orderBy('title')->get(['handle', 'template']) as $taxonomy) {
            $uses[] = ['view' => $first([$taxonomy->template, $prefix.'taxonomy-'.$taxonomy->handle, $prefix.'term'], $package.'term'), 'role' => 'term', 'subject' => 'taxonomy', 'handle' => $taxonomy->handle];
        }
        $uses[] = ['view' => $first([$prefix.'search'], $package.'search'), 'role' => 'search', 'subject' => 'site', 'handle' => 'search'];

        return array_values(array_unique($uses, SORT_REGULAR));
    }

    /**
     * Keeps a copy of a file about to be changed, beside the ones the CMS
     * keeps of templates written by AI agents
     * (storage/app/sunrice-cms/template-backups). Returns the copy's path.
     *
     * @param  string  $file  absolute path of the file
     * @param  string  $relative  its path inside the template or theme directory
     * @param  'templates'|'theme'  $kind
     */
    public static function backup(string $file, string $relative, string $kind = 'templates'): string
    {
        $folder = $kind === 'theme' ? 'theme' : self::VIEW_PREFIX;
        $now = date('Y-m-d_His');
        $place = fn (string $stamp): string => storage_path('app/sunrice-cms/template-backups/'.$stamp.'/'.$folder.'/'.ltrim($relative, '/'));
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
     * A line in the panel's activity log (Activity), and the site's kept
     * pages forgotten: they were drawn with what changed.
     *
     * @param  string  $subject  template, stylesheet, script or build
     */
    public static function changed(string $action, string $subject, string $label): void
    {
        app(ActivityLog::class)->record($action, $subject, null, $label, ['note' => 'Visual editor']);
        if ($subject !== 'build') {
            app(SiteCache::class)->changed("{$subject} {$label}", SiteCache::PAGES);
        }
    }

    /** The guard people sign in to the panel with. */
    public static function guard(): string
    {
        return app(Panel::class)->getGuard();
    }

    /** Who is signed in to the panel, if anyone. */
    public static function user(): ?Authorizable
    {
        $user = auth(self::guard())->user();

        return $user instanceof Authorizable ? $user : null;
    }

    /** The panel's path: "admin". */
    public static function adminPath(): string
    {
        return app(Panel::class)->getPath();
    }

    /**
     * Field definitions with fieldset includes expanded.
     *
     * @param  string  $kind  collection (every blueprint its entries may use), listing, fieldset, taxonomy or global; anything else has no fields
     * @return list<array<string, mixed>> each with handle, type, label and, when set, config
     */
    public static function fields(string $kind, string $handle): array
    {
        $schemas = match ($kind) {
            'collection' => Collection::query()->where('handle', $handle)->first()?->allSchemas() ?? [],
            'listing' => array_filter([Collection::query()->where('handle', $handle)->first()?->listingSchema()]),
            'fieldset' => array_filter([Fieldset::query()->where('handle', $handle)->first()?->schema()]),
            'taxonomy' => array_filter([Taxonomy::query()->where('handle', $handle)->first()?->schema()]),
            'global' => array_filter([GlobalSet::query()->where('handle', $handle)->first()?->schema()]),
            default => [],
        };
        // The fields of every blueprint, each once: the first blueprint's order, then the others' own.
        $fields = [];
        foreach ($schemas as $schema) {
            foreach ($schema->fields() as $field) {
                $fields[$field->handle] ??= $field;
            }
        }

        return self::clean(array_values($fields));
    }

    /**
     * `single`: whether each entry has a page of its own, and so a URL.
     *
     * @return list<array{handle: string, title: string, single: bool}>
     */
    public static function collections(): array
    {
        return Collection::query()->orderBy('title')->get(['handle', 'title', 'route'])
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
        return Taxonomy::query()->orderBy('title')->get(['handle', 'title', 'route'])
            ->map(fn (Taxonomy $taxonomy) => ['handle' => (string) $taxonomy->handle, 'title' => (string) $taxonomy->title, 'archive' => $taxonomy->route !== null])
            ->values()->all();
    }

    /** @return list<array{handle: string, title: string}> */
    public static function fieldsets(): array
    {
        return Fieldset::query()->orderBy('title')->get(['handle', 'title'])
            ->map(fn (Fieldset $fieldset) => ['handle' => (string) $fieldset->handle, 'title' => (string) $fieldset->title])
            ->values()->all();
    }

    /**
     * The built-in field type a type stores and hands to templates like:
     * itself, or for a site's custom field the type it is built on.
     */
    public static function baseType(string $type): string
    {
        $registry = app(FieldTypes::class);
        for ($depth = 0; $depth < 5 && $registry->has($type); $depth++) {
            $field = $registry->get($type);
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
        return Form::query()->orderBy('title')->get(['handle', 'title'])
            ->map(fn (Form $form) => ['handle' => (string) $form->handle, 'title' => (string) $form->title])
            ->values()->all();
    }

    /** @return list<array{handle: string, title: string}> */
    public static function globals(): array
    {
        return GlobalSet::query()->orderBy('title')->get(['handle', 'title'])
            ->map(fn (GlobalSet $set) => ['handle' => (string) $set->handle, 'title' => (string) $set->title])
            ->values()->all();
    }

    /** @return list<array{handle: string, title: string}> */
    public static function menus(): array
    {
        return Menu::query()->orderBy('title')->get(['handle', 'title'])
            ->map(fn (Menu $menu) => ['handle' => (string) $menu->handle, 'title' => (string) $menu->title])
            ->values()->all();
    }

    /**
     * The image sizes the panel makes of an upload, by name: [width, height, mode].
     *
     * @return array<string, mixed>
     */
    public static function imageSizes(): array
    {
        $sizes = config('sunrice-admin.media.image_sizes', []);

        return is_array($sizes) ? $sizes : [];
    }

    /**
     * Field definitions as plain data.
     *
     * @param  list<Field>  $fields
     * @return list<array<string, mixed>>
     */
    private static function clean(array $fields): array
    {
        $clean = [];
        foreach ($fields as $field) {
            $config = $field->config;
            if ($field->fields !== []) {
                $config['fields'] = self::clean($field->fields);
            }
            $clean[] = array_filter([
                'handle' => $field->handle,
                'type' => $field->type,
                'label' => $field->label !== '' ? $field->label : $field->handle,
                'config' => $config,
            ], fn ($value) => $value !== []);
        }

        return $clean;
    }
}
