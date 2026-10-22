<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Sunrice\Cms\Models\Fieldset;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Templates\TemplateRepository;
use Sunrice\VisualEditor\Tests\Support\WithEveryField;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\actingAs;

uses(WithStarterSite::class, WithEveryField::class);

beforeEach(function () {
    $this->setUpStarterSite();
    $this->setUpEveryField();
    actingAsSuperAdmin();

    /** The answer for the first node of a template whose own text has $marker in it. */
    $this->ask = function (string $path, ?string $marker = null, bool $inside = false, ?string $version = null) {
        $source = (string) file_get_contents($this->templates.'/'.$path);
        $node = null;
        foreach ($marker === null ? [] : Template::open($source)->root->walk() as $each) {
            if (str_contains($each->open, $marker)) {
                $node = $each->id();
                break;
            }
        }

        return $this->getJson(route('sunrice.admin.visual-editor.api.templates.fields', array_filter([
            'path' => $path,
            'version' => $version ?? TemplateRepository::version($source),
            'node' => $node,
            'inside' => $inside ? 1 : null,
        ], fn ($value) => $value !== null)));
    };
    /** One of the answer's variables, by how it is written. */
    $this->variable = fn ($response, string $code): array => collect($response->json('variables'))->firstOrFail(fn (array $variable) => $variable['code'] === $code);
    /** One of a variable's (or a field's) fields, by its handle, whatever heading it is under. */
    $this->field = fn (array $owner, string $handle): array => collect($owner['groups'])->flatMap(fn (array $group) => $group['fields'])->firstOrFail(fn (array $field) => $field['handle'] === $handle);
});

it('lists what can be picked in a block, with what a click writes', function () {
    $response = ($this->ask)('blocks/hero.blade.php', '<h1')->assertOk();

    expect(array_column($response->json('variables'), 'code'))->toBe(['$block', 'cms_page()', '$locale', "cms_global('footer')", "cms_menu('footer')", "cms_menu('main')"]);

    $block = ($this->variable)($response, '$block');
    expect($block['title'])->toBe('The Hero block being drawn')
        ->and($block['from'])->toBe('page')
        ->and($block['what'])->toBe('block')
        ->and($block['of'])->toBe(['Hero'])
        ->and(array_column($block['groups'], 'title'))->toBe(['Hero', 'Every block']);

    $heading = ($this->field)($block, 'heading');
    expect($heading)->toMatchArray([
        'label' => 'Heading',
        'code' => '$block->heading',
        'what' => 'text',
        'print' => '{{ $block->heading }}',
        'expression' => '$block->heading',
        'note' => null,
        'groups' => [],
    ])->and($heading['inserts'][0])->toBe(['title' => 'Text', 'kind' => 'print', 'blade' => '{{ $block->heading }}']);

    // A link: an element to insert, and its parts to print.
    $button = ($this->field)($block, 'button');
    expect($button['what'])->toBe('link')
        ->and($button['print'])->toBeNull()
        ->and($button['inserts'][0]['title'])->toBe('Link')
        ->and($button['inserts'][0]['blade'])->toStartWith('@if (($button = $block->button) && ! empty($button[\'url\']))')
        ->and(($this->field)($button, 'url')['print'])->toBe("{{ \$block->button['url'] ?? '' }}")
        ->and(($this->field)($button, 'url')['expression'])->toBe("\$block->button['url'] ?? ''");
});

it('lists a page\'s fields with those of each collection it may be of, and says which lack one', function () {
    $values = ($this->variable)(($this->ask)('entry.blade.php')->assertOk(), '$values');

    expect($values['of'])->toBe(['Articles', 'Pages', 'Things'])
        ->and(array_column($values['groups'], 'title'))->toBe(['Articles', 'Pages', 'Things'])
        ->and(($this->field)($values, 'summary')['note'])->toBe('Not in Articles, Pages')
        ->and(collect($values['groups'][0]['fields'])->firstWhere('handle', 'body')['note'])->toBeNull()       // all of them have a body
        ->and(($this->field)($values, 'summary')['print'])->toBe("{{ \$values['summary'] ?? '' }}");

    // The entry itself: what every entry has, then its fields read from it.
    $entry = ($this->variable)(($this->ask)('entry.blade.php')->assertOk(), '$entry');
    expect(array_column($entry['groups'], 'title'))->toBe(['Every entry', 'Articles', 'Pages', 'Things'])
        ->and(($this->field)($entry, 'title')['note'])->toBeNull()
        ->and(($this->field)($entry, 'excerpt')['print'])->toBe("{{ \$entry->values()['excerpt'] ?? '' }}");

    // One collection: nothing to say.
    file_put_contents($this->templates.'/articles.blade.php', '<h1>{{ $title }}</h1>');
    $article = ($this->variable)(($this->ask)('articles.blade.php')->assertOk(), '$values');
    expect(($this->field)($article, 'excerpt')['note'])->toBeNull()
        ->and($article['of'])->toBe(['Articles']);
});

