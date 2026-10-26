<?php

declare(strict_types=1);

namespace Workbench\App\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Sunrice\Cms\Actions\Entries\SetHomepage;
use Sunrice\Cms\Models\Collection;
use Sunrice\Cms\Models\Entry;
use Sunrice\Cms\Models\Fieldset;
use Sunrice\Cms\Settings\SiteSettings;
use Sunrice\Cms\Structure\StructureImporter;
use Sunrice\Cms\Support\Locales;
use Sunrice\VisualEditor\Support\SunriceBridge;

/**
 * A small site to edit, drawn by the CMS's starter templates: pages built
 * from hero, text and gallery blocks, five articles with a listing page,
 * a `site` and a `footer` global set, and a `main` menu. The blocks'
 * templates (workbench/templates) are added to the site's templates when
 * it has none of that name.
 */
class DemoSeeder extends Seeder
{
    public const SITE_NAME = 'Sunrice Demo';

    public const HERO_HEADING = 'Templates you can see';

    public const HERO_LINK = 'https://example.com/docs';

    public function run(StructureImporter $importer): void
    {
        $templates = SunriceBridge::templateDirectory();
        foreach (File::allFiles(dirname(__DIR__, 2).'/templates') as $file) {
            $to = $templates.'/'.$file->getRelativePathname();
            if (! is_file($to)) {
                File::ensureDirectoryExists(dirname($to));
                File::copy($file->getPathname(), $to);
            }
        }

        // Safe to run again: Testbench seeds once for `seeders:` in
        // testbench.yaml and once for `migrate-fresh --seeder`.
        if (Fieldset::query()->where('handle', 'hero')->exists()) {
            return;
        }
        $main = Locales::default();
        $field = fn (string $handle, string $type, string $label, array $config = []): array => ['handle' => $handle, 'type' => $type, 'label' => $label, 'config' => $config];

        $importer->import([
            'fieldsets' => [
                ['handle' => 'hero', 'title' => 'Hero', 'fields' => [
                    $field('heading', 'text', 'Heading'),
                    $field('subheading', 'textarea', 'Subheading'),
                    $field('button', 'link', 'Button'),
                ]],
                ['handle' => 'text', 'title' => 'Text', 'fields' => [$field('body', 'rich_text', 'Body')]],
                ['handle' => 'gallery', 'title' => 'Gallery', 'fields' => [
                    $field('title', 'text', 'Title'),
                    $field('images', 'media', 'Images', ['multiple' => true]),
                ]],
            ],
            'blueprints' => [
                ['handle' => 'page', 'title' => 'Page', 'fields' => [
                    $field('body', 'rich_text', 'Body'),
                    $field('sections', 'flexible', 'Sections', ['blocks' => ['hero', 'text', 'gallery']]),
                ]],
                ['handle' => 'article', 'title' => 'Article', 'fields' => [
                    $field('excerpt', 'textarea', 'Excerpt'),
                    $field('body', 'rich_text', 'Body'),
                ]],
                ['handle' => 'site', 'title' => 'Site', 'fields' => [$field('name', 'text', 'Name')]],
                ['handle' => 'footer', 'title' => 'Footer', 'fields' => [$field('text', 'text', 'Text')]],
            ],
            'collections' => [
                ['handle' => 'pages', 'title' => 'Pages', 'blueprint' => 'page', 'route' => '/{slug}'],
                ['handle' => 'articles', 'title' => 'Articles', 'blueprint' => 'article', 'dated' => true, 'route' => '/articles/{slug}', 'listing_route' => '/articles'],
            ],
            'globals' => [
                ['handle' => 'site', 'title' => 'Site', 'blueprint' => 'site', 'values' => [$main => ['name' => self::SITE_NAME]]],
                ['handle' => 'footer', 'title' => 'Footer', 'blueprint' => 'footer', 'values' => [$main => ['text' => 'Built with Sunrice CMS.']]],
            ],
        ]);

        $entry = fn (string $collection, string $title, array $data): array => ['collection' => $collection, 'slug' => str($title)->slug()->toString(), 'translations' => [
            $main => ['title' => $title, 'slug' => str($title)->slug()->toString(), 'published' => true, 'data' => $data],
        ]];
        $importer->import([
            'entries' => [
                $entry('pages', 'Home', ['sections' => [
                    ['type' => 'hero', 'key' => 'home-hero', 'values' => [
                        'heading' => self::HERO_HEADING,
                        'subheading' => 'This page is drawn by a layout and one partial per block.',
                        'button' => ['type' => 'url', 'url' => self::HERO_LINK, 'label' => 'Read the docs', 'new_tab' => false],
                    ]],
                    ['type' => 'text', 'key' => 'home-text', 'values' => [
                        'body' => '<p>Each block on this page has its own Blade file under <code>blocks/</code>.</p>',
                    ]],
                ]]),
                $entry('pages', 'About', ['body' => '<p>A page with a rich text body and no blocks.</p>']),
                ...array_map(fn (string $title): array => $entry('articles', $title, [
                    'excerpt' => "A short summary of the {$title}.",
                    'body' => "<p>The body of the {$title}.</p>",
                ]), ['First post', 'Second post', 'Third post', 'Fourth post', 'Fifth post']),
            ],
            'menus' => [['handle' => 'main', 'title' => 'Main menu', 'items' => [
                ['entry' => 'pages/home', 'labels' => [$main => 'Home']],
                ['collection' => 'articles', 'labels' => [$main => 'Articles']],
                ['entry' => 'pages/about', 'labels' => [$main => 'About']],
            ]]],
        ]);

        $pages = Collection::query()->where('handle', 'pages')->sole();
        app(SetHomepage::class)->handle(Entry::query()->whereBelongsTo($pages)->whereHas('translations', fn ($query) => $query->where('slug', 'home'))->sole());
        $site = app(SiteSettings::class);
        $site->name = self::SITE_NAME;
        $site->save();
    }
}
