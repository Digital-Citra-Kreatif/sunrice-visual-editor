<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\Cms\Models\Blueprint;
use Sunrice\Cms\Models\Collection;
use Sunrice\Cms\Models\Entry;
use Sunrice\Cms\Models\EntryTranslation;
use Sunrice\Cms\Models\Taxonomy;
use Sunrice\VisualEditor\Support\SunriceBridge;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class);

it('finds the site\'s template and theme directories where the CMS has them', function () {
    expect(SunriceBridge::templateDirectory())->toBe(rtrim((string) config('view.paths')[0], '/').'/cms')
        ->and(SunriceBridge::themeDirectory())->toBe(public_path('cms'))
        ->and(SunriceBridge::themeUrl('site.css'))->toBe(asset('cms/site.css'));

    config(['sunrice-visual-editor.paths' => ['templates' => '/srv/site/views/cms/', 'theme' => '/srv/site/theme']]);

    expect(SunriceBridge::templateDirectory())->toBe('/srv/site/views/cms')
        ->and(SunriceBridge::themeDirectory())->toBe('/srv/site/theme');
});

it('finds the CMS\'s own views', function () {
    $directories = SunriceBridge::packageViewDirectories();

    expect($directories)->not->toBeEmpty()
        ->and($directories[0].'/entry.blade.php')->toBeFile()
        ->and($directories[0].'/components/seo.blade.php')->toBeFile();
});

it('says which view each kind of page uses, as the CMS chooses it', function () {
    $this->setUpStarterSite();
    $view = function (string $role, string $handle): ?string {
        $use = collect(SunriceBridge::templateMap())->first(fn (array $use) => $use['role'] === $role && $use['handle'] === $handle);

        return $use['view'] ?? null;
    };

    expect($view('entry', 'articles'))->toBe('cms.entry')
        ->and($view('entry', 'pages'))->toBe('cms.entry')
        ->and($view('listing', 'articles'))->toBe('cms.listing')
        ->and($view('listing', 'pages'))->toBeNull()   // pages have no listing page
        ->and($view('search', 'search'))->toBe('cms.search');

    // cms.{handle} comes before cms.entry; the collection's own template before both.
    File::put($this->templates.'/articles.blade.php', '<article>{{ $title }}</article>');
    File::put($this->templates.'/articles-listing.blade.php', '<main>{{ $title }}</main>');
    expect($view('entry', 'articles'))->toBe('cms.articles')
        ->and($view('listing', 'articles'))->toBe('cms.articles-listing');

    File::ensureDirectoryExists($this->templates.'/pages');
    File::put($this->templates.'/pages/wide.blade.php', '<main>{{ $title }}</main>');
    Collection::query()->where('handle', 'pages')->update(['template' => 'cms.pages.wide']);
    expect($view('entry', 'pages'))->toBe('cms.pages.wide');

    // A template set to a view that isn't there: the CMS goes on down the list.
    Collection::query()->where('handle', 'pages')->update(['template' => 'cms.pages.gone']);
    expect($view('entry', 'pages'))->toBe('cms.entry');

    // A taxonomy with term pages, and an entry with a template of its own.
    Taxonomy::query()->create(['handle' => 'topics', 'title' => 'Topics', 'route' => '/topics/{slug}']);
    File::put($this->templates.'/landing.blade.php', '<main>{{ $title }}</main>');
    Entry::query()->whereKey(EntryTranslation::query()->where('title', 'About')->value('entry_id'))->update(['template' => 'cms.landing']);
    $uses = collect(SunriceBridge::templateMap());
    expect($view('term', 'topics'))->toBe('cms.term')
        ->and($uses->where('view', 'cms.landing')->values()->all())->toBe([['view' => 'cms.landing', 'role' => 'entry', 'subject' => 'collection', 'handle' => 'pages']]);
});

