<?php

declare(strict_types=1);

namespace Workbench\App\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Sunrice\Actions\Entries\PublishTranslation;
use Sunrice\Models\Blueprint;
use Sunrice\Models\Collection;
use Sunrice\Models\Entry;
use Sunrice\Models\Fieldset;
use Sunrice\Models\GlobalSet;
use Sunrice\Models\Menu;
use Sunrice\Models\Setting;
use Sunrice\Support\Locales;

/**
 * A small site to edit: the content Sunrice's starter templates expect.
 * Pages built from hero/text/gallery blocks, five articles with a listing
 * page, a `site` and a `footer` global and a `main` menu.
 */
class DemoSeeder extends Seeder
{
    public const SITE_NAME = 'Sunrice Demo';

    public const HERO_HEADING = 'Templates you can see';

    public const HERO_LINK = 'https://example.com/docs';

    public function run(): void
    {
        // Safe to run again: Testbench seeds once for `seeders:` in
        // testbench.yaml and once for `migrate-fresh --seeder`.
        if (Fieldset::query()->where('handle', 'hero')->exists()) {
            return;
        }

        Fieldset::create(['handle' => 'hero', 'title' => 'Hero', 'fields' => [
            ['handle' => 'heading', 'type' => 'text', 'label' => 'Heading'],
            ['handle' => 'subheading', 'type' => 'textarea', 'label' => 'Subheading'],
            ['handle' => 'button', 'type' => 'link', 'label' => 'Button'],
        ]]);
        Fieldset::create(['handle' => 'text', 'title' => 'Text', 'fields' => [
            ['handle' => 'body', 'type' => 'rich_text', 'label' => 'Body'],
        ]]);
        Fieldset::create(['handle' => 'gallery', 'title' => 'Gallery', 'fields' => [
            ['handle' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['handle' => 'images', 'type' => 'asset', 'label' => 'Images', 'config' => ['multiple' => true]],
        ]]);

        $page = Blueprint::create(['handle' => 'page', 'title' => 'Page', 'fields' => [
            ['handle' => 'body', 'type' => 'rich_text', 'label' => 'Body'],
            ['handle' => 'sections', 'type' => 'flexible', 'label' => 'Sections', 'config' => ['fieldsets' => ['hero', 'text', 'gallery']]],
        ]]);
        $article = Blueprint::create(['handle' => 'article', 'title' => 'Article', 'fields' => [
            ['handle' => 'excerpt', 'type' => 'textarea', 'label' => 'Excerpt'],
            ['handle' => 'body', 'type' => 'rich_text', 'label' => 'Body'],
        ]]);

        $pages = Collection::create([
            'handle' => 'pages', 'title' => 'Pages', 'blueprint_id' => $page->id,
            'settings' => ['route' => '/{slug}', 'has_single' => true, 'has_archive' => false, 'translatable' => false, 'sluggable' => true],
        ]);
        $articles = Collection::create([
            'handle' => 'articles', 'title' => 'Articles', 'blueprint_id' => $article->id,
            'settings' => ['route' => '/articles/{slug}', 'archive_route' => '/articles', 'has_single' => true, 'has_archive' => true, 'translatable' => false, 'sluggable' => true],
        ]);

        $home = $this->entry($pages, $page, 'Home', [
            'sections' => [
                ['id' => 'home-hero', 'type' => 'hero', 'values' => [
                    'heading' => self::HERO_HEADING,
                    'subheading' => 'This page is drawn by a layout, a header, a footer and one partial per block.',
                    'button' => ['type' => 'url', 'url' => self::HERO_LINK, 'label' => 'Read the docs', 'new_tab' => false],
                ]],
                ['id' => 'home-text', 'type' => 'text', 'values' => [
                    'body' => '<p>Each block on this page has its own Blade file under <code>blocks/</code>.</p>',
                ]],
            ],
        ]);
        $about = $this->entry($pages, $page, 'About', ['body' => '<p>A page with a rich text body and no blocks.</p>']);

        foreach (['First post', 'Second post', 'Third post', 'Fourth post', 'Fifth post'] as $title) {
            $this->entry($articles, $article, $title, [
                'excerpt' => "A short summary of the {$title}.",
                'body' => "<p>The body of the {$title}.</p>",
            ]);
        }

        Setting::set('homepage_entry_id', $home->id);

        // Sunrice before it translated globals like entries had a set-wide "translatable" switch and
        // kept a set that was not translatable in a row of no language; since, every row has its language.
        $shared = Schema::hasColumn('sunrice_globals', 'translatable');
        $global = fn (array $set) => GlobalSet::create($set + ($shared ? ['translatable' => false] : []));
        $locale = $shared ? null : Locales::main();

        $siteBlueprint = Blueprint::create(['handle' => 'site', 'title' => 'Site', 'fields' => [
            ['handle' => 'name', 'type' => 'text', 'label' => 'Name'],
        ]]);
        $site = $global(['handle' => 'site', 'title' => 'Site', 'blueprint_id' => $siteBlueprint->id, 'group' => 'global']);
        $site->values()->create(['locale' => $locale, 'data' => ['name' => self::SITE_NAME]]);

        $footerBlueprint = Blueprint::create(['handle' => 'footer', 'title' => 'Footer', 'fields' => [
            ['handle' => 'text', 'type' => 'rich_text', 'label' => 'Text'],
        ]]);
        $footer = $global(['handle' => 'footer', 'title' => 'Footer', 'blueprint_id' => $footerBlueprint->id, 'group' => 'template_part']);
        $footer->values()->create(['locale' => $locale, 'data' => ['text' => '<p>Built with Sunrice CMS.</p>']]);

        $menu = Menu::create(['handle' => 'main', 'title' => 'Main menu']);
        $label = fn (string $text): array => [Locales::main() => $text];
        $menu->items()->create(['type' => 'entry', 'target_id' => $home->id, 'labels' => $label('Home'), 'sort_order' => 0]);
        $menu->items()->create(['type' => 'collection', 'target_id' => $articles->id, 'labels' => $label('Articles'), 'sort_order' => 1]);
        $menu->items()->create(['type' => 'entry', 'target_id' => $about->id, 'labels' => $label('About'), 'sort_order' => 2]);
    }

    /**
     * A published entry in the main language.
     *
     * @param  array<string, mixed>  $data
     */
    protected function entry(Collection $collection, Blueprint $blueprint, string $title, array $data): Entry
    {
        $entry = Entry::create(['collection_id' => $collection->id, 'blueprint_id' => $blueprint->id, 'status' => 'draft']);
        $translation = $entry->translations()->create([
            'collection_id' => $collection->id,
            'locale' => Locales::main(),
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'data' => $data,
            'is_ready' => true,
        ]);
        app(PublishTranslation::class)->handle($translation);

        return $entry->refresh();
    }
}
