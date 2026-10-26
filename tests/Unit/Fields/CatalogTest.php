<?php

declare(strict_types=1);

use Sunrice\Cms\Actions\Fieldsets\SaveFieldset;
use Sunrice\Cms\Fields\CustomField;
use Sunrice\Cms\Fields\FieldTypes;
use Sunrice\Cms\Models\Fieldset;
use Sunrice\VisualEditor\Fields\Catalog;
use Sunrice\VisualEditor\Fields\Field;
use Sunrice\VisualEditor\Fields\Members;
use Sunrice\VisualEditor\Fields\Shape;
use Sunrice\VisualEditor\Tests\Support\WithEveryField;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class, WithEveryField::class);

beforeEach(function () {
    $this->setUpStarterSite();
    $this->setUpEveryField();
    $this->catalog = new Catalog;
});

/** handle => what it is, in a few words. */
function shapes(array $fields): array
{
    return array_combine(array_map(fn (Field $field) => $field->handle, $fields), array_map(fn (Field $field) => $field->shape->name(), $fields));
}

it('lists the fields of the demo site\'s blueprints as the blueprints have them', function () {
    expect(shapes($this->catalog->fields('collection', 'pages')))->toBe(['body' => 'rich text', 'sections' => 'list of blocks'])
        ->and(shapes($this->catalog->fields('collection', 'articles')))->toBe(['excerpt' => 'text', 'body' => 'rich text'])
        ->and(shapes($this->catalog->fields('fieldset', 'hero')))->toBe(['heading' => 'text', 'subheading' => 'text', 'button' => 'link'])
        ->and(shapes($this->catalog->fields('fieldset', 'gallery')))->toBe(['title' => 'text', 'images' => 'list of files'])
        ->and(shapes($this->catalog->fields('global', 'site')))->toBe(['name' => 'text'])
        ->and(shapes($this->catalog->fields('global', 'footer')))->toBe(['text' => 'text']);

    $sections = $this->catalog->fields('collection', 'pages')[1];
    expect($sections->label)->toBe('Sections')
        ->and($sections->type)->toBe('flexible')
        ->and($sections->multiple())->toBeTrue()
        ->and($sections->shape->item->of)->toBe(['hero', 'text', 'gallery']);
});

it('knows what every type of field gives a template', function () {
    $fields = $this->catalog->fields('collection', 'things');

    expect(shapes($fields))->toBe([
        'summary' => 'text',
        'notes' => 'text',
        'body' => 'rich text',
        'price' => 'number',
        'featured' => 'yes or no',
        'colour' => 'text',
        'sizes' => 'list of texts',
        'made_on' => 'date',
        'more' => 'link',
        'cover' => 'file',
        'photos' => 'list of files',
        'related' => 'list of entries',
        'see_also' => 'list of entries',
        'topics' => 'list of terms',
        'details' => 'group',
        'steps' => 'list of rows',
        'sections' => 'list of blocks',
    ]);

    $by = array_combine(array_map(fn (Field $field) => $field->handle, $fields), $fields);
    expect($by['related']->shape->item->of)->toBe(['articles'])
        ->and($by['see_also']->shape->item->of)->toBe(['articles', 'pages'])
        ->and($by['topics']->shape->item->of)->toBe(['topics'])
        ->and($by['sections']->shape->item->of)->toBe(['hero', 'text', 'cta'])
        ->and(shapes($by['details']->children()))->toBe(['material' => 'text', 'weight' => 'number', 'picture' => 'file'])
        ->and(shapes($by['steps']->children()))->toBe(['label' => 'text', 'text' => 'rich text', 'icon' => 'file'])
        ->and($by['summary']->children())->toBe([])
        ->and($by['summary']->multiple())->toBeFalse();
});

