<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Tests\Support;

use Sunrice\Admin\Media\Media;
use Sunrice\Cms\Actions\Entries\CreateEntry;
use Sunrice\Cms\Actions\Entries\PublishEntry;
use Sunrice\Cms\Models\Collection;
use Sunrice\Cms\Models\Entry;
use Sunrice\Cms\Models\Term;
use Sunrice\Cms\Structure\StructureImporter;
use Sunrice\Cms\Support\Locales;

/**
 * Content with a field of every type the CMS has, on top of the starter
 * site (WithStarterSite): a `things` collection with a listing page, a
 * `topics` taxonomy, and two things: one with every field filled and one
 * with none.
 */
trait WithEveryField
{
    protected Entry $everything;

    protected Entry $nothing;

    protected function setUpEveryField(): void
    {
        $main = Locales::default();
        $field = fn (string $handle, string $type, string $label, array $config = [], array $fields = []): array => ['handle' => $handle, 'type' => $type, 'label' => $label, 'config' => $config, 'fields' => $fields];
        $term = fn (string $slug, string $title, array $data = []): array => ['taxonomy' => 'topics', 'slug' => $slug, 'translations' => [$main => ['title' => $title, 'slug' => $slug, 'data' => $data]]];

        app(StructureImporter::class)->import([
            'fieldsets' => [
                ['handle' => 'cta', 'title' => 'Call to action', 'fields' => [$field('label', 'text', 'Label'), $field('target', 'link', 'Target')]],
            ],
            'blueprints' => [
                ['handle' => 'topic', 'title' => 'Topic', 'fields' => [$field('description', 'textarea', 'Description')]],
                ['handle' => 'thing', 'title' => 'Thing', 'fields' => [
                    $field('summary', 'text', 'Summary'),
                    $field('notes', 'textarea', 'Notes'),
                    $field('body', 'rich_text', 'Body'),
                    $field('price', 'number', 'Price'),
                    $field('featured', 'toggle', 'Featured'),
                    $field('colour', 'select', 'Colour', ['options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']]]),
                    $field('sizes', 'select', 'Sizes', ['options' => [['value' => 's', 'label' => 'Small'], ['value' => 'l', 'label' => 'Large']], 'multiple' => true]),
                    $field('made_on', 'date', 'Made on'),
                    $field('more', 'link', 'More'),
                    $field('cover', 'media', 'Cover'),
                    $field('photos', 'media', 'Photos', ['multiple' => true]),
                    $field('related', 'entries', 'Related articles', ['collections' => ['articles'], 'multiple' => true]),
                    $field('see_also', 'entries', 'See also', ['collections' => ['articles', 'pages'], 'multiple' => true]),
                    $field('topics', 'terms', 'Topics', ['taxonomy' => 'topics', 'multiple' => true]),
                    $field('details', 'group', 'Details', [], [
                        $field('material', 'text', 'Material'),
                        $field('weight', 'number', 'Weight'),
                        $field('picture', 'media', 'Picture'),
                    ]),
                    $field('steps', 'repeater', 'Steps', [], [
                        $field('label', 'text', 'Label'),
                        $field('text', 'rich_text', 'Text'),
                        $field('icon', 'media', 'Icon'),
                    ]),
                    $field('sections', 'flexible', 'Sections', ['blocks' => ['hero', 'text', 'cta']]),
                ]],
                ['handle' => 'things_listing', 'title' => 'Things listing', 'fields' => [
                    $field('image', 'media', 'Image'),
                    $field('description', 'rich_text', 'Description'),
                ]],
            ],
            'collections' => [
                ['handle' => 'things', 'title' => 'Things', 'blueprint' => 'thing', 'route' => '/things/{slug}', 'listing_route' => '/things', 'listing_blueprint' => 'things_listing'],
            ],
            'taxonomies' => [
                ['handle' => 'topics', 'title' => 'Topics', 'route' => '/topics/{slug}', 'blueprint' => 'topic', 'collections' => ['things']],
            ],
            'terms' => [$term('news', 'News', ['description' => 'What happened.']), $term('tips', 'Tips')],
        ]);

        $topic = fn (string $slug): int => (int) Term::query()->whereHas('translations', fn ($query) => $query->where('slug', $slug))->firstOrFail()->id;
        $image = fn (string $name): int => (int) Media::query()->create(['disk' => 'public', 'path' => 'media/'.$name.'.jpg', 'filename' => $name.'.jpg', 'mime_type' => 'image/jpeg', 'size' => 1, 'alt' => 'Alt of '.$name])->id;
        $articles = Collection::query()->where('handle', 'articles')->firstOrFail();
        $article = fn (string $slug): int => (int) Entry::query()->where('collection_id', $articles->id)
            ->whereHas('translations', fn ($query) => $query->where('slug', $slug))->firstOrFail()->id;
        $things = Collection::query()->where('handle', 'things')->firstOrFail();

        $this->everything = $this->thing($things, 'Everything', [
            'summary' => 'A thing with everything.',
            'notes' => "First line.\nSecond line.",
            'body' => '<p>The <strong>body</strong> of the thing.</p>',
            'price' => 1250,
            'featured' => true,
            'colour' => 'blue',
            'sizes' => ['s', 'l'],
            'made_on' => '2026-03-14',
            'more' => ['type' => 'url', 'url' => 'https://example.com/more', 'label' => 'More about it', 'new_tab' => true],
            'cover' => $image('cover'),
            'photos' => [$image('photo-one'), $image('photo-two')],
            'related' => [$article('first-post'), $article('second-post')],
            'see_also' => [$article('third-post')],
            'topics' => [$topic('news'), $topic('tips')],
            'details' => ['material' => 'Oak', 'weight' => 12, 'picture' => $image('detail')],
            'steps' => [
                ['label' => 'Cut', 'text' => '<p>Cut the wood.</p>', 'icon' => $image('saw')],
                ['label' => 'Sand', 'text' => '<p>Sand it down.</p>', 'icon' => null],
            ],
            'sections' => [
                ['type' => 'hero', 'key' => 'thing-hero', 'values' => ['heading' => 'A hero in a thing', 'subheading' => 'Under it.']],
                ['type' => 'cta', 'key' => 'thing-cta', 'values' => ['label' => 'Buy it', 'target' => ['type' => 'url', 'url' => 'https://example.com/buy', 'label' => 'Buy', 'new_tab' => false]]],
            ],
        ], ['topics' => [$topic('news')]]);
        $this->nothing = $this->thing($things, 'Nothing', []);
    }

    /**
     * A published thing in the main language.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, list<int>>  $terms  its own terms, by taxonomy
     */
    private function thing(Collection $things, string $title, array $data, array $terms = []): Entry
    {
        $translation = app(CreateEntry::class)->handle($things, ['title' => $title, 'slug' => str($title)->slug()->toString(), 'data' => $data, 'terms' => $terms === [] ? null : $terms]);
        app(PublishEntry::class)->handle($translation);

        return $translation->entry->refresh();
    }
}
