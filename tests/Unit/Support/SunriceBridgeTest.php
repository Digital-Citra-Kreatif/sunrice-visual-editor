<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\VisualEditor\Support\SunriceBridge;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class);

it('finds the site\'s template and theme directories', function () {
    expect(SunriceBridge::templateDirectory())->toBe(resource_path('views/sunrice'))
        ->and(SunriceBridge::themeDirectory())->toBe(public_path('sunrice-theme'));

    config(['sunrice-visual-editor.paths' => ['templates' => '/srv/site/views/sunrice/', 'theme' => '/srv/site/theme']]);

    expect(SunriceBridge::templateDirectory())->toBe('/srv/site/views/sunrice')
        ->and(SunriceBridge::themeDirectory())->toBe('/srv/site/theme');
});

it('finds Sunrice\'s own views', function () {
    $directories = SunriceBridge::packageViewDirectories();

    expect($directories)->not->toBeEmpty()
        ->and($directories[0].'/defaults/show.blade.php')->toBeFile()
        ->and($directories[0].'/components/seo.blade.php')->toBeFile();
});

it('says which view each collection uses', function () {
    $this->setUpStarterSite();

    $map = collect(SunriceBridge::templateMap());
    $view = fn (string $role, string $handle) => $map->first(fn (array $use) => $use['role'] === $role && $use['handle'] === $handle)['view'] ?? null;

    expect($view('entry', 'articles'))->toBe('sunrice.articles.show')   // the collection's own template
        ->and($view('entry', 'pages'))->toBe('sunrice.show')            // the shared one
        ->and($view('listing', 'articles'))->toBe('sunrice.index')
        ->and($view('listing', 'pages'))->toBeNull();                   // pages have no listing page
});

it('reads field definitions with their nested fields', function () {
    $this->setUpStarterSite();

    $page = SunriceBridge::fields('collection', 'pages');
    expect(array_column($page, 'handle'))->toBe(['body', 'sections'])
        ->and(array_column($page, 'type'))->toBe(['rich_text', 'flexible'])
        ->and($page[1]['config']['fieldsets'])->toBe(['hero', 'text', 'gallery']);

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
        ['handle' => 'footer', 'title' => 'Footer', 'group' => 'template_part'],
        ['handle' => 'site', 'title' => 'Site', 'group' => 'global'],
    ])->and(SunriceBridge::menus())->toBe([
        ['handle' => 'main', 'title' => 'Main menu'],
    ]);
});

it('keeps a copy of a file before it is changed', function () {
    $this->setUpStarterSite();
    $file = $this->templates.'/partials/header.blade.php';
    $original = File::get($file);

    $copy = SunriceBridge::backup($file, 'partials/header.blade.php');
    File::put($file, 'changed');

    expect($copy)->toBeFile()
        ->and(File::get($copy))->toBe($original)
        ->and($copy)->toContain('/app/sunrice/template-backups/')
        ->and($copy)->toEndWith('/sunrice/partials/header.blade.php');

    File::deleteDirectory(storage_path('app/sunrice/template-backups'));
});
