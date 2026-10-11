<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\View\FileViewFinder;
use Laravel\Mcp\Server\Tool;
use Sunrice\Fields\BlueprintSchema;
use Sunrice\Fields\CustomField;
use Sunrice\Fields\FieldRegistry;
use Sunrice\Mcp\Templates;
use Sunrice\Models\Collection;
use Sunrice\Models\Fieldset;
use Sunrice\Models\Form;
use Sunrice\Models\GlobalSet;
use Sunrice\Models\Menu;
use Sunrice\Models\Taxonomy;
use Sunrice\Sunrice;

/**
 * Everything the editor needs from Sunrice that Sunrice does not publish as
 * API yet. This is the only file allowed to touch Sunrice's internals
 * (models, Sunrice\Mcp\Templates): when Sunrice gains the public API asked
 * for in EXECUTION_PLAN.md V0.4 (C1–C3), only this file changes.
 */
final class SunriceBridge
{
    /** The site's templates: view names "sunrice.…". */
    public static function templateDirectory(): string
    {
        return rtrim((string) (config('sunrice-visual-editor.paths.templates') ?? Templates::appDirectory()), '/');
    }

    /** Theme files (CSS, JS…), served from /sunrice-theme. */
    public static function themeDirectory(): string
    {
        return rtrim((string) (config('sunrice-visual-editor.paths.theme') ?? Templates::themeDirectory()), '/');
    }

    /**
     * Sunrice's own views, shown read-only: the fallback templates
     * (sunrice::defaults.*) and the views of its Blade components.
     *
     * @return list<string>
     */
    public static function packageViewDirectories(): array
    {
        $finder = View::getFinder();
        $hints = $finder instanceof FileViewFinder ? $finder->getHints() : [];

        return array_values(array_filter((array) ($hints['sunrice'] ?? []), is_dir(...)));
    }

    /**
     * The view each collection and taxonomy uses right now (C1).
     *
     * @return list<array{view: string, role: 'entry'|'listing'|'term', subject: 'collection'|'taxonomy', handle: string}>
     */
    public static function templateMap(): array
    {
        $map = Templates::map();
        $uses = [];

        foreach ($map['collections'] ?? [] as $handle => $pages) {
            foreach (['entry_page' => 'entry', 'listing_page' => 'listing'] as $key => $role) {
                if (isset($pages[$key]['uses'])) {
                    $uses[] = ['view' => (string) $pages[$key]['uses'], 'role' => $role, 'subject' => 'collection', 'handle' => (string) $handle];
                }
            }
        }
        foreach ($map['taxonomies'] ?? [] as $handle => $page) {
            if (isset($page['uses'])) {
                $uses[] = ['view' => (string) $page['uses'], 'role' => 'term', 'subject' => 'taxonomy', 'handle' => (string) $handle];
            }
        }

        return $uses;
    }

    /**
     * Adds tools to Sunrice's MCP server (C3): `Sunrice::registerMcpTool()`,
     * which came with sunrice-cms#40 on 11 October 2026.
     *
     * Sunrice has no tagged release, so Composer can't be told to bring one
     * that has it: a site whose Sunrice is a checkout from before that day
     * has no such method. There the tools are not offered to agents, and
     * nothing fails. The check goes when Sunrice tags a release to require.
     *
     * @param  list<class-string>  $tools
     * @return bool whether they were added
     */
    public static function registerMcpTools(array $tools): bool
    {
        $sunrice = app(Sunrice::class);
        // @phpstan-ignore function.alreadyNarrowedType (always there in the Sunrice this is analysed with, not in every one it runs with)
        if (! class_exists(Tool::class) || ! method_exists($sunrice, 'registerMcpTool')) {
            return false;
        }
        foreach ($tools as $tool) {
            $sunrice->registerMcpTool($tool);
        }

        return true;
    }

