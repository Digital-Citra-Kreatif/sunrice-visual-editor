<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\Models\Collection;
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

    expect($site->count())->toBeGreaterThanOrEqual(19)
        // In order, by path.
        ->and($site->pluck('path')->all())->toBe($site->pluck('path')->sort()->values()->all())
        ->and($site->pluck('role', 'path')->all())->toMatchArray([
            'articles/show.blade.php' => 'entry',
            'blocks/default.blade.php' => 'block',
            'blocks/form.blade.php' => 'block',
            'blocks/hero.blade.php' => 'block',
            'index.blade.php' => 'listing',
            'layouts/app.blade.php' => 'layout',
            'partials/card.blade.php' => 'partial',
            'partials/footer.blade.php' => 'partial',
            'partials/header.blade.php' => 'partial',
            'search.blade.php' => 'search',
            'show.blade.php' => 'entry',
            'taxonomies/show.blade.php' => 'term',
        ])
        ->and($site->pluck('view', 'path')->all())->toMatchArray([
            'articles/show.blade.php' => 'sunrice.articles.show',
            'partials/header.blade.php' => 'sunrice.partials.header',
            'show.blade.php' => 'sunrice.show',
        ])
        ->and($site->every(fn (array $template) => $template['editable'] && $template['copyTo'] === null))->toBeTrue()
        // Every starter template opens as a tree, with nothing stray in it.
        ->and($site->every(fn (array $template) => $template['opens'] && $template['reason'] === null && $template['strays'] === 0))->toBeTrue();
});

it('says which collections a template draws pages for', function () {
    $usedBy = collect($this->repository->all())->where('origin', 'site')->pluck('usedBy', 'path');

    expect($usedBy['articles/show.blade.php'])->toBe([['role' => 'entry', 'subject' => 'collection', 'handle' => 'articles']])
        ->and($usedBy['show.blade.php'])->toBe([['role' => 'entry', 'subject' => 'collection', 'handle' => 'pages']])
        ->and($usedBy['index.blade.php'])->toBe([['role' => 'listing', 'subject' => 'collection', 'handle' => 'articles']])
        ->and($usedBy['partials/header.blade.php'])->toBe([])
        ->and($usedBy['taxonomies/show.blade.php'])->toBe([]);
});

it('takes a template\'s role from the pages that use it when its place doesn\'t say', function () {
    // A collection told to use a template of its own, in a folder Sunrice knows nothing about.
    File::ensureDirectoryExists($this->templates.'/landing');
    File::put($this->templates.'/landing/wide.blade.php', '<main>{{ $entry->title }}</main>');
    File::put($this->templates.'/notes.blade.php', '<p>Not used by anything.</p>');
    $pages = Collection::query()->where('handle', 'pages')->firstOrFail();
    $pages->update(['settings' => ['template' => 'sunrice.landing.wide'] + $pages->settings]);

    $site = collect($this->repository->all())->where('origin', 'site')->keyBy('path');

    expect($site['landing/wide.blade.php'])->toMatchArray(['role' => 'entry', 'view' => 'sunrice.landing.wide', 'usedBy' => [['role' => 'entry', 'subject' => 'collection', 'handle' => 'pages']]])
        ->and($site['notes.blade.php'])->toMatchArray(['role' => 'other', 'usedBy' => []])
        // Still named the way Sunrice looks for it, though nothing uses it now.
        ->and($site['show.blade.php'])->toMatchArray(['role' => 'entry', 'usedBy' => []]);
});

it('lists Sunrice\'s own views read-only, with where a copy would go', function () {
    $package = collect($this->repository->all())->where('origin', 'package')->keyBy('path');

    expect($package['defaults/show.blade.php'])->toMatchArray(['view' => 'sunrice::defaults.show', 'role' => 'entry', 'editable' => false, 'copyTo' => 'show.blade.php', 'opens' => true])
        ->and($package['defaults/index.blade.php'])->toMatchArray(['role' => 'listing', 'copyTo' => 'index.blade.php'])
        ->and($package['defaults/term.blade.php'])->toMatchArray(['role' => 'term', 'copyTo' => 'taxonomies/show.blade.php'])
        ->and($package['defaults/search.blade.php'])->toMatchArray(['role' => 'search', 'copyTo' => 'search.blade.php'])
        // Sunrice looks for these nowhere in the site: there is nowhere for a copy to go.
        ->and($package['defaults/draft-banner.blade.php'])->toMatchArray(['role' => 'partial', 'copyTo' => null])
        ->and($package['components/form.blade.php'])->toMatchArray(['view' => 'sunrice::components.form', 'role' => 'component', 'editable' => false, 'copyTo' => null])
        // The admin's page and mail are not templates of the site.
        ->and($package->keys()->filter(fn (string $path) => ! preg_match('#^(defaults|components|errors)/#', $path))->all())->toBe([]);
});

it('reads a template: its source, the version of that source, and its tree', function () {
    $file = $this->templates.'/partials/header.blade.php';
    $read = $this->repository->read('partials/header.blade.php');

    expect($read)->toMatchArray([
        'path' => 'partials/header.blade.php',
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
    expect($this->repository->read('partials/header.blade.php')['version'])->not->toBe($read['version']);
});

it('reads one of Sunrice\'s own views', function () {
    $read = $this->repository->read('defaults/show.blade.php', 'package');

    expect($read)->toMatchArray(['origin' => 'package', 'view' => 'sunrice::defaults.show', 'editable' => false, 'opens' => true])
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

it('finds nothing outside the directories it lists', function (string $path, string $origin) {
    // Something real to be tempted by, next to the template directory.
    File::put(dirname($this->templates).'/secret.blade.php', 'secret');

    expect(fn () => $this->repository->read($path, $origin))->toThrow(TemplateNotFound::class);
})->with([
    'a template that isn\'t there' => ['partials/nope.blade.php', 'site'],
    'up and out' => ['../secret.blade.php', 'site'],
    'up and back in' => ['partials/../show.blade.php', 'site'],
    'an absolute path' => ['/etc/passwd', 'site'],
    'a file that is no template' => ['.env', 'site'],
    'a PHP file' => ['partials/header.php', 'site'],
    'nothing' => ['', 'site'],
    'a package view asked for as the site\'s' => ['defaults/show.blade.php', 'site'],
    'a site template asked for as Sunrice\'s' => ['partials/header.blade.php', 'package'],
    'the admin\'s page' => ['app.blade.php', 'package'],
    'somewhere else altogether' => ['show.blade.php', 'elsewhere'],
]);
