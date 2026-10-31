<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Sunrice\VisualEditor\Blade\Fragment;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Fields\Catalog;
use Sunrice\VisualEditor\Fields\Emit;
use Sunrice\VisualEditor\Fields\Reading;
use Sunrice\VisualEditor\Fields\Shape;
use Sunrice\VisualEditor\Fields\Variable;
use Sunrice\VisualEditor\Tests\Support\WithEveryField;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class, WithEveryField::class);

/*
 * What the emitter writes is put into a template of the site and drawn
 * as a page, for a thing with every field filled and for one with none.
 */
beforeEach(function () {
    $this->setUpStarterSite();
    $this->setUpEveryField();
    $catalog = new Catalog;
    $emit = new Emit($catalog);

    /**
     * Every way of inserting or printing each field of a value, and of what is inside it, by a key:
     * "cover#Image", "cover.alt#print".
     */
    $fragments = [];
    $collect = function (Reading $owner, string $prefix, int $depth) use (&$collect, &$fragments, $catalog, $emit): void {
        foreach ($catalog->members($owner->shape) as $members) {
            foreach ($members->fields as $field) {
                $reading = $owner->field($field);
                $key = $prefix.$field->handle;
                foreach ($emit->inserts($reading, $field->label) as $insert) {
                    // Something to see inside a condition that has nothing in it yet.
                    $fragments[$key.'#'.$insert->title] = str_ends_with($insert->blade, ")\n@endif") && substr_count($insert->blade, "\n") === 1 ? str_replace("\n@endif", "\n[shown]\n@endif", $insert->blade) : $insert->blade;
                }
                if (($printed = $emit->printed($reading)) !== null) {
                    $fragments[$key.'#print'] = $printed->blade;
                }
                // Not on from an entry to the entries above it, except from the page to its entry.
                if ($depth > 0 && ! $reading->shape->isList() && (! in_array($reading->shape->kind, [Shape::ENTRY, Shape::TERM, Shape::TERM_TRANSLATION], true) || $prefix === 'page.')) {
                    $collect($reading, $key.'.', $depth - 1);
                }
            }
        }
    };
    $variable = fn (string $code, Shape $shape) => Reading::of(new Variable($code, $shape, Variable::PAGE, ''));
    // The page's own fields, then what every entry has (and its fields, as a list of entries reads them).
    $collect($variable('$values', Shape::values('collection', ['things'])), '', 2);
    $collect($variable('$entry', Shape::entry(['things'])), 'entry.', 1);
    $collect($variable('$collection', Shape::collection('things')), 'collection.', 2);
    $collect($variable("cms_global('site')", Shape::globals('site')), 'site.', 0);
    $collect($variable("cms_global('footer')", Shape::globals('footer')), 'footer.', 0);
    $collect($variable('cms_page()', Shape::of(Shape::PAGE)), 'page.', 1);
    $fragments['menu#Menu'] = $emit->inserts($variable("cms_menu('main')", Shape::listOf(Shape::of(Shape::MENU_ITEM))), 'Main menu')[0]->blade;
    $this->fragments = $fragments;

    $template = '';
    foreach ($fragments as $key => $blade) {
        $template .= '<section data-key="'.$key.'">'."\n".$blade."\n</section>\n";
    }
    @mkdir($this->templates.'/collections/things', recursive: true);
    file_put_contents($this->templates.'/collections/things/show.blade.php', $template);

    /** What each fragment drew on a page, by its key, with runs of spaces made one. */
    $this->drawn = function (string $path): array {
        $html = $this->get($path)->assertOk()->getContent();
        preg_match_all('/<section data-key="([^"]+)">(.*?)<\/section>/s', $html, $sections, PREG_SET_ORDER);

        // Pages and files are linked with the address of the site, which is the test's own: left out.
        $tidy = fn (string $drawn): string => trim(str_replace(rtrim(url('/'), '/'), '', (string) preg_replace('/\s+/', ' ', $drawn)));

        return array_combine(array_column($sections, 1), array_map(fn (array $section) => $tidy($section[2]), $sections));
    };
});