it('reads on into a file, a group and a date, and stops at a list and at another entry', function () {
    $values = ($this->variable)(($this->ask)('entry.blade.php')->assertOk(), '$values');

    $cover = ($this->field)($values, 'cover');
    expect(array_column($cover['inserts'], 'title'))->toBe(['Image', 'Link to the file', 'Only when it is set'])
        ->and(($this->field)($cover, 'alt')['print'])->toBe("{{ (\$values['cover'] ?? null)?->altIn() }}")
        ->and(($this->field)(($this->field)(($this->field)($values, 'details'), 'picture'), 'alt')['print'])->toBe("{{ (\$values['details']['picture'] ?? null)?->altIn() }}")
        ->and(($this->field)(($this->field)($values, 'made_on'), 'year')['print'])->toBe("{{ (\$values['made_on'] ?? null)?->year }}");

    // A list is looped over, not read into; neither is an entry reached from an entry.
    expect(($this->field)($values, 'steps')['groups'])->toBe([])
        ->and(($this->field)($values, 'steps')['what'])->toBe('list of rows')
        ->and(($this->field)($values, 'related')['of'])->toBe(['Articles'])
        ->and(($this->field)($values, 'mentor')['groups'])->toBe([]);

    // The page's own entry is read into, once.
    $page = ($this->variable)(($this->ask)('layout.blade.php')->assertOk(), 'cms_page()');
    $pageEntry = ($this->field)($page, 'entry');
    expect(($this->field)($pageEntry, 'title')['print'])->toBe('{{ cms_page()?->entry?->title }}')
        ->and(($this->field)(($this->field)($pageEntry, 'cover'), 'alt')['print'])->toBe("{{ (cms_page()?->entry?->values()['cover'] ?? null)?->altIn() }}");
});

it('has a loop\'s item for what goes into the loop', function () {
    $beside = ($this->ask)('blocks/gallery.blade.php', '@foreach')->assertOk();
    $inside = ($this->ask)('blocks/gallery.blade.php', '@foreach', inside: true)->assertOk();

    expect(array_column($beside->json('variables'), 'code'))->not->toContain('$image')
        ->and(array_column($inside->json('variables'), 'code'))->toContain('$image');

    $image = ($this->variable)($inside, '$image');
    expect($image['from'])->toBe('loop')
        ->and($image['what'])->toBe('file')
        ->and($image['inserts'][0]['blade'])->toBe("<img src=\"{{ \$image->url('large') }}\" alt=\"{{ \$image->altIn() }}\">")
        ->and(($this->field)($image, 'alt')['print'])->toBe('{{ $image->altIn() }}');
});

it('gives a new variable no name the place already has', function () {
    // $image is set at the top of the hero: an image field inserted there is held in another.
    Fieldset::query()->where('handle', 'hero')->firstOrFail()->update(['fields' => [
        ['handle' => 'heading', 'type' => 'text', 'label' => 'Heading'],
        ['handle' => 'image', 'type' => 'media', 'label' => 'Image'],
    ]]);
    $hero = (string) file_get_contents($this->templates.'/blocks/hero.blade.php');
    file_put_contents($this->templates.'/blocks/hero.blade.php', str_replace('<div class="hero">', "@php(\$image = null)\n<div class=\"hero\">", $hero));
    $block = ($this->variable)(($this->ask)('blocks/hero.blade.php', '<h1')->assertOk(), '$block');

    expect(($this->field)($block, 'image')['inserts'][0]['blade'])->toStartWith('@if ($image2 = $block->image)');
});

it('lists globals and menus with what a click writes', function () {
    $response = ($this->ask)('layout.blade.php')->assertOk();

    $footer = ($this->variable)($response, "cms_global('footer')");
    expect($footer['title'])->toBe('Footer')
        ->and($footer['from'])->toBe('site')
        ->and(($this->field)($footer, 'text')['print'])->toBe("{{ cms_global('footer')['text'] ?? '' }}");

    $menu = ($this->variable)($response, "cms_menu('main')");
    expect($menu['what'])->toBe('list of menu items')
        ->and($menu['inserts'][0]['title'])->toBe('Menu')
        ->and($menu['groups'])->toBe([]);
});

