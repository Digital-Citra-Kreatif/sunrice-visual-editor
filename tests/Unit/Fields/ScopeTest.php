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
    // --- An entry's page: the entry of the collections that use the template, and its fields as $values.
    'the entry template, as a whole' => ['entry.blade.php', null, [
        '$entry' => 'entry(articles|pages|things)',
        '$title' => 'text',
        '$values' => 'values(collection:articles|pages|things)',
        '$collection' => 'collection',
        '$preview' => 'bool',
        '$fallback' => 'bool',
        '$richText' => 'list<text>',
    ]],
    // --- A listing page: its own fields, and its entries.
    'a listing, in its loop' => ['listing.blade.php', "@include('cms.partials.card'", [
        '$item' => 'entry(articles|things)',
        '$entries' => 'list<entry(articles|things)>',
        '$title' => 'text',
        '$values' => 'values(listing:articles|things)',
        '$collection' => 'collection',
        '$richText' => 'list<text>',
    ]],
    // --- A term's page.
    'a term page, above its loop' => ['term.blade.php', '<h1>', [
        '$term' => 'term_page(topics)',
        '$taxonomy' => 'taxonomy',
        '$title' => 'text',
        '$values' => 'values(taxonomy:topics)',
        '$entries' => 'list<entry>',
        '$fallback' => 'bool',
        '$richText' => 'list<text>',
    ]],
    // --- Blocks: the fieldset the file is named after.
    'a block' => ['blocks/hero.blade.php', '<h1', [
        '$block' => 'block(hero)',
    ]],
    // --- Partials: what the templates that include them pass on.
    'a partial given an entry by three templates' => ['partials/card.blade.php', '<h2', [
        '$image' => 'value',                          // an `image` field none of the collections has
        '$fields' => 'values(collection)',
        '$url' => 'text',
        '$item' => 'entry',                           // of two collections on the listing, of any on a term page
    ]],
    'a partial that includes itself' => ['partials/menu.blade.php', '<a href', [
        '$item' => 'menu_item',
        '$items' => 'list<menu_item>',
    ]],
    'a partial given a paginator' => ['partials/pagination.blade.php', null, [
        '$paginator' => 'list<entry>',
    ]],
    'the language switcher' => ['partials/language-switcher.blade.php', null, []],
    // --- The rest.
    'the layout' => ['layout.blade.php', '@yield', [
        '$home' => 'text',
        '$page' => 'page',
    ]],
    'the search page' => ['search.blade.php', '<input', [
        '$query' => 'text',
        '$results' => 'list<entry>',
        '$title' => 'text',
    ]],
]);

it('has the page, the language, global sets and menus everywhere', function () {
    $everywhere = array_values(array_filter(($this->variables)('partials/menu.blade.php', null), fn (Variable $variable) => $variable->from === Variable::SITE));

    expect(array_map(fn (Variable $variable) => [$variable->code, $variable->shape->spell(), $variable->title], $everywhere))->toBe([
        ['cms_page()', 'page', 'The page being shown'],
        ['$locale', 'text', 'The language of the page'],
        ["cms_global('footer')", 'global(footer)', 'Footer'],
        ["cms_menu('footer')", 'list<menu_item>', 'Footer menu'],
        ["cms_menu('main')", 'list<menu_item>', 'Main menu'],
    ])->and($everywhere[2]->name())->toBeNull()
        ->and($everywhere[0]->sure)->toBeFalse()   // null on the app's own pages
        ->and($everywhere[1]->name())->toBe('locale');
});

it('says where each comes from, and whether it is sure to be there', function () {
    $by = fn (string $path, ?string $marker) => collect(($this->variables)($path, $marker))->keyBy(fn (Variable $variable) => $variable->code);

    $card = $by('partials/card.blade.php', '<h2');
    expect($card['$fields']->from)->toBe(Variable::SET)
        ->and($card['$fields']->title)->toBe('Set above: $fields = $item->values()')
        ->and($card['$image']->sure)->toBeFalse()                 // set, and maybe to nothing
        ->and($card['$item']->from)->toBe(Variable::INCLUDED)
        ->and($card['$item']->title)->toBe('Passed on by the templates that include this one');

    $menu = $by('partials/menu.blade.php', '<a href');
    expect($menu['$item']->from)->toBe(Variable::LOOP)
        ->and($menu['$item']->title)->toBe('Each of $items');

    expect($by('entry.blade.php', null)['$entry']->from)->toBe(Variable::PAGE)
        ->and($by('entry.blade.php', null)['$entry']->title)->toBe('The entry the page shows')
        ->and($by('blocks/hero.blade.php', null)['$block']->title)->toBe('The Hero block being drawn');
});

