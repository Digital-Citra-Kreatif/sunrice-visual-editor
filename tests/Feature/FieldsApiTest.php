<?php

declare(strict_types=1);

use Sunrice\Models\Fieldset;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Templates\TemplateRepository;
use Sunrice\VisualEditor\Tests\Support\WithEveryField;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

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
    $response = ($this->ask)('blocks/hero.blade.php', '<h1>')->assertOk();

    expect(array_column($response->json('variables'), 'code'))->toBe(['$image', '$block', '$sunricePage', '$locale', "sunrice_global('footer')", "sunrice_global('site')", "sunrice_menu('main')"]);

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

it('lists a page\'s entry with the fields of each collection it may be of, and says which lack one', function () {
    $entry = ($this->variable)(($this->ask)('show.blade.php')->assertOk(), '$entry');

    expect($entry['of'])->toBe(['Pages', 'Things'])
        ->and(array_column($entry['groups'], 'title'))->toBe(['Every entry', 'Pages', 'Things'])
        ->and(($this->field)($entry, 'title')['note'])->toBeNull()
        ->and(($this->field)($entry, 'summary')['note'])->toBe('Not in Pages')
        ->and(collect($entry['groups'][1]['fields'])->firstWhere('handle', 'body')['note'])->toBeNull()       // both have a body
        ->and(collect($entry['groups'][1]['fields'])->firstWhere('handle', 'sections')['note'])->toBeNull();

    // One collection: nothing to say.
    $article = ($this->variable)(($this->ask)('articles/show.blade.php')->assertOk(), '$entry');
    expect(($this->field)($article, 'excerpt')['note'])->toBeNull();
});

it('reads on into a file, a group and a date, and stops at a list and at another entry', function () {
    $entry = ($this->variable)(($this->ask)('show.blade.php')->assertOk(), '$entry');

    $cover = ($this->field)($entry, 'cover');
    expect(array_column($cover['inserts'], 'title'))->toBe(['Image', 'Link to the file', 'Only when it is set'])
        ->and(($this->field)($cover, 'alt')['print'])->toBe("{{ \$entry->get('cover')?->alt }}")
        ->and(($this->field)(($this->field)(($this->field)($entry, 'details'), 'picture'), 'alt')['print'])->toBe("{{ (\$entry->get('details')['picture'] ?? null)?->alt }}")
        ->and(($this->field)(($this->field)($entry, 'made_on'), 'year')['print'])->toBe("{{ \$entry->get('made_on')?->year }}")
        ->and(($this->field)(($this->field)($entry, 'author'), 'name')['print'])->toBe('{{ $entry->author?->name }}');

    // A list is looped over, not read into; neither is an entry reached from an entry.
    expect(($this->field)($entry, 'steps')['groups'])->toBe([])
        ->and(($this->field)($entry, 'steps')['what'])->toBe('list of rows')
        ->and(($this->field)($entry, 'related')['of'])->toBe(['Articles']);

    // The page's own entry is read into, once.
    $page = ($this->variable)(($this->ask)('partials/header.blade.php')->assertOk(), '$sunricePage');
    $pageEntry = ($this->field)($page, 'entry');
    expect(($this->field)($pageEntry, 'title')['print'])->toBe('{{ $sunricePage->entry?->title }}')
        ->and(($this->field)(($this->field)($pageEntry, 'cover'), 'alt')['print'])->toBe("{{ \$sunricePage->entry?->get('cover')?->alt }}");
});

it('has a loop\'s item for what goes into the loop', function () {
    $beside = ($this->ask)('blocks/gallery.blade.php', '@foreach')->assertOk();
    $inside = ($this->ask)('blocks/gallery.blade.php', '@foreach', inside: true)->assertOk();

    expect(array_column($beside->json('variables'), 'code'))->not->toContain('$image')
        ->and(array_column($inside->json('variables'), 'code'))->toContain('$image');

    $image = ($this->variable)($inside, '$image');
    expect($image['from'])->toBe('loop')
        ->and($image['what'])->toBe('file')
        ->and($image['inserts'][0]['blade'])->toBe("<img src=\"{{ \$image->url('large') }}\" alt=\"{{ \$image->alt }}\">")
        ->and(($this->field)($image, 'alt')['print'])->toBe('{{ $image->alt }}');
});

it('gives a new variable no name the place already has', function () {
    // $image is set at the top of the hero: an image field inserted there is held in another.
    Fieldset::query()->where('handle', 'hero')->firstOrFail()->update(['fields' => [
        ['handle' => 'heading', 'type' => 'text', 'label' => 'Heading'],
        ['handle' => 'image', 'type' => 'asset', 'label' => 'Image'],
    ]]);
    $block = ($this->variable)(($this->ask)('blocks/hero.blade.php', '<h1>')->assertOk(), '$block');

    expect(($this->field)($block, 'image')['inserts'][0]['blade'])->toStartWith('@if ($image2 = $block->image)');
});

it('lists globals and menus with what a click writes', function () {
    $response = ($this->ask)('partials/header.blade.php')->assertOk();

    $site = ($this->variable)($response, "sunrice_global('site')");
    expect($site['title'])->toBe('Site')
        ->and($site['from'])->toBe('site')
        ->and(($this->field)($site, 'name')['print'])->toBe("{{ sunrice_global('site')->name }}");

    $menu = ($this->variable)($response, "sunrice_menu('main')");
    expect($menu['what'])->toBe('list of menu items')
        ->and($menu['inserts'][0]['title'])->toBe('Menu')
        ->and($menu['groups'])->toBe([]);
});

it('refuses a place of another version, a node that is not there, and a template without a tree', function () {
    ($this->ask)('show.blade.php', version: 'an-older-one')->assertStatus(409)->assertJsonPath('reason', 'conflict');

    $version = TemplateRepository::version((string) file_get_contents($this->templates.'/show.blade.php'));
    $route = fn (array $more) => route('sunrice.admin.visual-editor.api.templates.fields', ['path' => 'show.blade.php', ...$more]);
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
    $this->actingAs(createUser(email: 'editor@example.com'), config('sunrice.auth.guard', 'web'));

    ($this->ask)('show.blade.php')->assertStatus(403);
});
