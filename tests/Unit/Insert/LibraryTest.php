<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Fragment;
use Sunrice\VisualEditor\Blade\Operations;
use Sunrice\VisualEditor\Blade\Syntax;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Insert\Library;
use Sunrice\VisualEditor\Templates\TemplateFile;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class);

/*
 * What the editor offers to insert. Each entry is put into a template in
 * every way the Insert panel can put it, and has to leave a template that
 * opens as a tree and compiles.
 */

const SVE_LIBRARY_TEMPLATE = <<<'BLADE'
<main>
    <section class="hero">
        <h1>{{ $title }}</h1>
    </section>
    <div></div>
    @if ($more)
    @endif
</main>

BLADE;

/** Every entry there is for a site with these collections, taxonomies and forms. */
function sveLibrary(): array
{
    return [
        ...Library::elements(),
        ...Library::blade(),
        ...Library::sunrice(
            [['handle' => 'articles', 'title' => 'Articles', 'single' => true], ['handle' => 'team', 'title' => 'Team', 'single' => false]],
            [['handle' => 'categories', 'title' => 'Categories', 'archive' => true], ['handle' => 'tags', 'title' => 'Tags', 'archive' => false]],
            [['handle' => 'contact', 'title' => 'Contact']],
        ),
    ];
}

it('offers pieces that stand on their own, go anywhere and compile', function (string $blade) {
    // Throws when a tag or a block is left open.
    Fragment::parse($blade);

    $template = Template::open(SVE_LIBRARY_TEMPLATE);
    $at = fn (string $text) => (string) strpos(SVE_LIBRARY_TEMPLATE, $text);

    foreach ([
        'into an element that has things in it' => ['append', $at('<section')],
        'after an element' => ['insertAfter', $at('<h1>')],
        'before an element' => ['insertBefore', $at('<h1>')],
        'into an empty element' => ['append', $at('<div>')],
        'into an empty block' => ['append', 'b'.$at('@if')],
        'into the template' => ['append', 'root'],
    ] as $where => [$type, $node]) {
        $result = Operations::apply(SVE_LIBRARY_TEMPLATE, [['type' => $type, 'node' => $node, 'blade' => $blade]]);

        expect($result)->not->toBe(SVE_LIBRARY_TEMPLATE, $where)
            ->and(Template::open($result)->strays())->toBe([], $where)
            ->and(Syntax::error($result))->toBeNull($where)
            // What was there is still there, as it was.
            ->and(str_contains($result, '<h1>{{ $title }}</h1>'))->toBeTrue($where);
    }

    expect($template->print())->toBe(SVE_LIBRARY_TEMPLATE);
})->with(fn () => array_column(sveLibrary(), 'blade', 'id'));

it('names each entry once, in its group', function () {
    $library = sveLibrary();

    expect(array_unique(array_column($library, 'id')))->toHaveCount(count($library))
        ->and(array_values(array_unique(array_column($library, 'group'))))->toBe([Library::ELEMENTS, Library::BLADE, Library::SUNRICE]);

    foreach ($library as $entry) {
        expect($entry['title'])->not->toBe('')
            ->and($entry['hint'])->not->toBe('')
            ->and($entry['blade'])->not->toBe('');
    }
});

it('names a collection, taxonomy or form of the site in each of the CMS\'s components', function () {
    $entries = collect(sveLibrary())->keyBy('id');

    expect($entries['sunrice.entries.articles']['blade'])->toContain('<x-sunrice-cms::entries collection="articles"', '<a href="{{ $item->url() }}">{{ $item->title }}</a>')
        // Each one is $item: on an entry's page $entry is the page's own.
        ->and($entries['sunrice.entries.articles']['blade'])->toContain('as $item)')->not->toContain('$entry')
        // Entries without a page of their own have no address to link to.
        ->and($entries['sunrice.entries.team']['blade'])->toContain('<h3>{{ $item->title }}</h3>')->not->toContain('href')
        ->and($entries['sunrice.terms.categories']['blade'])->toContain('<x-sunrice-cms::terms taxonomy="categories">', '<a href="{{ $term->url() }}"', '{{ $term->title() }}')
        // Terms without pages of their own: no link.
        ->and($entries['sunrice.terms.tags']['blade'])->toContain('<li>{{ $term->title() }}</li>')->not->toContain('href')
        ->and($entries['sunrice.form.contact']['blade'])->toBe('<x-sunrice-cms::form handle="contact" />')
        ->and($entries['sunrice.code.head']['blade'])->toBe('<x-sunrice-cms::code place="head" />');
});

it('leaves out what has a handle that could not be written into Blade as it is', function () {
    $ids = array_column(Library::sunrice(
        [['handle' => 'a"b', 'title' => 'Odd', 'single' => true], ['handle' => 'news', 'title' => 'News', 'single' => true]],
        [['handle' => '{{ $x }}', 'title' => 'Odd', 'archive' => true]],
        [['handle' => "it's", 'title' => 'Odd']],
    ), 'id');

    expect($ids)->toContain('sunrice.entries.news', 'sunrice.search')
        ->and(implode(' ', $ids))->not->toContain('a"b', '$x', "it's", 'terms', 'form');
});

it('offers the site\'s partials as includes, with what each needs', function () {
    $this->setUpStarterSite();
    $file = fn (string $path, string $origin = TemplateFile::SITE, string $role = 'partial') => new TemplateFile($path, $origin, 'cms.'.str_replace('/', '.', substr($path, 0, -10)), $role, [], $this->templates.'/'.$path);
    file_put_contents($this->templates.'/partials/brand.blade.php', '<a class="brand" href="/">{{ cms_global(\'footer\')[\'text\'] ?? \'\' }}</a>');

    $entries = collect(Library::partials([
        $file('partials/card.blade.php'),
        $file('partials/menu.blade.php'),
        $file('partials/brand.blade.php'),
        // Not partials, and not the site's: not offered.
        $file('layout.blade.php', role: 'layout'),
        $file('blocks/hero.blade.php', role: 'block'),
        $file('comments/thread.blade.php', TemplateFile::PACKAGE),
    ]))->keyBy('id');

    expect($entries->keys()->all())->toBe(['partial.cms.partials.card', 'partial.cms.partials.menu', 'partial.cms.partials.brand'])
        ->and($entries['partial.cms.partials.card'])->toMatchArray(['group' => Library::PARTIALS, 'title' => 'partials/card', 'hint' => 'Needs $item', 'blade' => "@include('cms.partials.card')"])
        ->and($entries['partial.cms.partials.menu']['hint'])->toBe('Needs $items')
        // It reads the site's global itself.
        ->and($entries['partial.cms.partials.brand']['hint'])->toBe('@include');
});