it('refuses a place of another version, a node that is not there, and a template without a tree', function () {
    ($this->ask)('entry.blade.php', version: 'an-older-one')->assertStatus(409)->assertJsonPath('reason', 'conflict');

    $version = TemplateRepository::version((string) file_get_contents($this->templates.'/entry.blade.php'));
    $route = fn (array $more) => route('sunrice.admin.visual-editor.api.templates.fields', ['path' => 'entry.blade.php', ...$more]);
    $this->getJson($route(['version' => $version, 'node' => '999999']))->assertStatus(422)->assertJsonPath('reason', 'invalid');
    $this->getJson($route([]))->assertStatus(422)->assertJsonPath('reason', 'invalid');
    $this->getJson(route('sunrice.admin.visual-editor.api.templates.fields', ['path' => 'nope.blade.php', 'version' => 'x']))->assertStatus(404);

    // A block that ends inside a tag: no tree holds that.
    $source = (string) file_get_contents(__DIR__.'/../fixtures/edge/locked-block-closed-in-tag.blade.php');
    file_put_contents($this->templates.'/broken.blade.php', $source);
    $broken = TemplateRepository::version($source);
    $this->getJson(route('sunrice.admin.visual-editor.api.templates.fields', ['path' => 'broken.blade.php', 'version' => $broken]))->assertStatus(422)->assertJsonPath('reason', 'rejected');
});

it('is for those who may use the editor only', function () {
    auth()->logout();
    $this->actingAs(createUser(email: 'editor@example.com'));

    ($this->ask)('entry.blade.php')->assertStatus(403);
});

// --- At a place in text that is not saved: what the code editor asks ----------------

/** The answer for the place of "|" in a text for a template: the text is not the file's, and is not saved. */
function askAt(string $path, string $marked): TestResponse
{
    return test()->postJson(route('sunrice.admin.visual-editor.api.templates.fields-at', ['path' => $path]), [
        'source' => str_replace('|', '', $marked),
        'offset' => (int) strpos($marked, '|'),
    ]);
}

it('says what can be read at a place in text that is not saved, with what was typed above it', function () {
    $hero = (string) file_get_contents($this->templates.'/blocks/hero.blade.php');
    // A line typed above the heading, and the cursor after it.
    $typed = str_replace('    <h1', "    @php(\$kicker = \$block->heading)\n    |\n    <h1", $hero);

    $response = askAt('blocks/hero.blade.php', $typed)->assertOk()->assertJsonPath('exact', true);

    expect(array_column($response->json('variables'), 'code'))->toBe(['$kicker', '$block', 'cms_page()', '$locale', "cms_global('footer')", "cms_menu('footer')", "cms_menu('main')"])
        ->and(($this->variable)($response, '$kicker')['from'])->toBe('set')
        ->and(($this->field)(($this->variable)($response, '$block'), 'heading')['expression'])->toBe('$block->heading')
        // Nothing of it was written.
        ->and(file_get_contents($this->templates.'/blocks/hero.blade.php'))->toBe($hero);

    // Above that line it is not there yet.
    expect(array_column(askAt('blocks/hero.blade.php', str_replace('    @php($kicker', '    |@php($kicker', str_replace('|', '', $typed)))->json('variables'), 'code'))->not->toContain('$kicker');
});

it('has a loop\'s item inside a loop that was only just typed, and not beside it', function () {
    $loop = "<section>\n    @foreach (\$block->images ?? [] as \$photo)\n        INSIDE\n    @endforeach\n    AFTER\n</section>\n";

    $inside = askAt('blocks/gallery.blade.php', str_replace('INSIDE', '|', $loop))->assertOk();
    $after = askAt('blocks/gallery.blade.php', str_replace('AFTER', '|', $loop))->assertOk();
    $line = askAt('blocks/gallery.blade.php', str_replace('as \$photo', 'as |\$photo', $loop))->assertOk();

    expect(array_column($inside->json('variables'), 'code'))->toContain('$photo', '$block')
        ->and(($this->variable)($inside, '$photo')['from'])->toBe('loop')
        ->and(array_column($after->json('variables'), 'code'))->toContain('$block')->not->toContain('$photo')
        // In the loop's own line the item is not there yet either.
        ->and(array_column($line->json('variables'), 'code'))->not->toContain('$photo');
});

