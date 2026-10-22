<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\Cms\Models\Collection;
use Sunrice\VisualEditor\Templates\TemplateNotFound;
use Sunrice\VisualEditor\Templates\TemplateRepository;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class);

beforeEach(function () {
    $this->setUpStarterSite();
    $this->repository = app(TemplateRepository::class);
});

it('lists the site\'s templates with what each one is for', function () {
    $site = collect($this->repository->all())->where('origin', 'site')->values();

    expect($site->count())->toBeGreaterThanOrEqual(15)
        // In order, by path.
        ->and($site->pluck('path')->all())->toBe($site->pluck('path')->sort()->values()->all())
        ->and($site->pluck('role', 'path')->all())->toMatchArray([
            'blocks/hero.blade.php' => 'block',
            'blocks/text.blade.php' => 'block',
            'entry.blade.php' => 'entry',
            'layout.blade.php' => 'layout',
            'listing.blade.php' => 'listing',
            'partials/card.blade.php' => 'partial',
            'partials/menu.blade.php' => 'partial',
            'search.blade.php' => 'search',
            'term.blade.php' => 'term',
        ])
        ->and($site->pluck('view', 'path')->all())->toMatchArray([
            'entry.blade.php' => 'cms.entry',
            'partials/card.blade.php' => 'cms.partials.card',
            'blocks/hero.blade.php' => 'cms.blocks.hero',
        ])
        ->and($site->every(fn (array $template) => $template['editable'] && $template['copyTo'] === null))->toBeTrue()
        // Every starter template opens as a tree, with nothing stray in it.
        ->and($site->filter(fn (array $template) => ! $template['opens'] || $template['reason'] !== null || $template['strays'] !== 0)->pluck('path')->all())->toBe([]);
});

it('says which collections a template draws pages for', function () {
    $usedBy = collect($this->repository->all())->where('origin', 'site')->pluck('usedBy', 'path');

    expect($usedBy['entry.blade.php'])->toBe([
        ['role' => 'entry', 'subject' => 'collection', 'handle' => 'articles'],
        ['role' => 'entry', 'subject' => 'collection', 'handle' => 'pages'],
    ])
        ->and($usedBy['listing.blade.php'])->toBe([['role' => 'listing', 'subject' => 'collection', 'handle' => 'articles']])
        ->and($usedBy['search.blade.php'])->toBe([['role' => 'search', 'subject' => 'site', 'handle' => 'search']])
        ->and($usedBy['partials/card.blade.php'])->toBe([])
        ->and($usedBy['term.blade.php'])->toBe([]);
});

it('takes a template\'s role from the pages that use it when its place doesn\'t say', function () {
    // A collection told to use a template of its own, in a folder the CMS knows nothing about.
    File::ensureDirectoryExists($this->templates.'/landing');
    File::put($this->templates.'/landing/wide.blade.php', '<main>{{ $entry->title }}</main>');
    File::put($this->templates.'/notes.blade.php', '<p>Not used by anything.</p>');
    Collection::query()->where('handle', 'pages')->update(['template' => 'cms.landing.wide']);

    $site = collect($this->repository->all())->where('origin', 'site')->keyBy('path');

    expect($site['landing/wide.blade.php'])->toMatchArray(['role' => 'entry', 'view' => 'cms.landing.wide', 'usedBy' => [['role' => 'entry', 'subject' => 'collection', 'handle' => 'pages']]])
        ->and($site['notes.blade.php'])->toMatchArray(['role' => 'other', 'usedBy' => []]);
});

it('lists the CMS\'s own views read-only, with where a copy would go', function () {
    $package = collect($this->repository->all())->where('origin', 'package')->keyBy('path');

    expect($package['entry.blade.php'])->toMatchArray(['view' => 'sunrice-cms::entry', 'role' => 'entry', 'editable' => false, 'copyTo' => 'entry.blade.php', 'opens' => true])
        ->and($package['listing.blade.php'])->toMatchArray(['role' => 'listing', 'copyTo' => 'listing.blade.php'])
        ->and($package['term.blade.php'])->toMatchArray(['role' => 'term', 'copyTo' => 'term.blade.php'])
        ->and($package['search.blade.php'])->toMatchArray(['role' => 'search', 'copyTo' => 'search.blade.php'])
        // The CMS looks for these nowhere in the site: there is nowhere for a copy to go.
        ->and($package['draft-banner.blade.php'])->toMatchArray(['copyTo' => null])
        ->and($package['components/form.blade.php'])->toMatchArray(['view' => 'sunrice-cms::components.form', 'role' => 'component', 'editable' => false, 'copyTo' => null])
        ->and($package['comments/thread.blade.php'])->toMatchArray(['role' => 'partial', 'copyTo' => null])
        // Mail is not a template of the site.
        ->and($package->keys()->filter(fn (string $path) => str_starts_with($path, 'mail/'))->all())->toBe([]);
});