it('writes nothing the tree can not hold', function () {
    expect(count($this->fragments))->toBeGreaterThan(250);

    foreach ($this->fragments as $key => $blade) {
        expect(Template::open($blade)->print())->toBe($blade, $key);
        expect(fn () => Fragment::parse($blade))->not->toThrow(Throwable::class, $key);
    }
});

it('draws every field of a thing that has them all', function () {
    $drawn = ($this->drawn)('/things/everything');

    expect(array_keys($drawn))->toBe(array_keys($this->fragments));
    $expected = [
        // What every entry has.
        'entry.title#Text' => 'Everything',
        'entry.title#Only when it is set' => '[shown]',
        'entry.url#Text' => '/things/everything',
        'entry.published_at#Date' => now()->translatedFormat('j F Y'),
        'entry.published_at.year#print' => (string) now()->year,
        'entry.terms#Each one, in a loop' => '<a href="/things/topics/news">News</a>',   // its page in the collection
        'entry.slug#Text' => 'everything',
        // Each type of field.
        'summary#Text' => 'A thing with everything.',
        'summary#Only when it is set' => '[shown]',
        'notes#Text' => 'First line. Second line.',
        'body#Rich text' => '<p>The <strong>body</strong> of the thing.</p>',
        'price#Text' => '1250',
        'featured#Only when it is on' => '[shown]',
        'featured#Only when it is off' => '',
        'colour#Text' => 'blue',
        'sizes#All of them, with commas' => 's, l',
        'sizes#Each one, in a loop' => 's l',
        'sizes#Only when there are any' => '[shown]',
        'made_on#Date' => '14 March 2026',
        'made_on.short#print' => '14/03/2026',
        'made_on.year#print' => '2026',
        'made_on.machine#print' => '2026-03-14T00:00:00+00:00',
        'more#Link' => '<a href="https://example.com/more" target="_blank" rel="noopener" >More about it</a>',
        'more.url#print' => 'https://example.com/more',
        'more.label#print' => 'More about it',
        'more.new_tab#Only when it is on' => '[shown]',
        'cover#Image' => '<img src="/storage/media/cover.jpg?v=1" alt="Alt of cover">',
        'cover#Link to the file' => '<a href="/storage/media/cover.jpg?v=1">cover.jpg</a>',
        'cover#Only when it is set' => '[shown]',
        'cover.url:large#print' => '/storage/media/cover.jpg?v=1',
        'cover.alt#print' => 'Alt of cover',
        'photos#Each one, in a loop' => '<img src="/storage/media/photo-one.jpg?v=1" alt="Alt of photo-one"> <img src="/storage/media/photo-two.jpg?v=1" alt="Alt of photo-two">',
        'related#Each one, in a loop' => '<a href="/articles/first-post">First post</a> <a href="/articles/second-post">Second post</a>',
        'see_also#Each one, in a loop' => '<a href="/articles/third-post">Third post</a>',
        'topics#Each one, in a loop' => '<a href="/things/topics/news">News</a> <a href="/things/topics/tips">Tips</a>',
        'topics#Only when there are any' => '[shown]',
        'details.material#Text' => 'Oak',
        'details.weight#Text' => '12',
        'details.picture#Image' => '<img src="/storage/media/detail.jpg?v=1" alt="Alt of detail">',
        'details.picture.alt#print' => 'Alt of detail',
        'steps#Each one, in a loop' => '<div> Cut <p>Cut the wood.</p> <img src="/storage/media/saw.jpg?v=1" alt="Alt of saw"> </div> <div> Sand <p>Sand it down.</p> </div>',
        // An entry's fields, read as a list of entries reads them.
        'entry.summary#Text' => 'A thing with everything.',
        'entry.cover.alt#print' => 'Alt of cover',
        'entry.related#Each one, in a loop' => '<a href="/articles/first-post">First post</a> <a href="/articles/second-post">Second post</a>',
        // The collection, the site, the page.
        'collection.title#Text' => 'Things',
        'collection.listingUrl#Text' => '/things',
        'site.name#Text' => 'Sunrice Demo',
        'footer.text#Text' => 'Built with Sunrice CMS.',
        'page.entry.title#print' => 'Everything',
        'page.term.title#print' => '',
        'page.collection.title#print' => 'Things',
        'menu#Menu' => '<ul> <li><a href="">Home</a></li> <li><a href="/articles">Articles</a></li> <li><a href="/about">About</a></li> </ul>',
    ];
    foreach ($expected as $key => $html) {
        expect($drawn[$key] ?? 'NOT DRAWN')->toBe($html, $key);
    }

    // The blocks of the thing, each by its own template: the hero's, and for the one without a template the fallback.
    expect($drawn['sections#Each block, drawn by its own template'])->toContain('A hero in a thing')->toContain('Under it.');
});

