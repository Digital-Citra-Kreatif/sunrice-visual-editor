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
    // --- A page of entries: the entry of the collections that use the template.
    'the shared entry template, as a whole' => ['show.blade.php', null, [
        '$entry' => 'entry(pages|things)',
        '$collection' => 'collection',
    ]],
    'a block of its loop over the sections' => ['show.blade.php', '@includeFirst', [
        '$block' => 'block(hero|text|gallery)',
        '$image' => 'value',                         // an `image` field neither collection has
        '$entry' => 'entry(pages|things)',
        '$collection' => 'collection',
    ]],
    'the template of one collection' => ['articles/show.blade.php', '<h1>', [
        '$entry' => 'entry(articles)',
        '$collection' => 'collection(articles)',
    ]],
    'inside a condition that sets what it asks about' => ['articles/show.blade.php', '{{ $excerpt }}', [
        '$excerpt' => 'text',
        '$entry' => 'entry(articles)',
        '$collection' => 'collection(articles)',
    ]],
    'a term of the entry, in a loop' => ['articles/show.blade.php', '{{ $term->name }}', [
        '$term' => 'term',
        '$image' => 'value',
        '$entry' => 'entry(articles)',
        '$collection' => 'collection(articles)',
    ]],
    'a loop over a field the collection does not have' => ['articles/show.blade.php', "['entry' => \$item]", [
        '$item' => 'value',
        '$related' => 'value',
        '$entry' => 'entry(articles)',
        '$collection' => 'collection(articles)',
    ]],
    'what a component found, inside its tag' => ['articles/show.blade.php', '{{-- One extra is fetched', [
        '$component' => 'group{entries}',
        '$entry' => 'entry(articles)',
        '$collection' => 'collection(articles)',
    ]],
    // --- A listing page.
    'a listing, in its loop' => ['index.blade.php', "@include('sunrice.partials.card'", [
        '$entry' => 'entry(articles|things)',
        '$image' => 'value',
        '$listing' => 'group{title, intro}',
        '$entries' => 'list<entry(articles|things)>',
        '$collection' => 'collection',
    ]],
    'a listing, above its loop' => ['index.blade.php', '<h1>', [
        '$image' => 'value',
        '$listing' => 'group{title, intro}',
        '$entries' => 'list<entry(articles|things)>',
        '$collection' => 'collection',
    ]],
    // --- A term's page.
    'a term page, in the loop over the terms below it' => ['taxonomies/show.blade.php', '{{ $child->name }}', [
        '$child' => 'term(topics)',
        '$term' => 'term(topics)',
        '$taxonomy' => 'taxonomy',
        '$entries' => 'list<entry>',
        '$collection' => 'collection',
    ]],
    // --- Blocks: the fieldset the file is named after.
    'a block' => ['blocks/hero.blade.php', '<h1>', [
        '$image' => 'value',                         // the hero of the demo site has no image
        '$block' => 'block(hero)',
    ]],
    'a block, in a loop over one of its fields' => ['blocks/gallery.blade.php', '<img', [
        '$image' => 'asset',
        '$block' => 'block(gallery)',
    ]],
    'the template for blocks without one of their own' => ['blocks/default.blade.php', null, [
        '$block' => 'block',
    ]],
    'a block named after no fieldset' => ['blocks/form.blade.php', null, [
        '$block' => 'block',
    ]],
    // --- Partials: what the templates that include them pass on.
    'a partial given an entry by three templates' => ['partials/card.blade.php', '<h2>', [
        '$image' => 'value',
        '$entry' => 'entry',                          // of two collections on the listing, of any on a term page
    ]],
    'a partial that includes itself' => ['partials/menu.blade.php', '{{ $item->label }}', [
        '$item' => 'menu_item',
        '$items' => 'list<menu_item>',
    ]],
    'a partial that sets what it uses in a piece of PHP' => ['partials/footer.blade.php', '<footer', [
        '$footerMenu' => 'list<menu_item>',
        '$footer' => 'global(footer)',
    ]],
    'the same, in a loop over a field the global set does not have' => ['partials/footer.blade.php', "{{ \$link['label']", [
        '$link' => 'value',
        '$footerMenu' => 'list<menu_item>',
        '$footer' => 'global(footer)',
    ]],
    'a partial given nothing that is known' => ['partials/pagination.blade.php', null, [
        '$paginator' => 'value',
        '$elements' => 'value',
    ]],
    'the header' => ['partials/header.blade.php', null, []],
    'the language switcher' => ['partials/language-switcher.blade.php', null, []],
    'the search form' => ['partials/search-form.blade.php', null, []],
    'the filter form' => ['partials/entry-filter.blade.php', null, [
        '$filter' => 'value',
    ]],
    // --- The rest.
    'the layout' => ['layouts/app.blade.php', null, []],
    'the search page' => ['search.blade.php', null, [
        '$query' => 'text',
        '$results' => 'list<entry>',
    ]],
]);

it('covers every starter template', function () {
    $covered = ['show', 'index', 'articles/show', 'taxonomies/show', 'search', 'layouts/app',
        'blocks/hero', 'blocks/gallery', 'blocks/default', 'blocks/form', 'blocks/text',
        'partials/card', 'partials/menu', 'partials/footer', 'partials/pagination', 'partials/header', 'partials/language-switcher', 'partials/search-form', 'partials/entry-filter'];
    $site = array_filter(app(TemplateRepository::class)->files(), fn ($file) => $file->origin === 'site');

    expect(array_values(array_diff(array_map(fn ($file) => substr($file->path, 0, -10), $site), $covered)))->toBe([])
        ->and(($this->at)('blocks/text.blade.php', null))->toBe(['$block' => 'block(text)']);
});

