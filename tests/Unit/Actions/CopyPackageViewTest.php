<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\VisualEditor\Actions\CopyPackageView;
use Sunrice\VisualEditor\Templates\CopyRefused;
use Sunrice\VisualEditor\Templates\TemplateNotFound;
use Sunrice\VisualEditor\Templates\TemplateRepository;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class);

beforeEach(function () {
    $this->setUpStarterSite();
    $this->copy = app(CopyPackageView::class);
    $this->repository = app(TemplateRepository::class);
});

it('copies a fallback view to where Sunrice looks first, and the site then uses the copy', function () {
    // The starter site has its own taxonomies/show: take it away, so Sunrice falls back.
    File::delete($this->templates.'/taxonomies/show.blade.php');
    $view = $this->repository->read('defaults/term.blade.php', 'package');

    $path = $this->copy->handle('defaults/term.blade.php');

    expect($path)->toBe('taxonomies/show.blade.php')
        ->and(File::get($this->templates.'/taxonomies/show.blade.php'))->toBe($view['source'])
        ->and($this->repository->read('taxonomies/show.blade.php'))->toMatchArray(['origin' => 'site', 'editable' => true, 'role' => 'term', 'source' => $view['source']]);
});

it('makes the folder the copy goes in', function () {
    File::deleteDirectory($this->templates.'/taxonomies');

    expect($this->copy->handle('defaults/term.blade.php'))->toBe('taxonomies/show.blade.php')
        ->and($this->templates.'/taxonomies/show.blade.php')->toBeFile();
});

it('will not replace a template the site already has', function () {
    $mine = File::get($this->templates.'/show.blade.php');

    expect(fn () => $this->copy->handle('defaults/show.blade.php'))->toThrow(CopyRefused::class, 'already has "show.blade.php"')
        ->and(File::get($this->templates.'/show.blade.php'))->toBe($mine);
});

it('will not copy a view the site has no place for', function (string $path) {
    $before = collect(File::allFiles($this->templates))->map->getRelativePathname()->sort()->values()->all();

    expect(fn () => $this->copy->handle($path))->toThrow(CopyRefused::class, 'would never be used')
        ->and(collect(File::allFiles($this->templates))->map->getRelativePathname()->sort()->values()->all())->toBe($before);
})->with([
    ['components/form.blade.php'],
    ['defaults/draft-banner.blade.php'],
]);

it('copies only Sunrice\'s own views', function (string $path) {
    expect(fn () => $this->copy->handle($path))->toThrow(TemplateNotFound::class);
})->with([
    'a template of the site' => ['partials/header.blade.php'],
    'something that isn\'t there' => ['defaults/nope.blade.php'],
    'the admin\'s page' => ['app.blade.php'],
    'up and out' => ['../../composer.json'],
]);
