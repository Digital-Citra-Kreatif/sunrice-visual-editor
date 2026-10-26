<?php

declare(strict_types=1);

use Sunrice\Cms\Models\Collection;
use Sunrice\Cms\Models\Taxonomy;
use Sunrice\VisualEditor\Fields\Catalog;
use Sunrice\VisualEditor\Fields\Emit;
use Sunrice\VisualEditor\Fields\Insert;
use Sunrice\VisualEditor\Fields\Reading;
use Sunrice\VisualEditor\Fields\Shape;
use Sunrice\VisualEditor\Fields\Variable;
use Sunrice\VisualEditor\Tests\Support\WithEveryField;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class, WithEveryField::class);

beforeEach(function () {
    $this->setUpStarterSite();
    $this->setUpEveryField();
    $this->catalog = new Catalog;
    $this->emit = new Emit($this->catalog);

    $this->entry = Reading::of(new Variable('$entry', Shape::entry(['things']), Variable::PAGE, 'The entry'));
    $this->values = Reading::of(new Variable('$values', Shape::values('collection', ['things']), Variable::PAGE, 'The fields of the entry'));
    /** The reading of a field, and of fields inside it: ('cover', 'alt'). */
    $this->read = function (Reading $from, string ...$handles): Reading {
        foreach ($handles as $handle) {
            $from = $from->field($this->catalog->member($from->shape, $handle) ?? throw new RuntimeException("Nothing called {$handle}."));
        }

        return $from;
    };
    /** The Blade of each way a field of the entry can be inserted, by its title. */
    $this->ways = function (Reading $reading, string $label, array $taken = []): array {
        $inserts = $this->emit->inserts($reading, $label, $taken);

        return array_combine(array_map(fn (Insert $insert) => $insert->title, $inserts), array_map(fn (Insert $insert) => $insert->blade, $inserts));
    };
});

/** Everything the emitter writes for the fields of a value, as one text to read and compare. */
function written(Catalog $catalog, callable $ways, Reading $owner): string
{
    $text = '';
    foreach ($catalog->members($owner->shape) as $members) {
        foreach ($members->fields as $field) {
            $text .= "\n### {$field->handle}: {$field->shape->spell()}\n";
            foreach ($ways($owner->field($field), $field->label) as $title => $blade) {
                $text .= "--- {$title}\n{$blade}\n";
            }
        }
    }

    return $text;
}

it('writes for each type of field the Blade that suits it', function () {
    $fixture = __DIR__.'/../../fixtures/emit/things.txt';
    // A page's own fields, then an entry's (any entry's: in a list, from an entries field).
    $written = written($this->catalog, $this->ways, $this->values).written($this->catalog, $this->ways, $this->entry);
    if (getenv('UPDATE_FIXTURES')) {
        file_put_contents($fixture, $written);
    }

    expect($written)->toBe((string) file_get_contents($fixture));
});

