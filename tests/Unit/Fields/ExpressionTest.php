<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Fields\Catalog;
use Sunrice\VisualEditor\Fields\Expression;
use Sunrice\VisualEditor\Fields\Shape;
use Sunrice\VisualEditor\Tests\Support\WithEveryField;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class, WithEveryField::class);

beforeEach(function () {
    $this->setUpStarterSite();
    $this->setUpEveryField();
    $this->catalog = new Catalog;
    $this->known = [
        'entry' => Shape::entry(['things']),
        'any' => Shape::entry(),
        'block' => Shape::block(['hero']),
        'term' => Shape::term(['topics']),
        'collection' => Shape::collection('things'),
        'entries' => Shape::listOf(Shape::entry(['articles'])),
        'sunricePage' => Shape::of(Shape::PAGE),
        'locale' => Shape::of(Shape::TEXT),
    ];
    $this->is = fn (string $code): ?string => Expression::read($code, $this->known, $this->catalog)[0]?->spell();
});

it('reads what a value is from the way it is read', function (string $code, ?string $shape) {
    expect(($this->is)($code))->toBe($shape);
})->with([
    // Variables
    ['$entry', 'entry(things)'],
    ['$nobody', null],
    // An entry's fields and what every entry has
    ["\$entry->get('summary')", 'text'],
    ['$entry->get("summary")', 'text'],
    ["\$entry->get('body')", 'html'],
    ["\$entry->get('price')", 'number'],
    ["\$entry->get('featured')", 'bool'],
    ["\$entry->get('made_on')", 'date'],
    ["\$entry->get('more')", 'link'],
    ["\$entry->get('cover')", 'asset'],
    ["\$entry->get('photos')", 'list<asset>'],
    ["\$entry->get('sizes')", 'list<text>'],
    ["\$entry->get('related')", 'list<entry(articles)>'],
    ["\$entry->get('see_also')", 'list<entry>'],
    ["\$entry->get('topics')", 'list<term(topics)>'],
    ["\$entry->get('details')", 'group{material, weight, picture}'],
    ["\$entry->get('steps')", 'list<row{label, text, icon}>'],
    ["\$entry->get('sections')", 'list<block(hero|text|cta)>'],
    ["\$entry->get('nope')", null],
    ['$entry->title', 'text'],
    ['$entry->url', 'text'],
    ['$entry->published_at', 'date'],
    ['$entry->author', 'user'],
    ['$entry->author->name', 'text'],
    ['$entry->author?->name', 'text'],
    ['$entry->terms', 'list<term>'],
    ['$entry->summary', null],                                       // an entry's fields are not properties
    // Further in
    ["\$entry->get('cover')->url('large')", 'text'],
    ["\$entry->get('cover')?->url('small')", 'text'],                // a size the site doesn't list is a URL all the same
    ["\$entry->get('cover')?->alt", 'text'],
    ["\$entry->get('more')['url']", 'text'],
    ["\$entry->get('more')['new_tab']", 'bool'],
    ["\$entry->get('details')['picture']", 'asset'],
    ["\$entry->get('details')['picture']?->url()", 'text'],
    ["\$entry->get('made_on')->translatedFormat('j F Y')", 'text'],
    ["\$entry->get('made_on')?->format('Y')", 'text'],
    ['$entry->published_at->year', 'number'],
    // Blocks, globals, terms, collections, the page
    ['$block->heading', 'text'],
    ["\$block->get('heading')", 'text'],
    ["\$block['heading']", 'text'],
    ['$block->button', 'link'],
    ["\$block->button['label']", 'text'],
    ['$block->type', 'text'],
    ["sunrice_global('site')", 'global(site)'],
    ["sunrice_global('site')->name", 'text'],
    ["sunrice_global('site')->get('name', config('app.name'))", 'text'],
    ["sunrice_global('footer')->text", 'html'],
    ["\$term->get('description')", 'text'],
    ['$term->name', 'text'],
    ['$term->children', 'list<term(topics)>'],
    ['$term->parent', 'term(topics)'],
    ['$term->parent->parent->name', 'text'],
    ['$collection->titleIn($locale)', 'text'],
    ["\$collection->archive('image')", 'asset'],
    ["\$collection->archive('nope')", null],
    ["\$collection->archiveText(\$locale)['title']", 'text'],
    ['$sunricePage->entry', 'entry'],
    ['$sunricePage->entry->title', 'text'],
    ['$sunricePage->collection', 'collection'],
    // Lists
    ['$entries', 'list<entry(articles)>'],
    ['$entries->first()', 'entry(articles)'],
    ['$entries[0]', 'entry(articles)'],
    ['$entries->take(3)', 'list<entry(articles)>'],
    ['$entries->reject(fn ($item) => $item->id === $entry->id)->take(3)', 'list<entry(articles)>'],
    ['$entries->filter(function ($item) { return $item->title !== \'\'; })->values()', 'list<entry(articles)>'],
    ['$entries->count()', 'number'],
    ['$entries->isNotEmpty()', 'bool'],
    ['$entries->map(fn ($item) => $item->title)', null],
    ["\$entry->get('sections')->byKey('hero')", 'block(hero|text|cta)'],
    ["\$entry->get('steps')->first()['label']", 'text'],
    ['collect($entry->get(\'photos\'))->first()', 'asset'],
    ["sunrice_menu('main')", 'list<menu_item>'],
    ["sunrice_menu('main')->first()->children", 'list<menu_item>'],
    ["sunrice_entries('articles')->where('featured', true)->limit(3)->get()", 'list<entry(articles)>'],
    ["sunrice_entries('articles')->orderBy('published_at', 'desc')->paginate(12)", 'list<entry(articles)>'],
    // Either of two
    ["\$entry->get('photos') ?? []", 'list<asset>'],
    ["\$nobody ?? \$entry->get('cover')", 'asset'],
    ["\$entry->get('summary') ?: \$entry->title", 'text'],
    ["(\$entry->get('cover'))", 'asset'],
    ['$entry->get(\'cover\') instanceof \Illuminate\Support\Collection ? $entry->get(\'cover\')->first() : $entry->get(\'cover\')', 'asset'],
    ['$entry->get(\'photos\') instanceof \Illuminate\Support\Collection ? $entry->get(\'photos\')->first() : $entry->get(\'photos\')', 'asset'],
    ["\$entry->get('featured') ? \$entry->get('cover') : null", 'asset'],
    // What is worked out, not read
    ['$entry->title === \'Home\'', 'bool'],
    ["\$entry->get('price') > 100 && \$entry->get('featured')", 'bool'],
    ['! $entry->get(\'cover\')', 'bool'],
    ["empty(\$block->button['url'])", 'bool'],
    ['isset($entry)', 'bool'],
    ["\$entry->get('price') * 2", 'number'],
    ["\$entry->title.' — '.\$entry->get('summary')", 'text'],
    ["__('sunrice::frontend.by', ['name' => \$entry->author->name])", 'text'],
    ["'plain text'", 'text'],
    ['"With {$entry->title} in it"', 'text'],
    ['42', 'number'],
    ['true', 'bool'],
    ['null', null],
    ['[]', null],
    ['now()', 'date'],
    ['now()->year', 'number'],
    // Not read: never a guess
    ['$entry->$field', null],
    ['Some\Thing::make($entry)', null],
    ['new Thing($entry)', null],
    ['$entry->get(', null],
    ['$entry $entry', null],
    ['', null],
]);