it('says how each field is read from what has it', function () {
    $from = fn (string $subject, string $handle, string $field, string $owner, string $how = Catalog::BY_KEY) => collect($this->catalog->fields($subject, $handle, $how))->firstOrFail(fn (Field $one) => $one->handle === $field)->from($owner);

    expect($from('collection', 'things', 'summary', '$values'))->toBe("\$values['summary']")
        ->and($from('collection', 'things', 'summary', '$item', Catalog::ON_ENTRY))->toBe("\$item->values()['summary']")
        ->and($from('taxonomy', 'topics', 'description', '$topic', Catalog::ON_TERM))->toBe("\$topic->translation()?->values()['description']")
        ->and($from('fieldset', 'hero', 'heading', '$block', Catalog::ON_BLOCK))->toBe('$block->heading')
        ->and($from('fieldset', 'hero', 'heading', '$block->values'))->toBe("\$block->values['heading']")
        ->and($from('global', 'site', 'name', "cms_global('site')"))->toBe("cms_global('site')['name']")
        ->and($from('listing', 'things', 'image', '$values'))->toBe("\$values['image']");

    // What is inside a group or a row is read from an array.
    $details = collect($this->catalog->fields('collection', 'things'))->firstOrFail(fn (Field $one) => $one->handle === 'details');
    expect($details->children()[0]->from("\$values['details']"))->toBe("\$values['details']['material']");

    // From something that may not be there, nothing is read.
    $alt = $this->catalog->member(Shape::of(Shape::ASSET), 'alt');
    expect($alt->from("\$values['cover']", ownerMayBeMissing: true))->toBe("\$values['cover']?->altIn()")
        ->and($alt->from('$image'))->toBe('$image->altIn()')
        ->and($this->catalog->member(Shape::entry(['things']), 'summary')->from('$item', ownerMayBeMissing: true))->toBe("\$item?->values()['summary']");
});

it('lists what every entry has, then the fields of each collection it may be of', function () {
    $titles = fn (array $members) => array_map(fn (Members $one) => $one->title, $members);

    $any = $this->catalog->members(Shape::entry());
    expect($titles($any))->toBe(['Every entry', 'Articles', 'Pages', 'Things'])
        ->and(array_map(fn (Field $field) => $field->handle, $any[0]->fields))->toBe(['title', 'url', 'published_at', 'terms', 'slug'])
        ->and($any[0]->fields[0]->builtIn)->toBeTrue()
        ->and($any[1]->subject)->toBe('collection')
        ->and($any[1]->handle)->toBe('articles');

    $article = $this->catalog->members(Shape::entry(['articles']));
    expect($titles($article))->toBe(['Every entry', 'Articles'])
        ->and(shapes($article[1]->fields))->toBe(['excerpt' => 'text', 'body' => 'rich text']);

    // A collection that is gone is left out.
    expect($titles($this->catalog->members(Shape::entry(['articles', 'gone']))))->toBe(['Every entry', 'Articles']);
});

