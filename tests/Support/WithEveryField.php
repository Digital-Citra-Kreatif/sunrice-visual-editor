<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Tests\Support;

use Sunrice\Admin\Media\Media;
use Sunrice\Cms\Actions\Entries\CreateEntry;
use Sunrice\Cms\Actions\Entries\PublishEntry;
use Sunrice\Cms\Actions\Terms\CreateTerm;
use Sunrice\Cms\Models\Blueprint;
use Sunrice\Cms\Models\Collection;
use Sunrice\Cms\Models\Entry;
use Sunrice\Cms\Models\EntryTranslation;
use Sunrice\Cms\Models\Fieldset;
use Sunrice\Cms\Models\Taxonomy;

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
        Blueprint::query()->create(['handle' => 'topic', 'title' => 'Topic', 'fields' => [
            ['handle' => 'description', 'type' => 'textarea', 'label' => 'Description'],
        ]]);
        $topics = Taxonomy::query()->create(['handle' => 'topics', 'title' => 'Topics', 'blueprint' => 'topic', 'route' => '/topics/{slug}']);
        $news = app(CreateTerm::class)->handle($topics, ['title' => 'News', 'data' => ['description' => 'What happened.']]);
        $tips = app(CreateTerm::class)->handle($topics, ['title' => 'Tips']);

        Fieldset::query()->create(['handle' => 'cta', 'title' => 'Call to action', 'fields' => [
            ['handle' => 'label', 'type' => 'text', 'label' => 'Label'],
            ['handle' => 'target', 'type' => 'link', 'label' => 'Target'],
        ]]);

        Blueprint::query()->create(['handle' => 'thing', 'title' => 'Thing', 'fields' => [
            ['handle' => 'summary', 'type' => 'text', 'label' => 'Summary'],
            ['handle' => 'notes', 'type' => 'textarea', 'label' => 'Notes'],
            ['handle' => 'body', 'type' => 'rich_text', 'label' => 'Body'],
            ['handle' => 'price', 'type' => 'number', 'label' => 'Price'],
            ['handle' => 'featured', 'type' => 'toggle', 'label' => 'Featured'],
            ['handle' => 'colour', 'type' => 'select', 'label' => 'Colour', 'config' => ['options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']]]],
            ['handle' => 'sizes', 'type' => 'select', 'label' => 'Sizes', 'config' => ['options' => [['value' => 's', 'label' => 'Small'], ['value' => 'l', 'label' => 'Large']], 'multiple' => true]],
            ['handle' => 'made_on', 'type' => 'date', 'label' => 'Made on'],
            ['handle' => 'more', 'type' => 'link', 'label' => 'More'],
            ['handle' => 'cover', 'type' => 'media', 'label' => 'Cover'],
            ['handle' => 'photos', 'type' => 'media', 'label' => 'Photos', 'config' => ['multiple' => true]],
            ['handle' => 'related', 'type' => 'entries', 'label' => 'Related articles', 'config' => ['collections' => ['articles'], 'multiple' => true]],
            ['handle' => 'see_also', 'type' => 'entries', 'label' => 'See also', 'config' => ['collections' => ['articles', 'pages'], 'multiple' => true]],
            ['handle' => 'mentor', 'type' => 'entries', 'label' => 'Mentor', 'config' => ['collections' => ['articles']]],
            ['handle' => 'topics', 'type' => 'terms', 'label' => 'Topics', 'config' => ['taxonomy' => 'topics', 'multiple' => true]],
            ['handle' => 'details', 'type' => 'group', 'label' => 'Details', 'fields' => [
                ['handle' => 'material', 'type' => 'text', 'label' => 'Material'],
                ['handle' => 'weight', 'type' => 'number', 'label' => 'Weight'],
                ['handle' => 'picture', 'type' => 'media', 'label' => 'Picture'],
            ]],
            ['handle' => 'steps', 'type' => 'repeater', 'label' => 'Steps', 'fields' => [
                ['handle' => 'label', 'type' => 'text', 'label' => 'Label'],
                ['handle' => 'text', 'type' => 'rich_text', 'label' => 'Text'],
                ['handle' => 'icon', 'type' => 'media', 'label' => 'Icon'],
            ]],
            ['handle' => 'sections', 'type' => 'flexible', 'label' => 'Sections', 'config' => ['blocks' => ['hero', 'text', 'cta']]],
        ]]);
        Blueprint::query()->create(['handle' => 'things_listing', 'title' => 'Things listing', 'fields' => [
            ['handle' => 'image', 'type' => 'media', 'label' => 'Image'],
            ['handle' => 'description', 'type' => 'rich_text', 'label' => 'Description'],
        ]]);

        $things = Collection::query()->create([
            'handle' => 'things', 'title' => 'Things', 'blueprint' => 'thing', 'listing_blueprint' => 'things_listing',
            'route' => '/things/{slug}', 'listing_route' => '/things',
        ]);

        $image = fn (string $name): Media => Media::query()->create(['disk' => 'public', 'path' => 'media/'.$name.'.jpg', 'filename' => $name.'.jpg', 'mime_type' => 'image/jpeg', 'size' => 1, 'width' => 1600, 'height' => 900, 'alt' => 'Alt of '.$name]);
        $article = fn (string $slug): int => (int) EntryTranslation::query()->where('slug', $slug)->firstOrFail()->entry_id;

        $this->everything = $this->entry($things, 'Everything', [
            'summary' => 'A thing with everything.',
            'notes' => "First line.\nSecond line.",
            'body' => '<p>The <strong>body</strong> of the thing.</p>',
            'price' => 1250,
            'featured' => true,
            'colour' => 'blue',
            'sizes' => ['s', 'l'],
            'made_on' => '2026-03-14',
            'more' => ['type' => 'url', 'url' => 'https://example.com/more', 'label' => 'More about it', 'new_tab' => true],
            'cover' => $image('cover')->id,
            'photos' => [$image('photo-one')->id, $image('photo-two')->id],
            'related' => [$article('first-post'), $article('second-post')],
            'see_also' => [$article('third-post')],
            'mentor' => $article('fourth-post'),
            'topics' => [$news->id, $tips->id],
            'details' => ['material' => 'Oak', 'weight' => 12, 'picture' => $image('detail')->id],
            'steps' => [
                ['label' => 'Cut', 'text' => '<p>Cut the wood.</p>', 'icon' => $image('saw')->id],
                ['label' => 'Sand', 'text' => '<p>Sand it down.</p>', 'icon' => null],
            ],
            'sections' => [
                ['id' => 'thing-hero', 'type' => 'hero', 'values' => ['heading' => 'A hero in a thing', 'subheading' => 'Under it.']],
                ['id' => 'thing-cta', 'type' => 'cta', 'values' => ['label' => 'Buy it', 'target' => ['type' => 'url', 'url' => 'https://example.com/buy', 'label' => 'Buy', 'new_tab' => false]]],
            ],
        ]);
        $this->everything->terms()->sync([$news->id]);
        $this->nothing = $this->entry($things, 'Nothing', []);
    }

    /** @param  array<string, mixed>  $data */
    private function entry(Collection $collection, string $title, array $data): Entry
    {
        $translation = app(CreateEntry::class)->handle($collection, ['title' => $title, 'data' => $data]);

        return app(PublishEntry::class)->handle($translation)->entry;
    }
}