it('says what an expression sets on its way', function () {
    $sets = fn (string $code): array => array_map(fn (?Shape $shape) => $shape?->spell(), Expression::read($code, $this->known, $this->catalog)[1]);

    expect($sets("\$image = \$entry->get('cover')"))->toBe(['image' => 'asset'])
        ->and($sets("(\$related = \$entry->get('related')) && \$related->isNotEmpty()"))->toBe(['related' => 'list<entry(articles)>'])
        ->and($sets('$count = 3'))->toBe(['count' => 'number'])
        ->and($sets('$thing = something_unknown()'))->toBe(['thing' => null])
        ->and($sets('$entry->title'))->toBe([])
        ->and($sets('$a == $b'))->toBe([]);
});

it('follows the statements of a piece of PHP one after the other', function () {
    $sets = array_map(fn (?Shape $shape) => $shape?->spell(), Expression::sets(<<<'PHP'

        $footer = sunrice_global('footer');
        $menu = sunrice_menu('footer');
        // The first of several, or the one there is.
        $image = $entry->get('photos');
        $image = $image instanceof \Illuminate\Support\Collection ? $image->first() : $image;
        $text = $footer->text;
        PHP, $this->known, $this->catalog));

    expect($sets)->toBe(['footer' => 'global(footer)', 'menu' => 'list<menu_item>', 'image' => 'asset', 'text' => 'html']);
});

it('reads what a loop goes over and what it calls each one', function (string $loop, ?array $expected) {
    $read = Expression::loop($loop, $this->known, $this->catalog);

    expect($read === null ? null : [$read['item'], $read['key'], $read['shape']?->spell()])->toBe($expected);
})->with([
    ['$entries as $entry', ['entry', null, 'entry(articles)']],
    ["\$entry->get('steps') ?? [] as \$index => \$step", ['step', 'index', 'row{label, text, icon}']],
    ["\$entry->get('photos') as \$photo", ['photo', null, 'asset']],
    ["sunrice_menu('main') as \$item", ['item', null, 'menu_item']],
    ['$entry->terms as $term', ['term', null, 'term']],
    ['$entries->reject(fn ($item) => $item->id === $entry->id)->take(3) as $item', ['item', null, 'entry(articles)']],
    ['$somethingElse as $one', ['one', null, null]],
    ["\$entry->get('summary') as \$letter", ['letter', null, null]],       // not a list: nothing is known of its items
    ['$pairs as [$a, $b]', null],
    ['$entries', null],
]);

it('takes the arguments of a call and the entries of an array apart', function () {
    expect(Expression::arguments("'sunrice.partials.card', ['entry' => \$item, 'wide' => true]"))->toBe(["'sunrice.partials.card'", "['entry' => \$item, 'wide' => true]"])
        ->and(Expression::arguments("\$a, fn (\$x, \$y) => [\$x, \$y], 'a,b'"))->toBe(['$a', 'fn ($x, $y) => [$x, $y]', "'a,b'"])
        ->and(Expression::arguments(''))->toBe([])
        ->and(Expression::pairs("['entry' => \$item, 'items' => \$item->children, \"wide\" => true, \$dynamic => 1, 'lonely']"))->toBe(['entry' => '$item', 'items' => '$item->children', 'wide' => 'true'])
        ->and(Expression::pairs('$notAnArray'))->toBe([])
        ->and(Expression::literal("'it\\'s'"))->toBe("it's")
        ->and(Expression::literal('"a\\nb"'))->toBe("a\nb")
        ->and(Expression::literal('$a'))->toBeNull()
        ->and(Expression::literal("'a'.'b'"))->toBeNull();
});
