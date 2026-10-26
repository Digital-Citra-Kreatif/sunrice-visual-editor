<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Fields\Catalog;
use Sunrice\VisualEditor\Fields\Scope;
use Sunrice\VisualEditor\Fields\Variable;
use Sunrice\VisualEditor\Templates\TemplateRepository;
use Sunrice\VisualEditor\Tests\Support\WithEveryField;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class, WithEveryField::class);

beforeEach(function () {
    $this->setUpStarterSite();
    $this->setUpEveryField();
    $this->scope = new Scope(new Catalog, app(TemplateRepository::class));

    /**
     * What can be read at the first node whose own text has $marker in it.
     *
     * @return list<Variable>
     */
    $this->variables = function (string $path, ?string $marker, bool $inside = false): array {
        $file = app(TemplateRepository::class)->find($path);
        $template = Template::open((string) file_get_contents($file->file));
        $node = null;
        foreach ($marker === null ? [] : $template->root->walk() as $each) {
            if (str_contains($each->open, $marker)) {
                $node = $each;
                break;
            }
        }
        if ($marker !== null && ! $node instanceof Node) {
            throw new RuntimeException("Nothing in {$path} has \"{$marker}\" in it.");
        }

        return $this->scope->at($file, $template, $node, $inside);
    };
    /** The same without what every template has, as "code" => "what it is". */
    $this->at = function (string $path, ?string $marker, bool $inside = false): array {
        $own = array_filter(($this->variables)($path, $marker, $inside), fn (Variable $variable) => $variable->from !== Variable::SITE);

        return array_combine(array_map(fn (Variable $variable) => $variable->code, $own), array_map(fn (Variable $variable) => $variable->shape->spell(), $own));
    };
});