it('reads field definitions with their nested fields', function () {
    $this->setUpStarterSite();

    $page = SunriceBridge::fields('collection', 'pages');
    expect(array_column($page, 'handle'))->toBe(['body', 'sections'])
        ->and(array_column($page, 'type'))->toBe(['rich_text', 'flexible'])
        ->and($page[1]['config']['blocks'])->toBe(['hero', 'text', 'gallery']);

    expect(array_column(SunriceBridge::fields('fieldset', 'hero'), 'handle'))->toBe(['heading', 'subheading', 'button'])
        ->and(array_column(SunriceBridge::fields('global', 'footer'), 'handle'))->toBe(['text'])
        ->and(SunriceBridge::fields('collection', 'articles')[0])->toBe(['handle' => 'excerpt', 'type' => 'textarea', 'label' => 'Excerpt']);
});

it('reads the fields of every blueprint a collection\'s entries may use', function () {
    $this->setUpStarterSite();
    Blueprint::query()->create(['handle' => 'video_post', 'title' => 'Video post', 'fields' => [
        ['handle' => 'excerpt', 'type' => 'textarea', 'label' => 'Excerpt'],
        ['handle' => 'video', 'type' => 'link', 'label' => 'Video'],
    ]]);
    Collection::query()->where('handle', 'articles')->update(['blueprints' => ['video_post']]);

    expect(array_column(SunriceBridge::fields('collection', 'articles'), 'handle'))->toBe(['excerpt', 'body', 'video']);
});

it('returns no fields for something that does not exist', function () {
    $this->setUpStarterSite();

    expect(SunriceBridge::fields('collection', 'nope'))->toBe([])
        ->and(SunriceBridge::fields('fieldset', 'nope'))->toBe([])
        ->and(SunriceBridge::fields('listing', 'pages'))->toBe([])
        ->and(SunriceBridge::fields('whatever', 'pages'))->toBe([]);
});

it('lists collections, taxonomies, fieldsets, globals and menus', function () {
    $this->setUpStarterSite();

    expect(SunriceBridge::collections())->toBe([
        ['handle' => 'articles', 'title' => 'Articles', 'single' => true],
        ['handle' => 'pages', 'title' => 'Pages', 'single' => true],
    ])->and(SunriceBridge::globals())->toBe([
        ['handle' => 'footer', 'title' => 'Footer'],
    ])->and(SunriceBridge::menus())->toBe([
        ['handle' => 'footer', 'title' => 'Footer menu'],
        ['handle' => 'main', 'title' => 'Main menu'],
    ])->and(SunriceBridge::fieldsets())->toBe([
        ['handle' => 'gallery', 'title' => 'Gallery'],
        ['handle' => 'hero', 'title' => 'Hero'],
        ['handle' => 'text', 'title' => 'Text'],
    ]);
});

it('names the built-in type a field type is made of', function () {
    expect(SunriceBridge::baseType('rich_text'))->toBe('rich_text')
        ->and(SunriceBridge::baseType('no_such_type'))->toBe('no_such_type');
});

it('links an entry to its page in the panel, in the language shown', function () {
    $this->setUpStarterSite();
    $about = EntryTranslation::query()->where('title', 'About')->firstOrFail();

    expect(SunriceBridge::entryLink($about))->toBe([
        'id' => $about->entry_id,
        'title' => 'About',
        'edit' => '/admin/cms/collections/pages/entries/'.$about->entry_id.'/edit?locale='.$about->locale,
    ])->and(SunriceBridge::entryLink('About'))->toBeNull()
        ->and(SunriceBridge::entryLink(null))->toBeNull();
});

it('keeps a copy of a file before it is changed, beside the CMS\'s own', function () {
    $this->setUpStarterSite();
    $file = $this->templates.'/partials/card.blade.php';
    $original = File::get($file);

    $copy = SunriceBridge::backup($file, 'partials/card.blade.php');
    File::put($file, 'changed');

    expect($copy)->toBeFile()
        ->and(File::get($copy))->toBe($original)
        ->and($copy)->toContain('/app/sunrice-cms/template-backups/')
        ->and($copy)->toEndWith('/cms/partials/card.blade.php');

    File::deleteDirectory(storage_path('app/sunrice-cms/template-backups'));
});