it('has the page, the language, global sets and menus everywhere', function () {
    $everywhere = array_values(array_filter(($this->variables)('partials/header.blade.php', null), fn (Variable $variable) => $variable->from === Variable::SITE));

    expect(array_map(fn (Variable $variable) => [$variable->code, $variable->shape->spell(), $variable->title], $everywhere))->toBe([
        ['$sunricePage', 'page', 'The page being shown'],
        ['$locale', 'text', 'The language of the page'],
        ["sunrice_global('footer')", 'global(footer)', 'Footer'],
        ["sunrice_global('site')", 'global(site)', 'Site'],
        ["sunrice_menu('main')", 'list<menu_item>', 'Main menu'],
    ])->and($everywhere[2]->name())->toBeNull()
        ->and($everywhere[0]->name())->toBe('sunricePage');
});

it('says where each comes from, and whether it is sure to be there', function () {
    $by = fn (string $path, ?string $marker) => collect(($this->variables)($path, $marker))->keyBy(fn (Variable $variable) => $variable->code);

    $article = $by('articles/show.blade.php', '{{ $term->name }}');
    expect($article['$term']->from)->toBe(Variable::LOOP)
        ->and($article['$term']->title)->toBe('Each of $entry->terms')
        ->and($article['$term']->sure)->toBeTrue()
        ->and($article['$image']->from)->toBe(Variable::SET)
        ->and($article['$image']->sure)->toBeFalse()                 // set, and maybe to nothing
        ->and($article['$entry']->from)->toBe(Variable::PAGE)
        ->and($article['$entry']->title)->toBe('The entry the page shows');

    $excerpt = $by('articles/show.blade.php', '{{ $excerpt }}')['$excerpt'];
    expect($excerpt->from)->toBe(Variable::SET)
        ->and($excerpt->sure)->toBeTrue()                            // inside the @if that asks about it
        ->and($excerpt->title)->toBe("Set by the condition: \$excerpt = \$entry->get('excerpt')");

    expect($by('taxonomies/show.blade.php', null)['$collection']->sure)->toBeFalse()   // a term's page across all collections has none
        ->and($by('partials/card.blade.php', null)['$entry']->from)->toBe(Variable::INCLUDED)
        ->and($by('partials/card.blade.php', null)['$entry']->title)->toBe('Passed on by the templates that include this one')
        ->and($by('partials/pagination.blade.php', null)['$paginator']->title)->toBe('Has to be given by whatever includes this template')
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
    $write('things/show.blade.php', <<<'BLADE'
        @extends('sunrice.layouts.app')
        @section('content')
            @php
                $cover = $entry->get('cover');
                $steps = $entry->get('steps') ?? [];
            @endphp
            <h1>{{ $entry->title }}</h1>
            @foreach ($steps as $number => $step)
                <section class="step">
                    @include('sunrice.partials.step', ['step' => $step, 'last' => $loop->last])
                </section>
            @endforeach
            @foreach ($entry->get('sections') ?? [] as $block)
                @if ($block->type === 'cta' && ($target = $block->target))
                    <a class="cta" href="{{ $target['url'] }}">{{ $block->label }}</a>
                @endif
            @endforeach
            <x-sunrice::entries collection="articles" :limit="3">
                @foreach ($component->entries as $article)
                    <p class="latest">{{ $article->title }}</p>
                @endforeach
            </x-sunrice::entries>
            <x-sunrice::terms taxonomy="topics">
                <ul class="topics">@each('sunrice.partials.topic', $component->terms, 'topic')</ul>
            </x-sunrice::terms>
        @endsection
        BLADE);
    $write('partials/step.blade.php', '<h2>{{ $step[\'label\'] }}</h2>@if ($last) <hr> @endif <p class="by">{{ $entry->author?->name }}</p>');
    $write('partials/topic.blade.php', '<li>{{ $topic->name }}</li>');
    $scope = new Scope(new Catalog, app(TemplateRepository::class));
    $this->scope = $scope;

    expect(($this->at)('things/show.blade.php', '<section class="step">'))->toBe([
        '$step' => 'row{label, text, icon}',
        '$number' => 'value',
        '$steps' => 'list<row{label, text, icon}>',
        '$cover' => 'asset',
        '$entry' => 'entry(things)',
        '$collection' => 'collection(things)',
    ])->and(($this->at)('things/show.blade.php', '<a class="cta"'))->toBe([
        '$target' => 'link',
        '$block' => 'block(hero|text|cta)',
        '$steps' => 'list<row{label, text, icon}>',
        '$cover' => 'asset',
        '$entry' => 'entry(things)',
        '$collection' => 'collection(things)',
    ])->and(($this->at)('things/show.blade.php', '<p class="latest">'))->toBe([
        '$article' => 'entry(articles)',
        '$component' => 'group{entries}',
        '$steps' => 'list<row{label, text, icon}>',
        '$cover' => 'asset',
        '$entry' => 'entry(things)',
        '$collection' => 'collection(things)',
    ])->and(($this->at)('things/show.blade.php', '<ul class="topics">')['$component'])->toBe('group{terms}');

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