it('knows what a template has at a place in it', function (string $path, ?string $marker, array $expected) {
    expect(($this->at)($path, $marker))->toBe($expected);
})->with([
    // --- A page of entries: the entry and the fields of the collections that use the template.
    'the shared entry template, as a whole' => ['collections/show.blade.php', null, [
        '$entry' => 'entry(articles|pages|things)',
        '$values' => 'values:collection(articles|pages|things)',
        '$collection' => 'collection',
        '$title' => 'text',
        '$page' => 'page',
        '$richText' => 'list<text>',
        '$code' => 'value',
        '$preview' => 'bool',
        '$draft' => 'bool',
        '$fallback' => 'bool',
        '$contentLocale' => 'text',
    ]],
    'a term of the entry, in a loop, under a condition that sets what it asks about' => ['collections/show.blade.php', '{{ $term->title() }}', [
        '$url' => 'text',
        '$term' => 'term',
        '$entry' => 'entry(articles|pages|things)',
        '$values' => 'values:collection(articles|pages|things)',
        '$collection' => 'collection',
        '$title' => 'text',
        '$page' => 'page',
        '$richText' => 'list<text>',
        '$code' => 'value',
        '$preview' => 'bool',
        '$draft' => 'bool',
        '$fallback' => 'bool',
        '$contentLocale' => 'text',
    ]],
    // --- A listing page.
    'a listing, in its loop' => ['collections/index.blade.php', "@include('cms.partials.card'", [
        '$item' => 'entry(articles|things)',
        '$entries' => 'list<entry(articles|things)>',
        '$values' => 'values:listing(articles|things)',
        '$collection' => 'collection',
        '$url' => 'text',
        '$title' => 'text',
        '$page' => 'page',
        '$richText' => 'list<text>',
        '$code' => 'value',
    ]],
    // --- A term's page, and a taxonomy's index page.
    'a term page' => ['taxonomies/show.blade.php', '<h1>', [
        '$term' => 'term_translation(topics)',
        '$values' => 'values:taxonomy(topics)',
        '$taxonomy' => 'taxonomy',
        '$entries' => 'list<entry>',
        '$collection' => 'collection',
        '$title' => 'text',
        '$page' => 'page',
        '$richText' => 'list<text>',
        '$code' => 'value',
        '$fallback' => 'bool',
        '$contentLocale' => 'text',
    ]],
    'an index page, in the loop over its terms' => ['taxonomies/index.blade.php', '<span class="muted">', [
        '$termUrl' => 'text',
        '$term' => 'term(topics)',
        '$terms' => 'list<term(topics)>',
        '$values' => 'values:index(topics)',
        '$taxonomy' => 'taxonomy',
        '$collection' => 'collection',
        '$url' => 'text',
        '$title' => 'text',
        '$page' => 'page',
        '$richText' => 'list<text>',
        '$code' => 'value',
    ]],
    // --- Blocks: the fieldset the file is named after.
    'a block' => ['blocks/hero.blade.php', '<h1>', [
        '$block' => 'block(hero)',
    ]],
    'a block, after what it sets' => ['blocks/hero.blade.php', '<a class', [
        '$link' => 'link',
        '$block' => 'block(hero)',
    ]],
    'a block, in a loop over one of its fields' => ['blocks/gallery.blade.php', '<img', [
        '$image' => 'asset',
        '$block' => 'block(gallery)',
    ]],
    // --- Partials: what the templates that include them pass on.
    'a partial given an entry by three templates' => ['partials/card.blade.php', '{{ $excerpt }}', [
        '$excerpt' => 'text',
        '$image' => 'value',                         // an `image` field no collection has
        '$fields' => 'values:collection',
        '$url' => 'text',
        '$item' => 'entry',                          // of two collections on the listing, of any on a term page and the search
    ]],
    'a partial given the fields of entries, terms, pages and blocks' => ['partials/fields.blade.php', null, [
        '$values' => 'values:mixed(listing:articles|listing:things|collection:articles|collection:pages|collection:things|fieldset:cta|fieldset:gallery|fieldset:hero|fieldset:text|index:topics|taxonomy:topics)',
        '$richText' => 'list<text>',
    ]],
    'a partial given blocks by name' => ['partials/blocks.blade.php', null, [
        '$blocks' => 'list<block>',
    ]],
    'a partial that includes itself' => ['partials/menu.blade.php', '<a href', [
        '$item' => 'menu_item',
        '$items' => 'list<menu_item>',
    ]],
    'a partial given a list to page through' => ['partials/pagination.blade.php', null, [
        '$paginator' => 'list<entry>',
    ]],
    // --- The rest.
    'the layout, in a condition that sets what it asks about' => ['layout.blade.php', '{{ $text }}', [
        '$text' => 'text',
        '$home' => 'text',
        '$page' => 'page',
    ]],
    'the layout, at a menu' => ['layout.blade.php', "@include('cms.partials.menu', ['items' => \$menu])", [
        '$menu' => 'list<menu_item>',
        '$home' => 'text',
        '$page' => 'page',
    ]],
    'the search page, in its search box' => ['search.blade.php', '<label', [
        '$component' => 'group{results, query}',
        '$query' => 'text',
        '$results' => 'list<entry>',
        '$title' => 'text',
        '$page' => 'page',
    ]],
]);

it('covers every starter template', function () {
    $covered = ['collections/show', 'collections/index', 'taxonomies/show', 'taxonomies/index', 'search', 'layout', 'comment-unsubscribe',
        'blocks/hero', 'blocks/gallery', 'blocks/text',
        'partials/card', 'partials/fields', 'partials/blocks', 'partials/block', 'partials/menu', 'partials/pagination'];
    $site = array_filter(app(TemplateRepository::class)->files(), fn ($file) => $file->origin === 'site');

    expect(array_values(array_diff(array_map(fn ($file) => substr($file->path, 0, -10), $site), $covered)))->toBe([])
        ->and(($this->at)('blocks/text.blade.php', null))->toBe(['$block' => 'block(text)'])
        ->and(($this->at)('partials/block.blade.php', '@include'))->toBe(['$blockFields' => 'value', '$block' => 'block'])
        // A page of the CMS's own, with what its controller gives it: nothing this knows of.
        ->and(array_keys(($this->at)('comment-unsubscribe.blade.php', null)))->toContain('$action');
});

