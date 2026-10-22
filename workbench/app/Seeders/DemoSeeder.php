<?php

declare(strict_types=1);

namespace Workbench\App\Seeders;

use Illuminate\Database\Seeder;
use Sunrice\Cms\Actions\Entries\CreateEntry;
use Sunrice\Cms\Actions\Entries\PublishEntry;
use Sunrice\Cms\Actions\Entries\SetHomepage;
use Sunrice\Cms\Actions\Globals\SaveGlobals;
use Sunrice\Cms\Actions\Menus\SaveMenu;
use Sunrice\Cms\Models\Blueprint;
use Sunrice\Cms\Models\Collection;
use Sunrice\Cms\Models\EntryTranslation;
use Sunrice\Cms\Models\Fieldset;
use Sunrice\Cms\Models\GlobalSet;
use Sunrice\Cms\Models\Menu;

/**
 * A small site to edit, made through the CMS's own actions: the content
 * its starter templates (vendor:publish --tag=sunrice-cms-templates) show.
 * Pages built from hero, text and gallery blocks, five articles with a
 * listing page, a `footer` global and a `main` and a `footer` menu.
 */
class DemoSeeder extends Seeder
{
    public const HERO_HEADING = 'Templates you can see';

    public const HERO_LINK = 'https://example.com/docs';

    public const FOOTER_TEXT = 'Built with Sunrice.';

    public function run(): void
    {
        // Safe to run again: Testbench seeds once for `seeders:` in
        // testbench.yaml and once for `migrate-fresh --seeder`.
        if (Fieldset::query()->where('handle', 'hero')->exists()) {
            return;
        }

        Fieldset::query()->create(['handle' => 'hero', 'title' => 'Hero', 'fields' => [
            ['handle' => 'heading', 'type' => 'text', 'label' => 'Heading'],
            ['handle' => 'subheading', 'type' => 'textarea', 'label' => 'Subheading'],
            ['handle' => 'button', 'type' => 'link', 'label' => 'Button'],
        ]]);
        Fieldset::query()->create(['handle' => 'text', 'title' => 'Text', 'fields' => [
            ['handle' => 'body', 'type' => 'rich_text', 'label' => 'Body'],
        ]]);
        Fieldset::query()->create(['handle' => 'gallery', 'title' => 'Gallery', 'fields' => [
            ['handle' => 'title', 'type' => 'text', 'label' => 'Title'],
            ['handle' => 'images', 'type' => 'media', 'label' => 'Images', 'config' => ['multiple' => true]],
        ]]);

        Blueprint::query()->create(['handle' => 'page', 'title' => 'Page', 'fields' => [
            ['handle' => 'body', 'type' => 'rich_text', 'label' => 'Body'],
            ['handle' => 'sections', 'type' => 'flexible', 'label' => 'Sections', 'config' => ['blocks' => ['hero', 'text', 'gallery']]],
        ]]);
        Blueprint::query()->create(['handle' => 'article', 'title' => 'Article', 'fields' => [
            ['handle' => 'excerpt', 'type' => 'textarea', 'label' => 'Excerpt'],
            ['handle' => 'body', 'type' => 'rich_text', 'label' => 'Body'],
        ]]);
        Blueprint::query()->create(['handle' => 'footer', 'title' => 'Footer', 'fields' => [
            ['handle' => 'text', 'type' => 'text', 'label' => 'Text'],
        ]]);

        $pages = Collection::query()->create(['handle' => 'pages', 'title' => 'Pages', 'blueprint' => 'page', 'route' => '/{slug}']);
        $articles = Collection::query()->create([
            'handle' => 'articles', 'title' => 'Articles', 'blueprint' => 'article', 'dated' => true,
            'route' => '/articles/{slug}', 'listing_route' => '/articles',
        ]);

        $home = $this->entry($pages, 'Home', [
            'sections' => [
                ['id' => 'home-hero', 'type' => 'hero', 'values' => [
                    'heading' => self::HERO_HEADING,
                    'subheading' => 'This page is drawn by a layout, its partials and one view per block.',
                    'button' => ['type' => 'url', 'url' => self::HERO_LINK, 'label' => 'Read the docs', 'new_tab' => false],
                ]],
                ['id' => 'home-text', 'type' => 'text', 'values' => [
                    'body' => '<p>Each block on this page is drawn by <code>cms/blocks/{type}.blade.php</code> when there is one.</p>',
                ]],
            ],
        ]);
        $about = $this->entry($pages, 'About', ['body' => '<p>A page with a rich text body and no blocks.</p>']);

        foreach (['First post', 'Second post', 'Third post', 'Fourth post', 'Fifth post'] as $title) {
            $this->entry($articles, $title, [
                'excerpt' => "A short summary of the {$title}.",
                'body' => "<p>The body of the {$title}.</p>",
            ]);
        }

        app(SetHomepage::class)->handle($home->entry);

        $footer = GlobalSet::query()->create(['handle' => 'footer', 'title' => 'Footer', 'blueprint' => 'footer']);
        app(SaveGlobals::class)->handle($footer, ['text' => self::FOOTER_TEXT]);

        app(SaveMenu::class)->handle(Menu::query()->create(['handle' => 'main', 'title' => 'Main menu']), [
            ['entry_id' => $home->entry_id],
            ['collection_id' => $articles->id],
            ['entry_id' => $about->entry_id],
        ]);
        app(SaveMenu::class)->handle(Menu::query()->create(['handle' => 'footer', 'title' => 'Footer menu']), [
            ['entry_id' => $about->entry_id],
        ]);
    }

    /**
     * A published entry in the main language.
     *
     * @param  array<string, mixed>  $data
     */
    protected function entry(Collection $collection, string $title, array $data): EntryTranslation
    {
        $translation = app(CreateEntry::class)->handle($collection, ['title' => $title, 'data' => $data]);

        return app(PublishEntry::class)->handle($translation);
    }
}