it('reads a template: its source, the version of that source, and its tree', function () {
    $file = $this->templates.'/partials/card.blade.php';
    $read = $this->repository->read('partials/card.blade.php');

    expect($read)->toMatchArray([
        'path' => 'partials/card.blade.php',
        'origin' => 'site',
        'role' => 'partial',
        'editable' => true,
        'opens' => true,
        'reason' => null,
        'source' => File::get($file),
        'version' => sha1(File::get($file)),
    ])
        ->and($read['tree'])->toMatchArray(['id' => 'root', 'type' => 'root'])
        ->and($read['tree']['children'])->not->toBeEmpty()
        ->and(TemplateRepository::version(File::get($file)))->toBe($read['version']);

    // The version follows the bytes.
    File::append($file, "\n");
    expect($this->repository->read('partials/card.blade.php')['version'])->not->toBe($read['version']);
});

it('reads one of the CMS\'s own views', function () {
    $read = $this->repository->read('entry.blade.php', 'package');

    expect($read)->toMatchArray(['origin' => 'package', 'view' => 'sunrice-cms::entry', 'editable' => false, 'opens' => true])
        ->and($read['source'])->toContain('$entry')
        ->and($read['tree']['type'])->toBe('root');
});

it('says when a template only opens as code, and when it has tags that don\'t match up', function () {
    File::put($this->templates.'/partials/broken.blade.php', "<p>Text</p>\n<?php \$a = 1;");
    File::put($this->templates.'/partials/top.blade.php', '<body class="a"><main><p>Text</p>');

    $site = collect($this->repository->all())->where('origin', 'site')->keyBy('path');
    expect($site['partials/broken.blade.php'])->toMatchArray(['opens' => false, 'strays' => 0])
        ->and($site['partials/broken.blade.php']['reason'])->toContain('Blade syntax error')
        ->and($site['partials/top.blade.php'])->toMatchArray(['opens' => true, 'reason' => null, 'strays' => 2]);

    $broken = $this->repository->read('partials/broken.blade.php');
    expect($broken['opens'])->toBeFalse()
        ->and($broken['tree'])->toBeNull()
        ->and($broken['source'])->toBe("<p>Text</p>\n<?php \$a = 1;")
        ->and($broken['version'])->toBe(sha1("<p>Text</p>\n<?php \$a = 1;"));
});

it('leaves a file that is not UTF-8 to a code editor', function () {
    File::put($this->templates.'/partials/latin.blade.php', "<p>Caf\xE9</p>");

    $read = $this->repository->read('partials/latin.blade.php');

    expect($read)->toMatchArray(['opens' => false, 'source' => null, 'tree' => null, 'version' => sha1("<p>Caf\xE9</p>")])
        ->and($read['reason'])->toContain('not UTF-8')
        ->and(json_encode($this->repository->all()))->toBeString();
});

it('finds nothing outside the directories it lists', function (string $path, string $origin) {
    // Something real to be tempted by, next to the template directory.
    File::put(dirname($this->templates).'/secret.blade.php', 'secret');

    expect(fn () => $this->repository->read($path, $origin))->toThrow(TemplateNotFound::class);
})->with([
    'a template that isn\'t there' => ['partials/nope.blade.php', 'site'],
    'up and out' => ['../secret.blade.php', 'site'],
    'up and back in' => ['partials/../entry.blade.php', 'site'],
    'an absolute path' => ['/etc/passwd', 'site'],
    'a file that is no template' => ['.env', 'site'],
    'a PHP file' => ['partials/header.php', 'site'],
    'nothing' => ['', 'site'],
    'a package view asked for as the site\'s' => ['draft-banner.blade.php', 'site'],
    'a site template asked for as Sunrice\'s' => ['partials/card.blade.php', 'package'],
    'the CMS\'s mail' => ['mail/submission.blade.php', 'package'],
    'somewhere else altogether' => ['entry.blade.php', 'elsewhere'],
]);
