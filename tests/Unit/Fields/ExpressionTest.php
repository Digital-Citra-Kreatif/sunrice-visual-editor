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
        'term' => Shape::term(['topics']),
        'shown' => Shape::termTranslation(['topics']),
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
    ['$nobody', null],
    // A page's fields, an entry's, and what every entry has
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
    ["\$values['topics']", 'list<term(topics)>'],
    ["\$values['details']", 'group{material, weight, picture}'],
    ["\$values['steps']", 'list<row{label, text, icon}>'],
    ["\$values['sections']", 'list<block(hero|text|cta)>'],
    ["\$values['nope']", null],
    ['$entry->values()', 'values:collection(things)'],
    ["\$entry->values()['summary']", 'text'],
    ["\$entry->values()['cover']?->url('large')", 'text'],
    ["\$any->values()['excerpt']", 'text'],                          // of the articles, the only collection with an excerpt
    ['$entry->title', 'text'],
    ['$entry->url()', 'text'],
    ['$entry->published_at', 'date'],
    ['$entry->terms', 'list<term>'],
    ['$entry->summary', null],                                       // an entry's fields are not properties
    ['$entry->url', null],                                           // its address is a method
    // Further in
    ["\$values['cover']->url('large')", 'text'],
    ["\$values['cover']?->url('small')", 'text'],                    // a size the site doesn't list is a URL all the same
    ["\$values['cover']?->altIn()", 'text'],
    ["\$values['more']['url']", 'text'],
    ["\$values['more']['new_tab']", 'bool'],
    ["\$values['details']['picture']", 'asset'],
    ["\$values['details']['picture']?->url()", 'text'],
    ["\$values['made_on']->translatedFormat('j F Y')", 'text'],
    ["\$values['made_on']?->format('Y')", 'text'],
    ['$entry->published_at->year', 'number'],
    // Blocks, globals, terms, collections, the page
    ['$block->heading', 'text'],
    ["\$block->get('heading')", 'text'],
    ["\$block['heading']", 'text'],
    ['$block->button', 'link'],
    ["\$block->button['label']", 'text'],
    ['$block->type', 'text'],
    ['$block->values', 'values:fieldset(hero)'],
    ["\$block->values['heading']", 'text'],
    ["cms_global('site')", 'global(site)'],
    ["cms_global('site')['name']", 'text'],
    ["cms_global('footer')['text']", 'text'],
    ["cms_global('site')->name", null],                               // a global set is an array
    ['$term->title()', 'text'],
    ['$term->url()', 'text'],
    ["\$term->translation()?->values()['description']", 'text'],
    ['$term->children', 'list<term(topics)>'],
    ['$term->parent', 'term(topics)'],
    ['$term->parent->parent->title()', 'text'],
    ['$shown->title', 'text'],
    ['$shown->term->children', 'list<term(topics)>'],
    ["\$shown->values()['description']", 'text'],
    ['$collection->titleIn($locale)', 'text'],
    ['$collection->listingUrl($locale)', 'text'],
    ["\$listing['image']", 'asset'],
    ["\$listing['nope']", null],
    ['cms_page()', 'page'],
    ['cms_page()->entry', 'entry'],
    ['cms_page()?->entry->title', 'text'],
    ['$page->collection', 'collection'],
    ['$page->term', 'term_translation'],
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
    ["cms_menu('main')[0]['label']", 'text'],
    ["cms_entries('articles')->where('featured', true)->limit(3)->get()", 'list<entry(articles)>'],
    ["cms_entries('articles')->orderBy('published_at', 'desc')->paginate(12)", 'list<entry(articles)>'],
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
    ['new Thing($entry)', null],
    ['$entry->values(', null],
    ['$entry $entry', null],
    ['', null],
]);

it('says what an expression sets on its way', function () {
    $sets = fn (string $code): array => array_map(fn (?Shape $shape) => $shape?->spell(), Expression::read($code, $this->known, $this->catalog)[1]);

    expect($sets("\$image = \$values['cover']"))->toBe(['image' => 'asset'])
        ->and($sets("(\$related = \$values['related']) && \$related->isNotEmpty()"))->toBe(['related' => 'list<entry(articles)>'])
        ->and($sets('$fields = $entry->values()'))->toBe(['fields' => 'values:collection(things)'])
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
        PHP, $this->known, $this->catalog));

    expect($sets)->toBe(['footer' => 'global(footer)', 'menu' => 'list<menu_item>', 'image' => 'asset', 'text' => 'text']);
});

it('reads what a loop goes over and what it calls each one', function (string $loop, ?array $expected) {
    $read = Expression::loop($loop, $this->known, $this->catalog);

    expect($read === null ? null : [$read['item'], $read['key'], $read['shape']?->spell()])->toBe($expected);
})->with([
    ['$entries as $entry', ['entry', null, 'entry(articles)']],
    ["\$values['steps'] ?? [] as \$index => \$step", ['step', 'index', 'row{label, text, icon}']],
    ["\$values['photos'] as \$photo", ['photo', null, 'asset']],
    ["cms_menu('main') as \$item", ['item', null, 'menu_item']],
    ['$entry->terms as $term', ['term', null, 'term']],
    ['$entries->reject(fn ($item) => $item->id === $entry->id)->take(3) as $item', ['item', null, 'entry(articles)']],
    ['$somethingElse as $one', ['one', null, null]],
    ["\$values['summary'] as \$letter", ['letter', null, null]],          // not a list: nothing is known of its items
    ['$pairs as [$a, $b]', null],
    ['$entries', null],
]);

it('takes the arguments of a call and the entries of an array apart', function () {
    expect(Expression::arguments("'cms.partials.card', ['item' => \$entry, 'wide' => true]"))->toBe(["'cms.partials.card'", "['item' => \$entry, 'wide' => true]"])
        ->and(Expression::arguments("\$a, fn (\$x, \$y) => [\$x, \$y], 'a,b'"))->toBe(['$a', 'fn ($x, $y) => [$x, $y]', "'a,b'"])
        ->and(Expression::arguments(''))->toBe([])
        ->and(Expression::pairs("['entry' => \$item, 'items' => \$item->children, \"wide\" => true, \$dynamic => 1, 'lonely']"))->toBe(['entry' => '$item', 'items' => '$item->children', 'wide' => 'true'])
        ->and(Expression::pairs('$notAnArray'))->toBe([])
        ->and(Expression::literal("'it\\'s'"))->toBe("it's")
        ->and(Expression::literal('"a\\nb"'))->toBe("a\nb")
        ->and(Expression::literal('$a'))->toBeNull()
        ->and(Expression::literal("'a'.'b'"))->toBeNull();
});
