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
        'values' => Shape::values('collection', ['things']),
        'any' => Shape::entry(),
        'block' => Shape::block(['hero']),
        'term' => Shape::termPage(['topics']),
        'topic' => Shape::term(['topics']),
        'collection' => Shape::collection('things'),
        'listing' => Shape::values('listing', ['things']),
        'entries' => Shape::listOf(Shape::entry(['articles'])),
        'page' => Shape::of(Shape::PAGE),
        'locale' => Shape::of(Shape::TEXT),
    ];
    $this->is = fn (string $code): ?string => Expression::read($code, $this->known, $this->catalog)[0]?->spell();
});

it('reads what a value is from the way it is read', function (string $code, ?string $shape) {
    expect(($this->is)($code))->toBe($shape);
})->with([
    // Variables
    ['$entry', 'entry(things)'],
    ['$values', 'values(collection:things)'],
    ['$nobody', null],
    // A page's fields, as the CMS gives them: $values
    ["\$values['summary']", 'text'],
    ['$values["summary"]', 'text'],
    ["\$values['body']", 'html'],
    ["\$values['price']", 'number'],
    ["\$values['featured']", 'bool'],
    ["\$values['made_on']", 'date'],
    ["\$values['more']", 'link'],
    ["\$values['cover']", 'asset'],
    ["\$values['photos']", 'list<asset>'],
    ["\$values['sizes']", 'list<text>'],
    ["\$values['related']", 'list<entry(articles)>'],
    ["\$values['see_also']", 'list<entry(articles|pages)>'],
    ["\$values['mentor']", 'entry(articles)'],
    ["\$values['topics']", 'list<term(topics)>'],
    ["\$values['details']", 'group{material, weight, picture}'],
    ["\$values['steps']", 'list<row{label, text, icon}>'],
    ["\$values['sections']", 'list<block(hero|text|cta)>'],
    ["\$values['nope']", null],
    ["\$values['summary'] ?? null", 'text'],
    // What every entry has, and its fields from values()
    ['$entry->title', 'text'],
    ['$entry->url()', 'text'],
    ['$entry->published_at', 'date'],
    ['$entry->entry->terms', 'list<term>'],
    ['$entry->entry->collection', 'collection(things)'],
    ['$entry->summary', null],                                       // an entry's fields are not properties
    ['$entry->values()', 'values(collection:things)'],
    ["\$entry->values()['summary']", 'text'],
    ["\$any->values()['excerpt']", 'text'],
    ["\$entries->first()->values()['excerpt']", 'text'],
    // Further in
    ["\$values['cover']->url('large')", 'text'],
    ["(\$values['cover'] ?? null)?->url('small')", 'text'],          // a size the site doesn't list is a URL all the same
    ["\$values['cover']?->altIn()", 'text'],
    ["\$values['more']['url']", 'text'],
    ["\$values['more']['new_tab']", 'bool'],
    ["\$values['details']['picture']", 'asset'],
    ["\$values['details']['picture']?->url()", 'text'],
    ["\$values['made_on']->translatedFormat('j F Y')", 'text'],
    ["\$values['made_on']?->format('Y')", 'text'],
    ['$entry->published_at->year', 'number'],
    ["\$values['mentor']->title", 'text'],
    ["\$values['mentor']?->values()['excerpt']", 'text'],
    // Blocks, globals, terms, collections, the page
    ['$block->heading', 'text'],
    ["\$block->get('heading')", 'text'],
    ["\$block['heading']", 'text'],
    ['$block->button', 'link'],
    ["\$block->button['label']", 'text'],
    ['$block->type', 'text'],
    ["cms_global('footer')", 'global(footer)'],
    ["cms_global('footer')['text']", 'text'],
    ["Cms::global('footer')['text']", 'text'],
    ["\\Sunrice\\Cms\\Content::global('footer')", 'global(footer)'],
    ['$term->title', 'text'],
    ['$term->url()', 'text'],
    ["\$term->values()['description']", 'text'],
    ['$term->term->children', 'list<term(topics)>'],
    ['$topic->title()', 'text'],
    ['$topic->parent', 'term(topics)'],
    ['$topic->parent->parent->title()', 'text'],
    ['$collection->titleIn()', 'text'],
    ['$collection->listingUrl()', 'text'],
    ["\$listing['image']", 'asset'],
    ["\$listing['nope']", null],
    ['cms_page()', 'page'],
    ['cms_page()?->entry', 'entry'],
    ['$page->entry->title', 'text'],
    ['$page->collection', 'collection'],
    ['$page?->home', 'bool'],
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
    ["\$values['sections']->byKey('hero')", 'block(hero|text|cta)'],
    ["\$values['steps']->first()['label']", 'text'],
    ["collect(\$values['photos'])->first()", 'asset'],
    ["cms_menu('main')", 'list<menu_item>'],
    ["cms_menu('main')[0]['children']", 'list<menu_item>'],
    ["Cms::menu('main')", 'list<menu_item>'],
    // Either of two
    ["\$values['photos'] ?? []", 'list<asset>'],
    ["\$nobody ?? \$values['cover']", 'asset'],
    ["\$values['summary'] ?: \$entry->title", 'text'],
    ["(\$values['cover'])", 'asset'],
    ["\$values['cover'] instanceof \\Illuminate\\Support\\Collection ? \$values['cover']->first() : \$values['cover']", 'asset'],
    ["\$values['photos'] instanceof \\Illuminate\\Support\\Collection ? \$values['photos']->first() : \$values['photos']", 'asset'],
    ["\$values['featured'] ? \$values['cover'] : null", 'asset'],
    // What is worked out, not read
    ['$entry->title === \'Home\'', 'bool'],
    ["\$values['price'] > 100 && \$values['featured']", 'bool'],
    ["! \$values['cover']", 'bool'],
    ["empty(\$block->button['url'])", 'bool'],
    ['isset($entry)', 'bool'],
    ["\$values['price'] * 2", 'number'],
    ["\$entry->title.' — '.\$values['summary']", 'text'],
    ["__('By :name', ['name' => \$entry->title])", 'text'],
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
    ['Cms::somethingElse()', null],
    ['new Thing($entry)', null],
    ["\$values['summary'", null],
    ['$entry $entry', null],
    ['', null],
]);