it('draws a thing with nothing in it without a word, a tag or an error', function () {
    $drawn = ($this->drawn)('/things/nothing');

    // What is there whatever the fields hold.
    $there = [
        'entry.title#Text' => 'Nothing', 'entry.title#Only when it is set' => '[shown]', 'entry.title#print' => 'Nothing',
        'entry.url#Text' => '/things/nothing', 'entry.url#Only when it is set' => '[shown]', 'entry.url#print' => '/things/nothing',
        'entry.slug#Text' => 'nothing', 'entry.slug#Only when it is set' => '[shown]', 'entry.slug#print' => 'nothing',
        'entry.published_at#Date' => now()->translatedFormat('j F Y'), 'entry.published_at#Only when it is set' => '[shown]',
        // Off is what a switch nobody touched is.
        'featured#Only when it is off' => '[shown]', 'more.new_tab#Only when it is off' => '[shown]',
        'entry.featured#Only when it is off' => '[shown]', 'entry.more.new_tab#Only when it is off' => '[shown]',
    ];
    foreach ($drawn as $key => $html) {
        if (str_starts_with($key, 'entry.published_at.') || preg_match('/^(collection|site|footer|page|menu)[.#]/', $key) === 1) {
            continue;
        }
        expect($html)->toBe($there[$key] ?? '', $key);
    }

    expect($drawn['page.entry.title#print'])->toBe('Nothing')
        ->and($drawn['collection.title#Text'])->toBe('Things');
});

it('draws a page that is not a thing with a thing\'s fields in its template', function () {
    // The template two collections share reads fields only one of them has.
    file_put_contents($this->templates.'/collections/show.blade.php', (string) file_get_contents($this->templates.'/collections/things/show.blade.php'));
    unlink($this->templates.'/collections/things/show.blade.php');

    $about = ($this->drawn)('/about');
    expect($about['entry.title#Text'])->toBe('About')
        ->and($about['summary#Text'])->toBe('')
        ->and($about['entry.summary#Text'])->toBe('')
        ->and($about['cover#Image'])->toBe('')
        ->and($about['steps#Each one, in a loop'])->toBe('')
        ->and($about['more#Link'])->toBe('')
        ->and($about['details.material#Text'])->toBe('')
        ->and($about['body#Rich text'])->toBe('<p>A page with a rich text body and no blocks.</p>');   // a field both have
});

it('renders every menu insert for populated and empty menus and native arrays', function () {
    $emit = new Emit(new Catalog);
    foreach (['main', 'not-there'] as $handle) {
        $reading = Reading::of(new Variable("cms_menu('{$handle}')", Shape::listOf(Shape::of(Shape::MENU_ITEM)), Variable::SITE, 'Menu'));
        foreach ($emit->inserts($reading, 'Menu') as $insert) {
            $blade = str_replace("\n@endif", "\n[shown]\n@endif", $insert->blade);
            $html = Blade::render($blade);
            if ($insert->title === 'Only when there are any') {
                expect(trim($html))->toBe($handle === 'main' ? '[shown]' : '');
            }
        }
    }
    $reading = Reading::of(new Variable('$items', Shape::listOf(Shape::of(Shape::TEXT)), Variable::PAGE, 'Items'));
    $blade = '@if ('.$reading->asked().")\nyes\n@endif";
    foreach ([[], ['one'], collect(), collect(['one'])] as $items) {
        expect(trim(Blade::render($blade, ['items' => $items])))->toBe(count($items) > 0 ? 'yes' : '');
    }
});
