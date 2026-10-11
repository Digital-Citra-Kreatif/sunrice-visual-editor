<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Tests\Support;

use Sunrice\Frontend\RouteMatcher;
use Sunrice\Models\Asset;
use Sunrice\Models\Blueprint;
use Sunrice\Models\Collection;
use Sunrice\Models\Entry;
use Sunrice\Models\Fieldset;
use Sunrice\Models\Taxonomy;
use Sunrice\Models\Term;
use Sunrice\Support\Locales;

/**
 * Content with a field of every type Sunrice has, on top of the starter
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
        $topics = Taxonomy::create([
            'handle' => 'topics', 'title' => 'Topics', 'hierarchical' => false,
            'blueprint_id' => Blueprint::create(['handle' => 'topic', 'title' => 'Topic', 'fields' => [
                ['handle' => 'description', 'type' => 'textarea', 'label' => 'Description'],
            ]])->id,
            'settings' => ['has_archive' => true],
        ]);
        $news = $this->term($topics, 'News', ['description' => 'What happened.']);
        $tips = $this->term($topics, 'Tips');

        Fieldset::create(['handle' => 'cta', 'title' => 'Call to action', 'fields' => [
            ['handle' => 'label', 'type' => 'text', 'label' => 'Label'],
            ['handle' => 'target', 'type' => 'link', 'label' => 'Target'],
        ]]);

        $thing = Blueprint::create(['handle' => 'thing', 'title' => 'Thing', 'fields' => [
            ['handle' => 'summary', 'type' => 'text', 'label' => 'Summary'],
            ['handle' => 'notes', 'type' => 'textarea', 'label' => 'Notes'],
            ['handle' => 'body', 'type' => 'rich_text', 'label' => 'Body'],
            ['handle' => 'price', 'type' => 'number', 'label' => 'Price'],
            ['handle' => 'featured', 'type' => 'toggle', 'label' => 'Featured'],
            ['handle' => 'colour', 'type' => 'select', 'label' => 'Colour', 'config' => ['options' => ['red' => 'Red', 'blue' => 'Blue']]],
            ['handle' => 'sizes', 'type' => 'select', 'label' => 'Sizes', 'config' => ['options' => ['s' => 'Small', 'l' => 'Large'], 'multiple' => true]],
            ['handle' => 'made_on', 'type' => 'date', 'label' => 'Made on'],
            ['handle' => 'more', 'type' => 'link', 'label' => 'More'],
            ['handle' => 'cover', 'type' => 'asset', 'label' => 'Cover'],
            ['handle' => 'photos', 'type' => 'asset', 'label' => 'Photos', 'config' => ['multiple' => true]],
            ['handle' => 'related', 'type' => 'entries', 'label' => 'Related articles', 'config' => ['collections' => ['articles']]],
            ['handle' => 'see_also', 'type' => 'entries', 'label' => 'See also'],
            ['handle' => 'topics', 'type' => 'terms', 'label' => 'Topics', 'config' => ['taxonomy' => 'topics']],
            ['handle' => 'details', 'type' => 'group', 'label' => 'Details', 'config' => ['fields' => [
                ['handle' => 'material', 'type' => 'text', 'label' => 'Material'],
                ['handle' => 'weight', 'type' => 'number', 'label' => 'Weight'],
                ['handle' => 'picture', 'type' => 'asset', 'label' => 'Picture'],
            ]]],
            ['handle' => 'steps', 'type' => 'repeater', 'label' => 'Steps', 'config' => ['fields' => [
                ['handle' => 'label', 'type' => 'text', 'label' => 'Label'],
                ['handle' => 'text', 'type' => 'rich_text', 'label' => 'Text'],
                ['handle' => 'icon', 'type' => 'asset', 'label' => 'Icon'],
            ]]],
            ['handle' => 'sections', 'type' => 'flexible', 'label' => 'Sections', 'config' => ['fieldsets' => ['hero', 'text', 'cta']]],
        ]]);
        $listing = Blueprint::create(['handle' => 'things_listing', 'title' => 'Things listing', 'fields' => [
            ['handle' => 'image', 'type' => 'asset', 'label' => 'Image'],
            ['handle' => 'description', 'type' => 'rich_text', 'label' => 'Description'],
        ]]);

        $things = Collection::create([
            'handle' => 'things', 'title' => 'Things', 'blueprint_id' => $thing->id,
            'settings' => ['route' => '/things/{slug}', 'archive_route' => '/things', 'has_single' => true, 'has_archive' => true, 'translatable' => false, 'sluggable' => true, 'archive_blueprint_id' => $listing->id],
        ]);

        $image = fn (string $name): Asset => Asset::factory()->create(['filename' => $name.'.jpg', 'path' => 'sunrice/'.$name.'.jpg', 'alt' => 'Alt of '.$name]);
        $articles = Collection::query()->where('handle', 'articles')->firstOrFail();
        $article = fn (string $slug): int => (int) Entry::query()->where('collection_id', $articles->id)
            ->whereHas('translations', fn ($query) => $query->where('slug', $slug))->firstOrFail()->id;

        $this->everything = $this->entry($things, $thing, 'Everything', [
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
        $this->nothing = $this->entry($things, $thing, 'Nothing', []);

        RouteMatcher::flush();
    }

    /** @param  array<string, mixed>  $data */
    private function term(Taxonomy $taxonomy, string $name, array $data = []): Term
    {
        $term = Term::create(['taxonomy_id' => $taxonomy->id, 'sort_order' => 0]);
        $term->translations()->create(['taxonomy_id' => $taxonomy->id, 'locale' => Locales::main(), 'name' => $name, 'slug' => str($name)->slug()->toString(), 'data' => $data]);

        return $term->refresh();
    }

    /** @param  array<string, mixed>  $data */
    private function entry(Collection $collection, Blueprint $blueprint, string $title, array $data): Entry
    {
        $entry = Entry::create(['collection_id' => $collection->id, 'blueprint_id' => $blueprint->id, 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create([
            'collection_id' => $collection->id,
            'locale' => Locales::main(),
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'data' => $data,
            'is_ready' => true,
            'content_published_at' => now(),
        ]);

        return $entry->refresh();
    }
}