it('has the page, the language, global sets and menus everywhere', function () {
    $everywhere = array_values(array_filter(($this->variables)('layout.blade.php', null), fn (Variable $variable) => $variable->from === Variable::SITE));

    expect(array_map(fn (Variable $variable) => [$variable->code, $variable->shape->spell(), $variable->title], $everywhere))->toBe([
        ['cms_page()', 'page', 'The page being shown'],
        ['$locale', 'text', 'The language of the page'],
        ["cms_global('footer')", 'global(footer)', 'Footer'],
        ["cms_global('site')", 'global(site)', 'Site'],
        ["cms_menu('main')", 'list<menu_item>', 'Main menu'],
    ])->and($everywhere[2]->name())->toBeNull()
        ->and($everywhere[0]->name())->toBeNull()
        // The app's own pages have no CMS page.
        ->and($everywhere[0]->sure)->toBeFalse();
});

it('says where each comes from, and whether it is sure to be there', function () {
    $by = fn (string $path, ?string $marker) => collect(($this->variables)($path, $marker))->keyBy(fn (Variable $variable) => $variable->code);

    $entry = $by('collections/show.blade.php', '{{ $term->title() }}');
    expect($entry['$term']->from)->toBe(Variable::LOOP)
        ->and($entry['$term']->title)->toBe('Each of $entry->terms')
        ->and($entry['$term']->sure)->toBeTrue()
        ->and($entry['$url']->from)->toBe(Variable::SET)
        ->and($entry['$url']->sure)->toBeTrue()                       // inside the @if that asks about it
        ->and($entry['$url']->title)->toBe('Set by the condition: $url = $term->url()')
        ->and($entry['$entry']->from)->toBe(Variable::PAGE)
        ->and($entry['$entry']->title)->toBe('The entry the page shows')
        ->and($entry['$values']->title)->toBe('The fields of the entry');

    $card = $by('partials/card.blade.php', '{{ $excerpt }}');
    expect($card['$image']->from)->toBe(Variable::SET)
        ->and($card['$image']->sure)->toBeFalse();                    // set, and maybe to nothing

    expect($by('taxonomies/show.blade.php', null)['$collection']->sure)->toBeFalse()   // a term's page across all collections has none
        ->and($by('partials/card.blade.php', null)['$item']->from)->toBe(Variable::INCLUDED)
        ->and($by('partials/card.blade.php', null)['$item']->title)->toBe('Passed on by the templates that include this one')
        ->and($by('blocks/hero.blade.php', null)['$block']->title)->toBe('The Hero block being drawn');
});

it('counts a loop\'s item in for what goes inside the loop, not for what takes its place', function () {
    // The loop itself: its item is not there where the loop stands.
    expect(array_keys(($this->at)('blocks/gallery.blade.php', '@foreach')))->toBe(['$block'])
        ->and(array_keys(($this->at)('blocks/gallery.blade.php', '@foreach', inside: true)))->toBe(['$image', '$block']);
});