it('reads on into a file, a link, a group, a date and a row, guarding each step', function () {
    $printed = fn (string ...$handles): ?string => $this->emit->printed(($this->read)($this->values, ...$handles))?->blade;
    $ofEntry = fn (string ...$handles): ?string => $this->emit->printed(($this->read)($this->entry, ...$handles))?->blade;

    expect($printed('cover', 'url:large'))->toBe("{{ (\$values['cover'] ?? null)?->url('large') }}")
        ->and($printed('cover', 'alt'))->toBe("{{ (\$values['cover'] ?? null)?->altIn() }}")
        ->and($printed('more', 'url'))->toBe("{{ \$values['more']['url'] ?? '' }}")
        ->and($printed('details', 'material'))->toBe("{{ \$values['details']['material'] ?? '' }}")
        ->and($printed('details', 'picture', 'url:medium'))->toBe("{{ (\$values['details']['picture'] ?? null)?->url('medium') }}")
        ->and($printed('made_on', 'year'))->toBe("{{ (\$values['made_on'] ?? null)?->year }}")
        ->and($printed('body'))->toBe("{!! \$values['body'] ?? '' !!}")
        ->and($ofEntry('published_at', 'machine'))->toBe('{{ $entry->published_at?->toAtomString() }}')
        ->and($ofEntry('title'))->toBe('{{ $entry->title }}')
        ->and($ofEntry('url'))->toBe('{{ $entry->url() }}')
        // An entry's fields, as a list or an entries field gives it.
        ->and($ofEntry('summary'))->toBe("{{ \$entry->values()['summary'] ?? '' }}")
        ->and($ofEntry('cover', 'alt'))->toBe("{{ (\$entry->values()['cover'] ?? null)?->altIn() }}")
        // No text: a file, a link, a list, a group, a yes-or-no.
        ->and($printed('cover'))->toBeNull()
        ->and($printed('more'))->toBeNull()
        ->and($printed('photos'))->toBeNull()
        ->and($printed('details'))->toBeNull()
        ->and($printed('featured'))->toBeNull();

    // In a loop: the item is a variable, sure to be there.
    $step = ($this->read)($this->values, 'steps')->item('step');
    $photo = ($this->read)($this->values, 'photos')->item('photo');
    expect($this->emit->printed(($this->read)($step, 'label'))->blade)->toBe("{{ \$step['label'] ?? '' }}")
        ->and(($this->ways)(($this->read)($step, 'icon'), 'Icon')['Image'])->toBe("@if (\$icon = \$step['icon'] ?? null)\n    <img src=\"{{ \$icon->url('large') }}\" alt=\"{{ \$icon->altIn() }}\">\n@endif")
        ->and(($this->ways)($photo, 'Photo')['Image'])->toBe("<img src=\"{{ \$photo->url('large') }}\" alt=\"{{ \$photo->altIn() }}\">")
        ->and($this->emit->printed(($this->read)($photo, 'alt'))->blade)->toBe('{{ $photo->altIn() }}');
});

it('reads a variable that may be nothing with care, and one that is there plainly', function () {
    $image = fn (bool $sure) => Reading::of(new Variable('$image', Shape::of(Shape::ASSET), Variable::SET, 'Set above', $sure));
    $target = fn (bool $sure) => Reading::of(new Variable('$target', Shape::of(Shape::LINK), Variable::SET, 'Set above', $sure));

    expect(($this->ways)($image(false), 'Image')['Image'])->toBe("@if (\$image)\n    <img src=\"{{ \$image->url('large') }}\" alt=\"{{ \$image->altIn() }}\">\n@endif")
        ->and(($this->ways)($image(true), 'Image')['Image'])->toBe("<img src=\"{{ \$image->url('large') }}\" alt=\"{{ \$image->altIn() }}\">")
        ->and($this->emit->printed(($this->read)($image(false), 'alt'))->blade)->toBe('{{ $image?->altIn() }}')
        ->and(($this->ways)($target(false), 'Target')['Link'])->toStartWith("@if (\$target && ! empty(\$target['url']))\n")
        ->and(($this->ways)($target(true), 'Target')['Link'])->toStartWith("@if (! empty(\$target['url']))\n");
});