it('lists what terms, blocks, globals, files, links, dates and menu items have', function () {
    $handles = fn (Shape $shape) => array_map(fn (Members $members) => array_map(fn (Field $field) => $field->handle, $members->fields), $this->catalog->members($shape));

    expect($handles(Shape::term(['topics'])))->toBe([['title', 'url', 'parent', 'children'], ['description']])
        ->and($handles(Shape::termTranslation(['topics'])))->toBe([['title', 'url', 'slug', 'term'], ['description']])
        ->and($handles(Shape::block(['hero'])))->toBe([['heading', 'subheading', 'button'], ['type', 'key', 'id']])
        ->and($handles(Shape::globals('site')))->toBe([['name']])
        ->and($handles(Shape::values('collection', ['articles'])))->toBe([['excerpt', 'body']])
        ->and($handles(Shape::values('listing', ['things'])))->toBe([['image', 'description']])
        ->and($handles(Shape::values('listing', ['articles'])))->toBe([])   // no listing blueprint
        ->and($handles(Shape::values(Shape::MIXED, ['fieldset:hero', 'collection:articles'])))->toBe([['heading', 'subheading', 'button'], ['excerpt', 'body']])
        ->and($handles(Shape::of(Shape::ASSET)))->toBe([['url:large', 'url:medium', 'url:thumbnail', 'url', 'alt', 'caption', 'title', 'width', 'height']])
        ->and($handles(Shape::of(Shape::LINK)))->toBe([['url', 'label', 'title', 'new_tab']])
        ->and($handles(Shape::of(Shape::DATE)))->toBe([['long', 'short', 'time', 'year', 'machine']])
        ->and($handles(Shape::of(Shape::MENU_ITEM)))->toBe([['label', 'url', 'new_tab', 'active', 'active_trail', 'children']])
        ->and($handles(Shape::of(Shape::USER)))->toBe([['name']])
        ->and($handles(Shape::of(Shape::PAGE)))->toBe([['entry', 'term', 'collection', 'taxonomy', 'home', 'pageNumber']])
        ->and($handles(Shape::collection('things')))->toBe([['title', 'listingUrl']])
        ->and($handles(Shape::of(Shape::TAXONOMY)))->toBe([['title', 'indexUrl']])
        ->and($handles(Shape::of(Shape::TEXT)))->toBe([]);

    expect($this->catalog->member(Shape::of(Shape::ASSET), 'url:large')->from('$image'))->toBe("\$image->url('large')")
        ->and($this->catalog->member(Shape::of(Shape::ASSET), 'url')->from('$image'))->toBe('$image->url()')
        ->and($this->catalog->member(Shape::of(Shape::LINK), 'url')->from('$block->button'))->toBe("\$block->button['url']")
        ->and($this->catalog->member(Shape::of(Shape::MENU_ITEM), 'children')->from('$item'))->toBe("\$item['children']")
        ->and($this->catalog->member(Shape::entry(), 'nope'))->toBeNull();
});

it('follows a field of a group and a row to what is inside', function () {
    $steps = $this->catalog->member(Shape::values('collection', ['things']), 'steps');
    $row = $this->catalog->members($steps->shape->item);

    expect($row)->toHaveCount(1)
        ->and(shapes($row[0]->fields))->toBe(['label' => 'text', 'text' => 'rich text', 'icon' => 'file'])
        ->and($row[0]->fields[0]->from('$step'))->toBe("\$step['label']");
});

it('takes a site\'s own field type for the built-in one it is made of', function () {
    $rating = new class extends CustomField
    {
        public function type(): string
        {
            return 'rating';
        }

        public function baseType(): string
        {
            return 'number';
        }
    };
    app(FieldTypes::class)->register($rating::class);
    app(SaveFieldset::class)->handle(['handle' => 'review', 'title' => 'Review', 'fields' => [
        ['handle' => 'stars', 'type' => 'rating', 'label' => 'Stars'],
    ]]);
    // A type no longer registered: content keeps its values, and templates get them as they are.
    Fieldset::query()->where('handle', 'review')->update(['fields' => json_encode([
        ['handle' => 'stars', 'type' => 'rating', 'label' => 'Stars'],
        ['handle' => 'mystery', 'type' => 'no_such_type', 'label' => 'Mystery'],
    ])]);
    $fields = (new Catalog)->fields('fieldset', 'review');
    expect($fields[0]->type)->toBe('rating')
        ->and($fields[0]->shape->kind)->toBe(Shape::NUMBER)
        ->and($fields[1]->shape->kind)->toBe(Shape::VALUE);
});

it('has nothing for what is not there, and comes out as plain data', function () {
    expect($this->catalog->fields('collection', 'nope'))->toBe([])
        ->and($this->catalog->fields('whatever', 'things'))->toBe([]);

    $data = array_map(fn (Field $field) => $field->toArray(), $this->catalog->fields('collection', 'things'));
    expect(json_decode(json_encode($data), true))->toBe($data)
        ->and($data[0])->toBe(['handle' => 'summary', 'label' => 'Summary', 'type' => 'text', 'shape' => ['kind' => 'text']])
        ->and($this->catalog->fields('collection', 'things', Catalog::ON_ENTRY)[0]->read)->toBe("%s->values()['summary']")
        ->and($data[6]['multiple'])->toBeTrue()
        ->and(array_column($data[14]['children'], 'handle'))->toBe(['material', 'weight', 'picture']);
});