    /**
     * Keeps a copy of a file about to be changed, where Sunrice keeps the
     * ones for templates written by AI agents. Returns the copy's path.
     *
     * @param  string  $file  absolute path of the file
     * @param  string  $relative  its path inside the template or theme directory
     * @param  'templates'|'theme'  $kind
     */
    public static function backup(string $file, string $relative, string $kind = 'templates'): string
    {
        $folder = $kind === 'theme' ? 'sunrice-theme' : 'sunrice';
        $now = date('Y-m-d_His');
        $place = fn (string $stamp): string => storage_path('app/sunrice/template-backups/'.$stamp.'/'.$folder.'/'.ltrim($relative, '/'));
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
     * Field definitions with fieldset includes expanded (C2).
     *
     * @param  string  $kind  collection, listing, fieldset, taxonomy or global; anything else has no fields
     * @return list<array<string, mixed>> each with handle, type, label and, when set, config
     */
    public static function fields(string $kind, string $handle): array
    {
        $schema = match ($kind) {
            'collection' => Collection::query()->where('handle', $handle)->first()?->blueprint?->schema(),
            'listing' => Collection::query()->where('handle', $handle)->first()?->archiveSchema(),
            'fieldset' => Fieldset::query()->where('handle', $handle)->first()?->schema(),
            'taxonomy' => Taxonomy::query()->where('handle', $handle)->first()?->blueprint?->schema(),
            'global' => GlobalSet::query()->where('handle', $handle)->first()?->blueprint?->schema(),
            default => null,
        };

        return $schema instanceof BlueprintSchema ? self::clean($schema->fields()) : [];
    }

    /**
     * `single`: whether each entry has a page of its own, and so a URL.
     *
     * @return list<array{handle: string, title: string, single: bool}>
     */
    public static function collections(): array
    {
        return Collection::query()->orderBy('title')->get()
            ->map(fn (Collection $collection) => ['handle' => (string) $collection->handle, 'title' => (string) $collection->title, 'single' => $collection->hasSinglePages()])
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
            ->map(fn (Taxonomy $taxonomy) => ['handle' => (string) $taxonomy->handle, 'title' => (string) $taxonomy->title, 'archive' => (bool) $taxonomy->setting('has_archive', false)])
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
        $registry = app(FieldRegistry::class);
        for ($depth = 0; $depth < 5 && $registry->has($type); $depth++) {
            $class = $registry->get($type)::class;
            if (! is_subclass_of($class, CustomField::class)) {
                break;
            }
            $type = $class::baseType();
        }

        return $type;
    }

    /** @return list<array{handle: string, title: string}> */
    public static function forms(): array
    {
        return Form::query()->orderBy('title')->get()
            ->map(fn (Form $form) => ['handle' => (string) $form->handle, 'title' => (string) $form->title])
            ->values()->all();
    }

    /** @return list<array{handle: string, title: string, group: string}> */
    public static function globals(): array
    {
        return GlobalSet::query()->orderBy('title')->get()
            ->map(fn (GlobalSet $set) => ['handle' => (string) $set->handle, 'title' => (string) $set->title, 'group' => (string) $set->group])
            ->values()->all();
    }

    /** @return list<array{handle: string, title: string}> */
    public static function menus(): array
    {
        return Menu::query()->orderBy('title')->get()
            ->map(fn (Menu $menu) => ['handle' => (string) $menu->handle, 'title' => (string) $menu->title])
            ->values()->all();
    }

    /**
     * Field definitions without the blueprint builder's own keys.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private static function clean(array $fields): array
    {
        $clean = [];
        foreach ($fields as $field) {
            $config = (array) ($field['config'] ?? []);
            if (isset($config['fields']) && is_array($config['fields'])) {
                $config['fields'] = self::clean($config['fields']);
            }
            $clean[] = array_filter([
                'handle' => (string) ($field['handle'] ?? ''),
                'type' => (string) ($field['type'] ?? ''),
                'label' => (string) ($field['label'] ?? $field['handle'] ?? ''),
                'config' => $config,
            ], fn ($value) => $value !== []);
        }

        return $clean;
    }
}
