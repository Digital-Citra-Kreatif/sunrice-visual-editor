<?php

declare(strict_types=1);

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
                if ($depth > 0 && ! $reading->shape->isList() && (! in_array($reading->shape->kind, [Shape::ENTRY, Shape::TERM], true) || $prefix === 'page.')) {
                    $collect($reading, $key.'.', $depth - 1);
                }
            }
        }
    };
    $variable = fn (string $code, Shape $shape) => Reading::of(new Variable($code, $shape, Variable::PAGE, ''));
    $collect($variable('$entry', Shape::entry(['things'])), '', 2);
    $collect($variable('$collection', Shape::collection('things')), 'collection.', 2);
    $collect($variable("sunrice_global('site')", Shape::globals('site')), 'site.', 0);
    $collect($variable("sunrice_global('footer')", Shape::globals('footer')), 'footer.', 0);
    $collect($variable('$sunricePage', Shape::of(Shape::PAGE)), 'page.', 1);
    $fragments['menu#Menu'] = $emit->inserts($variable("sunrice_menu('main')", Shape::listOf(Shape::of(Shape::MENU_ITEM))), 'Main menu')[0]->blade;
    $this->fragments = $fragments;

    $template = '';
    foreach ($fragments as $key => $blade) {
        $template .= '<section data-key="'.$key.'">'."\n".$blade."\n</section>\n";
    }
    @mkdir($this->templates.'/things');
    file_put_contents($this->templates.'/things/show.blade.php', $template);

    /** What each fragment drew on a page, by its key, with runs of spaces made one. */
    $this->drawn = function (string $path): array {
        $html = $this->get($path)->assertOk()->getContent();
        preg_match_all('/<section data-key="([^"]+)">(.*?)<\/section>/s', $html, $sections, PREG_SET_ORDER);

        // Files are linked with the address of the site, which is the test's own: left out.
        $tidy = fn (string $drawn): string => trim((string) preg_replace(['/\s+/', '#https?://[^/"]+/storage/#'], [' ', '/storage/'], $drawn));

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
        'title#Text' => 'Everything',
        'title#Only when it is set' => '[shown]',
        'url#Text' => '/things/everything',
        'published_at#Date' => now()->translatedFormat('j F Y'),
        'published_at.year#print' => (string) now()->year,
        'author#Name' => '',                                              // no one is signed in as its author
        'terms#Each one, in a loop' => '<a href="/topics/news">News</a>',
        'slug#Text' => 'everything',
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
        'cover#Image' => '<img src="/storage/sunrice/cover.jpg?v=1" alt="Alt of cover">',
        'cover#Link to the file' => '<a href="/storage/sunrice/cover.jpg?v=1">cover.jpg</a>',
        'cover#Only when it is set' => '[shown]',
        'cover.url:large#print' => '/storage/sunrice/cover.jpg?v=1',
        'cover.alt#print' => 'Alt of cover',
        'cover.width#print' => '800',
        'photos#Each one, in a loop' => '<img src="/storage/sunrice/photo-one.jpg?v=1" alt="Alt of photo-one"> <img src="/storage/sunrice/photo-two.jpg?v=1" alt="Alt of photo-two">',
        'related#Each one, in a loop' => '<a href="/articles/first-post">First post</a> <a href="/articles/second-post">Second post</a>',
        'see_also#Each one, in a loop' => '<a href="/articles/third-post">Third post</a>',
        'topics#Each one, in a loop' => '<a href="/topics/news">News</a> <a href="/topics/tips">Tips</a>',
        'topics#Only when there are any' => '[shown]',
        'details.material#Text' => 'Oak',
        'details.weight#Text' => '12',
        'details.picture#Image' => '<img src="/storage/sunrice/detail.jpg?v=1" alt="Alt of detail">',
        'details.picture.alt#print' => 'Alt of detail',
        'steps#Each one, in a loop' => '<div> Cut <p>Cut the wood.</p> <img src="/storage/sunrice/saw.jpg?v=1" alt="Alt of saw"> </div> <div> Sand <p>Sand it down.</p> </div>',
        // The collection, the site, the page.
        'collection.title#Text' => 'Things',
        'site.name#Text' => 'Sunrice Demo',
        'footer.text#Rich text' => '<p>Built with Sunrice CMS.</p>',
        'page.entry.title#print' => 'Everything',
        'page.term.name#print' => '',
        'page.collection.title#print' => 'Things',
        'menu#Menu' => '<ul> <li><a href="/">Home</a></li> <li><a href="/articles">Articles</a></li> <li><a href="/about">About</a></li> </ul>',
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
        'title#Text' => 'Nothing', 'title#Only when it is set' => '[shown]', 'title#print' => 'Nothing',
        'url#Text' => '/things/nothing', 'url#Only when it is set' => '[shown]', 'url#print' => '/things/nothing',
        'slug#Text' => 'nothing', 'slug#Only when it is set' => '[shown]', 'slug#print' => 'nothing',
        'published_at#Date' => now()->translatedFormat('j F Y'), 'published_at#Only when it is set' => '[shown]',
        // Off is what a switch nobody touched is.
        'featured#Only when it is off' => '[shown]', 'more.new_tab#Only when it is off' => '[shown]',
    ];
    foreach ($drawn as $key => $html) {
        if (str_starts_with($key, 'published_at.') || preg_match('/^(collection|site|footer|page|menu)[.#]/', $key) === 1) {
            continue;
        }
        expect($html)->toBe($there[$key] ?? '', $key);
    }

    expect($drawn['page.entry.title#print'])->toBe('Nothing')
        ->and($drawn['collection.title#Text'])->toBe('Things');
});

it('draws a page that is not a thing with a thing\'s fields in its template', function () {
    // The template two collections share reads fields only one of them has.
    file_put_contents($this->templates.'/show.blade.php', (string) file_get_contents($this->templates.'/things/show.blade.php'));
    unlink($this->templates.'/things/show.blade.php');

    $about = ($this->drawn)('/about');
    expect($about['title#Text'])->toBe('About')
        ->and($about['summary#Text'])->toBe('')
        ->and($about['cover#Image'])->toBe('')
        ->and($about['steps#Each one, in a loop'])->toBe('')
        ->and($about['more#Link'])->toBe('')
        ->and($about['details.material#Text'])->toBe('')
        ->and($about['body#Rich text'])->toBe('<p>A page with a rich text body and no blocks.</p>');   // a field both have
});