it('answers while something is only half typed', function (string $halfway) {
    $response = askAt('blocks/hero.blade.php', $halfway)->assertOk();

    expect(array_column($response->json('variables'), 'code'))->toContain('$block', 'cms_page()', "cms_global('footer')");
})->with([
    'a loop that is not closed yet' => ["<section>\n    @foreach (\$block->images as \$item)\n        |\n</section>\n"],
    'a condition half typed' => ["<section>\n    @if (\$block->heading |\n</section>\n"],
    'a tag half typed' => ["<section>\n    <div class=\"|\n</section>\n"],
    'an echo half typed' => ["<section>\n    {{ \$block->|\n</section>\n"],
]);

it('answers with what the file is given when the text does not open as a tree, and says it is no more', function () {
    // A block that ends inside a tag: no tree holds that.
    $source = (string) file_get_contents(__DIR__.'/../fixtures/edge/locked-block-closed-in-tag.blade.php');

    $response = askAt('blocks/hero.blade.php', $source.'|')->assertOk()->assertJsonPath('exact', false);

    expect($response->json('why'))->toBeString()->not->toBe('')
        ->and(array_column($response->json('variables'), 'code'))->toContain('$block', 'cms_page()', "cms_global('footer')");
});

it('is for those who may edit code, and reads only what it is sent', function () {
    $source = "<p>|</p>\n";
    askAt('blocks/nope.blade.php', $source)->assertNotFound();
    test()->postJson(route('sunrice.admin.visual-editor.api.templates.fields-at', ['path' => 'blocks/hero.blade.php']), ['source' => '<p></p>'])->assertStatus(422)->assertJsonPath('reason', 'invalid');
    test()->postJson(route('sunrice.admin.visual-editor.api.templates.fields-at', ['path' => 'blocks/hero.blade.php']), ['source' => '<p></p>', 'offset' => -1])->assertStatus(422);
    test()->postJson(route('sunrice.admin.visual-editor.api.templates.fields-at', ['path' => 'blocks/hero.blade.php']), ['source' => ['x'], 'offset' => 0])->assertStatus(422);
    // Past the end is the end.
    test()->postJson(route('sunrice.admin.visual-editor.api.templates.fields-at', ['path' => 'blocks/hero.blade.php']), ['source' => '<p></p>', 'offset' => 5000])->assertOk();

    foreach ([Access::OPEN, Access::EDIT] as $permission) {
        $user = createUser(email: $permission.'@example.com');
        foreach (['sunrice.access', $permission] as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name));
        }
        actingAs($user);
        askAt('blocks/hero.blade.php', $source)->assertForbidden()->assertJsonPath('reason', 'forbidden');
    }
});

it('says what a value is asked for and compared as in a condition, and gone over in a loop', function () {
    $response = ($this->ask)('entry.blade.php')->assertOk();
    $values = ($this->variable)($response, '$values');
    $entry = ($this->variable)($response, '$entry');

    expect(($this->field)($values, 'summary'))->toMatchArray(['asked' => "! empty(\$values['summary'])", 'compared' => "(\$values['summary'] ?? null)", 'looped' => null])
        ->and(($this->field)($values, 'photos'))->toMatchArray(['asked' => "collect(\$values['photos'] ?? [])->isNotEmpty()", 'looped' => "\$values['photos'] ?? []"])
        ->and(($this->field)(($this->field)($entry, 'entry'), 'terms'))->toMatchArray(['asked' => 'collect($entry->entry?->terms)->isNotEmpty()', 'looped' => '$entry->entry?->terms ?? []'])
        ->and(($this->field)($entry, 'title'))->toMatchArray(['asked' => '$entry->title', 'compared' => '$entry->title'])
        ->and(($this->field)(($this->field)($values, 'details'), 'material'))->toMatchArray(['asked' => "! empty(\$values['details']['material'])", 'compared' => "(\$values['details']['material'] ?? null)"]);
});

it('lists the site\'s collections with their fields, and its taxonomies', function () {
    $site = $this->getJson(route('sunrice.admin.visual-editor.api.site'))->assertOk();

    expect(array_column($site->json('collections'), 'title', 'handle'))->toBe(['articles' => 'Articles', 'pages' => 'Pages', 'things' => 'Things'])
        ->and($site->json('collections.0.single'))->toBeTrue()
        ->and($site->json('collections.0.fields'))->toBe([
            ['handle' => 'excerpt', 'label' => 'Excerpt', 'what' => 'text', 'kind' => 'text'],
            ['handle' => 'body', 'label' => 'Body', 'what' => 'rich text', 'kind' => 'html'],
        ])
        ->and($site->json('taxonomies'))->toBe([['handle' => 'topics', 'title' => 'Topics', 'archive' => true]]);

    auth()->logout();
    $this->actingAs(createUser(email: 'editor@example.com'));
    $this->getJson(route('sunrice.admin.visual-editor.api.site'))->assertStatus(403);
});
