<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\Cms\Models\EntryTranslation;
use Sunrice\VisualEditor\Support\SunriceBridge;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class);

it('finds the site\'s template and theme directories', function () {
    expect(SunriceBridge::templateDirectory())->toBe(resource_path('views/cms'))
        ->and(SunriceBridge::themeDirectory())->toBe(public_path('cms'))
        ->and(SunriceBridge::themeFolder())->toBe('cms')
        ->and(SunriceBridge::themeUrl('site.css'))->toBe(asset('cms/site.css'));

    config(['sunrice-visual-editor.paths' => ['templates' => '/srv/site/views/cms/', 'theme' => '/srv/site/theme', 'theme_url' => null]]);

    expect(SunriceBridge::templateDirectory())->toBe('/srv/site/views/cms')
        ->and(SunriceBridge::themeDirectory())->toBe('/srv/site/theme')
        // Not under public/: linked from where the CMS's own is.
        ->and(SunriceBridge::themeFolder())->toBe('cms');

    config(['sunrice-visual-editor.paths.theme_url' => '/assets/theme/']);
    expect(SunriceBridge::themeUrl('site.css'))->toBe(asset('assets/theme/site.css'));
});

it('finds the CMS\'s own views', function () {
    $directories = SunriceBridge::packageViewDirectories();

    expect($directories)->not->toBeEmpty()
        ->and($directories[0].'/entry.blade.php')->toBeFile()
        ->and($directories[0].'/components/seo.blade.php')->toBeFile();
});

it('names the views that draw the site\'s pages', function () {
    expect(SunriceBridge::pageViews())->toBe(['cms.*', 'pages.*', 'sunrice-cms::entry', 'sunrice-cms::listing', 'sunrice-cms::term', 'sunrice-cms::taxonomy-index', 'sunrice-cms::search']);
});

it('says which view each collection uses', function () {
    $this->setUpStarterSite();
    File::ensureDirectoryExists($this->templates.'/collections/articles');
    File::put($this->templates.'/collections/articles/show.blade.php', '<article>{{ $title }}</article>');

    $map = collect(SunriceBridge::templateMap());
    $view = fn (string $role, string $handle) => $map->first(fn (array $use) => $use['role'] === $role && $use['handle'] === $handle)['view'] ?? null;

    expect($view('entry', 'articles'))->toBe('cms.collections.articles.show')   // the collection's own template
        ->and($view('entry', 'pages'))->toBe('cms.collections.show')            // the shared one
        ->and($view('listing', 'articles'))->toBe('cms.collections.index')
        ->and($view('listing', 'pages'))->toBeNull()                            // pages have no listing page
        ->and($view('search', 'search'))->toBe('cms.search');
});

it('reads field definitions with their nested fields', function () {
    $this->setUpStarterSite();

    $page = SunriceBridge::fields('collection', 'pages');
    expect(array_column($page, 'handle'))->toBe(['body', 'sections'])
        ->and(array_column($page, 'type'))->toBe(['rich_text', 'flexible'])
        ->and($page[1]['config']['blocks'])->toBe(['hero', 'text', 'gallery']);

    expect(array_column(SunriceBridge::fields('fieldset', 'hero'), 'handle'))->toBe(['heading', 'subheading', 'button'])
        ->and(array_column(SunriceBridge::fields('global', 'site'), 'handle'))->toBe(['name'])
        ->and(SunriceBridge::fields('collection', 'articles')[0])->toBe(['handle' => 'excerpt', 'type' => 'textarea', 'label' => 'Excerpt']);
});

it('returns no fields for something that does not exist', function () {
    $this->setUpStarterSite();

    expect(SunriceBridge::fields('collection', 'nope'))->toBe([])
        ->and(SunriceBridge::fields('fieldset', 'nope'))->toBe([])
        ->and(SunriceBridge::fields('listing', 'pages'))->toBe([])
        ->and(SunriceBridge::fields('whatever', 'pages'))->toBe([]);
});

it('lists collections, globals and menus', function () {
    $this->setUpStarterSite();

    expect(SunriceBridge::collections())->toBe([
        ['handle' => 'articles', 'title' => 'Articles', 'single' => true],
        ['handle' => 'pages', 'title' => 'Pages', 'single' => true],
    ])->and(SunriceBridge::globals())->toBe([
        ['handle' => 'footer', 'title' => 'Footer'],
        ['handle' => 'site', 'title' => 'Site'],
    ])->and(SunriceBridge::menus())->toBe([
        ['handle' => 'main', 'title' => 'Main menu'],
    ])->and(SunriceBridge::fieldsets())->toBe([
        ['handle' => 'gallery', 'title' => 'Gallery'],
        ['handle' => 'hero', 'title' => 'Hero'],
        ['handle' => 'text', 'title' => 'Text'],
    ]);
});

it('finds the site\'s pages by their titles, for the command palette', function () {
    $this->setUpStarterSite();

    expect(SunriceBridge::pages('post', 2))->toHaveCount(2)
        ->and(SunriceBridge::pages('about'))->toHaveCount(1)
        ->and(SunriceBridge::pages('about')[0])->toMatchArray(['title' => 'About', 'collection' => 'Pages', 'path' => '/about'])
        ->and(SunriceBridge::pages('nothing like it'))->toBe([])
        // A % is a percent sign, not "anything".
        ->and(SunriceBridge::pages('%'))->toBe([]);
});

it('names the built-in type a field type is made of', function () {
    expect(SunriceBridge::baseType('rich_text'))->toBe('rich_text')
        ->and(SunriceBridge::baseType('no_such_type'))->toBe('no_such_type');
});

it('links the entry a page shows to its page in the admin', function () {
    $this->setUpStarterSite();
    $about = EntryTranslation::query()->where('slug', 'about')->sole();

    expect(SunriceBridge::entryLink($about))->toBe(['id' => $about->entry_id, 'title' => 'About', 'edit' => '/admin/cms/entries/'.$about->entry_id.'/edit?locale=en'])
        ->and(SunriceBridge::entryLink($about->entry)['edit'])->toBe('/admin/cms/entries/'.$about->entry_id.'/edit')
        ->and(SunriceBridge::entryLink('about'))->toBeNull();
});

it('keeps a copy of a file before it is changed', function () {
    $this->setUpStarterSite();
    $file = $this->templates.'/partials/card.blade.php';
    $original = File::get($file);

    $copy = SunriceBridge::backup($file, 'partials/card.blade.php');
    File::put($file, 'changed');

    expect($copy)->toBeFile()
        ->and(File::get($copy))->toBe($original)
        ->and($copy)->toContain('/app/sunrice-cms/template-backups/')
        ->and($copy)->toEndWith('/cms/partials/card.blade.php')
        ->and(glob(SunriceBridge::backups('*', 'partials/card.blade.php')))->toBe([$copy]);

    File::deleteDirectory(storage_path('app/sunrice-cms/template-backups'));
});