it('writes blocks, globals, menus, collections and the page', function () {
    $of = fn (string $code, Shape $shape) => Reading::of(new Variable($code, $shape, Variable::SITE, ''));
    $printed = fn (Reading $from, string ...$handles): ?string => $this->emit->printed(($this->read)($from, ...$handles))?->blade;

    $block = $of('$block', Shape::block(['hero']));
    expect($printed($block, 'heading'))->toBe('{{ $block->heading }}')
        ->and(($this->ways)(($this->read)($block, 'button'), 'Button')['Link'])->toStartWith("@if ((\$button = \$block->button) && ! empty(\$button['url']))\n    <a href=\"{{ \$button['url'] }}\"");

    expect($printed($of("cms_global('site')", Shape::globals('site')), 'name'))->toBe("{{ cms_global('site')['name'] ?? '' }}")
        ->and($printed($of("cms_global('footer')", Shape::globals('footer')), 'text'))->toBe("{{ cms_global('footer')['text'] ?? '' }}");

    expect(($this->ways)($of("cms_menu('main')", Shape::listOf(Shape::of(Shape::MENU_ITEM))), 'Main menu')['Menu'])->toBe(implode("\n", [
        '<ul>',
        "    @foreach (cms_menu('main') as \$item)",
        "        <li><a href=\"{{ \$item['url'] }}\">{{ \$item['label'] }}</a></li>",
        '    @endforeach',
        '</ul>',
    ]));

    $collection = $of('$collection', Shape::collection('things'));
    expect($printed($collection, 'title'))->toBe('{{ $collection->titleIn($locale) }}')
        ->and($printed($collection, 'listingUrl'))->toBe('{{ $collection->listingUrl($locale) }}');

    $listing = $of('$values', Shape::values('listing', ['things']));
    expect($printed($listing, 'description'))->toBe("{!! \$values['description'] ?? '' !!}")
        ->and(($this->ways)(($this->read)($listing, 'image'), 'Image')['Image'])->toStartWith("@if (\$image = \$values['image'] ?? null)\n");

    $page = $of('cms_page()', Shape::of(Shape::PAGE));
    expect($printed($page, 'entry', 'title'))->toBe('{{ cms_page()->entry?->title }}')
        ->and($printed($page, 'term', 'title'))->toBe('{{ cms_page()->term?->title }}')
        ->and(array_keys(($this->ways)(($this->read)($page, 'entry'), 'Entry')))->toBe(['Only when it is set']);
});

it('gives a new variable a name nothing at the place has', function () {
    $cover = ($this->read)($this->values, 'cover');
    $photos = ($this->read)($this->values, 'photos');
    $sections = ($this->read)($this->values, 'sections');

    expect(($this->ways)($cover, 'Cover', ['cover'])['Image'])->toStartWith('@if ($cover2 = ')
        ->and(($this->ways)($cover, 'Cover', ['cover', 'cover2'])['Image'])->toStartWith('@if ($cover3 = ')
        ->and(($this->ways)($photos, 'Photos', ['photo'])['Each one, in a loop'])->toContain(' as $photo2)')
        ->and(($this->ways)($sections, 'Sections', ['block'])['Each block, drawn by its own template'])->toContain(' as $block2)')->toContain("['block' => \$block2]")
        // A label that makes no name, and names that are Blade's and the CMS's own.
        ->and(($this->ways)($cover, '封面', [])['Image'])->toStartWith('@if ($value = ')
        ->and(($this->ways)($cover, 'Entry', [])['Image'])->toStartWith('@if ($entry2 = ')
        ->and(($this->ways)($photos, 'Entries', [])['Each one, in a loop'])->toContain(' as $file)')
        ->and(($this->ways)($photos, 'Loop', [])['Each one, in a loop'])->toContain(' as $file)');
});

it('links an entry or a term only when it has a page to link to', function () {
    Collection::query()->where('handle', 'articles')->firstOrFail()->update(['route' => null]);
    Taxonomy::query()->where('handle', 'topics')->firstOrFail()->update(['route' => null]);
    Taxonomy::query()->where('handle', 'topics')->firstOrFail()->collections()->updateExistingPivot(Collection::query()->where('handle', 'things')->value('id'), ['term_pages' => false]);
    $emit = new Emit($catalog = new Catalog);
    $loop = fn (string $handle, string $label) => $emit->inserts($this->values->field($catalog->member(Shape::values('collection', ['things']), $handle)), $label)[0]->blade;

    expect($loop('related', 'Related articles'))->toContain("    {{ \$article->title }}\n")->not->toContain('<a ')
        ->and($loop('see_also', 'See also'))->not->toContain('<a ')        // of two collections, and one has no pages
        ->and($loop('topics', 'Topics'))->toContain('<span>{{ $topic->title() }}</span>')->not->toContain('<a ');
});