it('counts a loop\'s item in for what goes inside the loop, not for what takes its place', function () {
    // The loop itself: its item is not there where the loop stands.
    expect(array_keys(($this->at)('partials/menu.blade.php', '@foreach')))->toBe(['$items'])
        ->and(array_keys(($this->at)('partials/menu.blade.php', '@foreach', inside: true)))->toBe(['$item', '$items']);
});

it('follows a site\'s own templates', function () {
    $write = function (string $path, string $blade): void {
        @mkdir(dirname($this->templates.'/'.$path), 0777, true);
        file_put_contents($this->templates.'/'.$path, $blade);
    };
    $write('things.blade.php', <<<'BLADE'
        @extends('cms.layout')
        @section('content')
            @php
                $cover = $values['cover'] ?? null;
                $steps = $values['steps'] ?? [];
            @endphp
            <h1>{{ $entry->title }}</h1>
            @foreach ($steps as $number => $step)
                <section class="step">
                    @include('cms.partials.step', ['step' => $step, 'last' => $loop->last])
                </section>
            @endforeach
            @foreach ($values['sections'] ?? [] as $block)
                @if ($block->type === 'cta' && ($target = $block->target))
                    <a class="cta" href="{{ $target['url'] }}">{{ $block->label }}</a>
                @endif
            @endforeach
            <x-sunrice-cms::entries collection="articles" :limit="3">
                @foreach ($component->entries as $article)
                    <p class="latest">{{ $article->title }}</p>
                @endforeach
            </x-sunrice-cms::entries>
            <x-sunrice-cms::terms taxonomy="topics">
                <ul class="topics">@each('cms.partials.topic', $component->terms, 'topic')</ul>
            </x-sunrice-cms::terms>
        @endsection
        BLADE);
    $write('partials/step.blade.php', '<h2>{{ $step[\'label\'] }}</h2>@if ($last) <hr> @endif <p class="by">{{ $entry->title }}</p>');
    $write('partials/topic.blade.php', '<li>{{ $topic->title() }}</li>');
    $scope = new Scope(new Catalog, app(TemplateRepository::class));
    $this->scope = $scope;
    // What the place and the files bring, beside the entry and its collection: what else an entry's page has is checked above.
    $at = $this->at;
    $this->at = fn (string $path, ?string $marker) => array_diff_key($at($path, $marker), array_flip(['$title', '$values', '$preview', '$fallback', '$richText']));

    expect(($this->at)('things.blade.php', '<section class="step">'))->toBe([
        '$step' => 'row{label, text, icon}',
        '$number' => 'value',
        '$steps' => 'list<row{label, text, icon}>',
        '$cover' => 'asset',
        '$entry' => 'entry(things)',
        '$collection' => 'collection(things)',
    ])->and(($this->at)('things.blade.php', '<a class="cta"'))->toBe([
        '$target' => 'link',
        '$block' => 'block(hero|text|cta)',
        '$steps' => 'list<row{label, text, icon}>',
        '$cover' => 'asset',
        '$entry' => 'entry(things)',
        '$collection' => 'collection(things)',
    ])->and(($this->at)('things.blade.php', '<p class="latest">'))->toBe([
        '$article' => 'entry(articles)',
        '$component' => 'group{entries}',
        '$steps' => 'list<row{label, text, icon}>',
        '$cover' => 'asset',
        '$entry' => 'entry(things)',
        '$collection' => 'collection(things)',
    ])->and(($this->at)('things.blade.php', '<ul class="topics">')['$component'])->toBe('group{terms}');

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
