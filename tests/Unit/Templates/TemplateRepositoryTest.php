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

    expect($site->count())->toBeGreaterThanOrEqual(16)
        // In order, by path.
        ->and($site->pluck('path')->all())->toBe($site->pluck('path')->sort()->values()->all())
        ->and($site->pluck('role', 'path')->all())->toMatchArray([
            'blocks/hero.blade.php' => 'block',
            'collections/index.blade.php' => 'listing',
            'collections/show.blade.php' => 'entry',
            'layout.blade.php' => 'layout',
            'partials/block.blade.php' => 'partial',
            'partials/card.blade.php' => 'partial',
            'search.blade.php' => 'search',
            'taxonomies/index.blade.php' => 'index',
            'taxonomies/show.blade.php' => 'term',
        ])
        ->and($site->pluck('view', 'path')->all())->toMatchArray([
            'collections/show.blade.php' => 'cms.collections.show',
            'partials/card.blade.php' => 'cms.partials.card',
            'layout.blade.php' => 'cms.layout',
        ])
        ->and($site->every(fn (array $template) => $template['editable'] && $template['copyTo'] === null))->toBeTrue()
        // Every starter template opens as a tree, with nothing stray in it.
        ->and($site->every(fn (array $template) => $template['opens'] && $template['reason'] === null && $template['strays'] === 0))->toBeTrue();
});

it('names templates by where the CMS looks for them', function () {
    foreach (['collections/articles/show', 'collections/articles/about', 'collections/articles/topics/index', 'collections/articles/topics/show', 'taxonomies/topics/index', 'taxonomies/topics/news', 'cms/partials/x'] as $path) {
        File::ensureDirectoryExists(dirname($this->templates.'/'.$path));
        File::put($this->templates.'/'.$path.'.blade.php', '<p>x</p>');
    }

    expect(collect($this->repository->all())->where('origin', 'site')->pluck('role', 'path')->all())->toMatchArray([
        'collections/articles/show.blade.php' => 'entry',
        // An entry of its own, by its slug.
        'collections/articles/about.blade.php' => 'entry',
        // A taxonomy's pages in one collection.
        'collections/articles/topics/index.blade.php' => 'index',
        'collections/articles/topics/show.blade.php' => 'term',
        'taxonomies/topics/index.blade.php' => 'index',
        'taxonomies/topics/news.blade.php' => 'term',
        // A partials folder anywhere holds partials.
        'cms/partials/x.blade.php' => 'partial',
    ]);
});

it('says which collections a template draws pages for', function () {
    $usedBy = collect($this->repository->all())->where('origin', 'site')->pluck('usedBy', 'path');

    expect($usedBy['collections/show.blade.php'])->toBe([
        ['role' => 'entry', 'subject' => 'collection', 'handle' => 'articles'],
        ['role' => 'entry', 'subject' => 'collection', 'handle' => 'pages'],
    ])
        ->and($usedBy['collections/index.blade.php'])->toBe([['role' => 'listing', 'subject' => 'collection', 'handle' => 'articles']])
        ->and($usedBy['search.blade.php'])->toBe([['role' => 'search', 'subject' => 'site', 'handle' => 'search']])
        ->and($usedBy['partials/card.blade.php'])->toBe([])
        // The demo site has no taxonomy.
        ->and($usedBy['taxonomies/show.blade.php'])->toBe([]);
});

it('takes a template\'s role from the pages that use it when its place doesn\'t say', function () {
    // A collection told to use a template of its own, in a folder the CMS knows nothing about.
    File::ensureDirectoryExists($this->templates.'/landing');
    File::put($this->templates.'/landing/wide.blade.php', '<main>{{ $title }}</main>');
    File::put($this->templates.'/notes.blade.php', '<p>Not used by anything.</p>');
    Collection::query()->where('handle', 'pages')->firstOrFail()->update(['template' => 'cms.landing.wide']);

    $site = collect($this->repository->all())->where('origin', 'site')->keyBy('path');

    expect($site['landing/wide.blade.php'])->toMatchArray(['role' => 'entry', 'view' => 'cms.landing.wide', 'usedBy' => [['role' => 'entry', 'subject' => 'collection', 'handle' => 'pages']]])
        ->and($site['notes.blade.php'])->toMatchArray(['role' => 'other', 'usedBy' => []])
        // Still named the way the CMS looks for it, for the articles now only.
        ->and($site['collections/show.blade.php'])->toMatchArray(['role' => 'entry', 'usedBy' => [['role' => 'entry', 'subject' => 'collection', 'handle' => 'articles']]]);
});

it('lists the CMS\'s own views read-only, with where a copy would go', function () {
    $package = collect($this->repository->all())->where('origin', 'package')->keyBy('path');

    expect($package['entry.blade.php'])->toMatchArray(['view' => 'sunrice-cms::entry', 'role' => 'entry', 'editable' => false, 'copyTo' => 'collections/show.blade.php', 'opens' => true])
        ->and($package['listing.blade.php'])->toMatchArray(['role' => 'listing', 'copyTo' => 'collections/index.blade.php'])
        ->and($package['term.blade.php'])->toMatchArray(['role' => 'term', 'copyTo' => 'taxonomies/show.blade.php'])
        ->and($package['taxonomy-index.blade.php'])->toMatchArray(['role' => 'index', 'copyTo' => 'taxonomies/index.blade.php'])
        ->and($package['search.blade.php'])->toMatchArray(['role' => 'search', 'copyTo' => 'search.blade.php'])
        // The CMS looks for these nowhere in the site: there is nowhere for a copy to go.
        ->and($package['draft-banner.blade.php'])->toMatchArray(['role' => 'partial', 'copyTo' => null])
        ->and($package['components/form.blade.php'])->toMatchArray(['view' => 'sunrice-cms::components.form', 'role' => 'component', 'editable' => false, 'copyTo' => null])
        // Its mail and the page a visitor unsubscribes from comments on are not templates of the site.
        ->and($package->keys()->filter(fn (string $path) => str_starts_with($path, 'mail/') || $path === 'comment-unsubscribe.blade.php')->all())->toBe([]);
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
        ->and($read['source'])->toContain('$values')
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
    'up and back in' => ['partials/../layout.blade.php', 'site'],
    'an absolute path' => ['/etc/passwd', 'site'],
    'a file that is no template' => ['.env', 'site'],
    'a PHP file' => ['partials/card.php', 'site'],
    'nothing' => ['', 'site'],
    'a package view asked for as the site\'s' => ['entry.blade.php', 'site'],
    'a site template asked for as the CMS\'s' => ['partials/card.blade.php', 'package'],
    'its mail' => ['mail/comment-posted.blade.php', 'package'],
    'somewhere else altogether' => ['layout.blade.php', 'elsewhere'],
]);