it('follows a site\'s own templates', function () {
    $write = function (string $path, string $blade): void {
        @mkdir(dirname($this->templates.'/'.$path), 0777, true);
        file_put_contents($this->templates.'/'.$path, $blade);
    };
    $write('collections/things/show.blade.php', <<<'BLADE'
        @extends('cms.layout')
        @section('content')
            @php
                $cover = $values['cover'] ?? null;
                $steps = $values['steps'] ?? [];
            @endphp
            <h1>{{ $title }}</h1>
            @foreach ($steps as $number => $step)
                <section class="step">
                    @include('cms.partials.step', ['step' => $step, 'last' => $loop->last])
                </section>
            @endforeach
            @foreach ($values['sections'] ?? [] as $block)
                @if ($block->type === 'cta' && ($target = $block->target))
                    <a class="cta" href="{{ $target['url'] }}">{{ $block['label'] }}</a>
                @endif
            @endforeach
            @foreach ($values['related'] ?? [] as $article)
                <p class="related">{{ $article->values()['excerpt'] ?? '' }}</p>
            @endforeach
            <x-sunrice-cms::entries collection="articles" :limit="3">
                @foreach ($component->entries as $latest)
                    <p class="latest">{{ $latest->title }}</p>
                @endforeach
            </x-sunrice-cms::entries>
            <x-sunrice-cms::terms taxonomy="topics">
                <ul class="topics">@each('cms.partials.topic', $component->terms, 'topic')</ul>
            </x-sunrice-cms::terms>
        @endsection
        BLADE);
    $write('partials/step.blade.php', '<h2>{{ $step[\'label\'] }}</h2>@if ($last) <hr> @endif <p class="by">{{ $entry->title }}</p>');
    $write('partials/topic.blade.php', '<li>{{ $topic->title() }}</li>');
    $this->scope = new Scope(new Catalog, app(TemplateRepository::class));
    $page = [
        '$entry' => 'entry(things)',
        '$values' => 'values:collection(things)',
        '$collection' => 'collection(things)',
        '$title' => 'text',
        '$page' => 'page',
        '$richText' => 'list<text>',
        '$code' => 'value',
        '$preview' => 'bool',
        '$draft' => 'bool',
        '$fallback' => 'bool',
        '$contentLocale' => 'text',
    ];

    expect(($this->at)('collections/things/show.blade.php', '<section class="step">'))->toBe([
        '$step' => 'row{label, text, icon}',
        '$number' => 'value',
        '$steps' => 'list<row{label, text, icon}>',
        '$cover' => 'asset',
        ...$page,
    ])->and(($this->at)('collections/things/show.blade.php', '<a class="cta"'))->toBe([
        '$target' => 'link',
        '$block' => 'block(hero|text|cta)',
        '$steps' => 'list<row{label, text, icon}>',
        '$cover' => 'asset',
        ...$page,
    ])->and(($this->at)('collections/things/show.blade.php', '<p class="related">'))->toMatchArray([
        '$article' => 'entry(articles)',
    ])->and(($this->at)('collections/things/show.blade.php', '<p class="latest">'))->toBe([
        '$latest' => 'entry(articles)',
        '$component' => 'group{entries}',
        '$steps' => 'list<row{label, text, icon}>',
        '$cover' => 'asset',
        ...$page,
    ])->and(($this->at)('collections/things/show.blade.php', '<ul class="topics">')['$component'])->toBe('group{terms}');

    // A partial has what is named for it, and the rest of what the including template has at that place.
    expect(($this->at)('partials/step.blade.php', null))->toBe([
        '$step' => 'row{label, text, icon}',
        '$last' => 'value',
        '$entry' => 'entry(things)',
    ])->and(($this->at)('partials/topic.blade.php', null))->toBe([
        '$topic' => 'term(topics)',
    ]);
});

it('leaves a variable it cannot follow a plain value', function () {
    file_put_contents($this->templates.'/partials/odd.blade.php', <<<'BLADE'
        @php($made = make_something($entry))
        @foreach ($made->things() as [$a, $b])
            <i>{{ $a }}</i>
        @endforeach
        @foreach ($whatever as $one)
            <b>{{ $one }}</b>
        @endforeach
        BLADE);
    $this->scope = new Scope(new Catalog, app(TemplateRepository::class));

    expect(($this->at)('partials/odd.blade.php', '<b>'))->toBe([
        '$one' => 'value',
        '$made' => 'value',
        '$entry' => 'entry',          // by its name: nothing includes this partial
        '$whatever' => 'value',
    ])->and(($this->at)('partials/odd.blade.php', '<i>'))->toBe([
        '$made' => 'value',           // `as [$a, $b]` names no one item
        '$entry' => 'entry',
        '$whatever' => 'value',
    ]);
});