it('says what an expression sets on its way', function () {
    $sets = fn (string $code): array => array_map(fn (?Shape $shape) => $shape?->spell(), Expression::read($code, $this->known, $this->catalog)[1]);

    expect($sets("\$image = \$values['cover']"))->toBe(['image' => 'asset'])
        ->and($sets("(\$related = \$values['related']) && \$related->isNotEmpty()"))->toBe(['related' => 'list<entry(articles)>'])
        ->and($sets('$fields = $entry->values()'))->toBe(['fields' => 'values(collection:things)'])
        ->and($sets('$count = 3'))->toBe(['count' => 'number'])
        ->and($sets('$thing = something_unknown()'))->toBe(['thing' => null])
        ->and($sets('$entry->title'))->toBe([])
        ->and($sets('$a == $b'))->toBe([]);
});

it('follows the statements of a piece of PHP one after the other', function () {
    $sets = array_map(fn (?Shape $shape) => $shape?->spell(), Expression::sets(<<<'PHP'

        $footer = cms_global('footer');
        $menu = cms_menu('footer');
        // The first of several, or the one there is.
        $image = $values['photos'];
        $image = $image instanceof \Illuminate\Support\Collection ? $image->first() : $image;
        $text = $footer['text'];
        $page = cms_page();
        PHP, $this->known, $this->catalog));

    expect($sets)->toBe(['footer' => 'global(footer)', 'menu' => 'list<menu_item>', 'image' => 'asset', 'text' => 'text', 'page' => 'page']);
});

it('reads what a loop goes over and what it calls each one', function (string $loop, ?array $expected) {
    $read = Expression::loop($loop, $this->known, $this->catalog);

    expect($read === null ? null : [$read['item'], $read['key'], $read['shape']?->spell()])->toBe($expected);
})->with([
    ['$entries as $entry', ['entry', null, 'entry(articles)']],
    ["\$values['steps'] ?? [] as \$index => \$step", ['step', 'index', 'row{label, text, icon}']],
    ["\$values['photos'] as \$photo", ['photo', null, 'asset']],
    ["cms_menu('main') as \$item", ['item', null, 'menu_item']],
    ['$entry->entry->terms as $topic', ['topic', null, 'term']],
    ['$entries->reject(fn ($item) => $item->id === $entry->id)->take(3) as $item', ['item', null, 'entry(articles)']],
    ['$somethingElse as $one', ['one', null, null]],
    ["\$values['summary'] as \$letter", ['letter', null, null]],       // not a list: nothing is known of its items
    ['$pairs as [$a, $b]', null],
    ['$entries', null],
]);

it('takes the arguments of a call and the entries of an array apart', function () {
    expect(Expression::arguments("'cms.partials.card', ['item' => \$item, 'wide' => true]"))->toBe(["'cms.partials.card'", "['item' => \$item, 'wide' => true]"])
        ->and(Expression::arguments("\$a, fn (\$x, \$y) => [\$x, \$y], 'a,b'"))->toBe(['$a', 'fn ($x, $y) => [$x, $y]', "'a,b'"])
        ->and(Expression::arguments(''))->toBe([])
        ->and(Expression::pairs("['entry' => \$item, 'items' => \$item->children, \"wide\" => true, \$dynamic => 1, 'lonely']"))->toBe(['entry' => '$item', 'items' => '$item->children', 'wide' => 'true'])
        ->and(Expression::pairs('$notAnArray'))->toBe([])
        ->and(Expression::literal("'it\\'s'"))->toBe("it's")
        ->and(Expression::literal('"a\\nb"'))->toBe("a\nb")
        ->and(Expression::literal('$a'))->toBeNull()
        ->and(Expression::literal("'a'.'b'"))->toBeNull();
});
