<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\Admin\Activity\Activity;
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

it('copies one of the CMS\'s page views to the site\'s template the CMS looks for before it, and the site then uses the copy', function () {
    // The starter site has its own taxonomies/show: take it away, so the CMS falls back.
    File::delete($this->templates.'/taxonomies/show.blade.php');
    $view = $this->repository->read('term.blade.php', 'package');

    $path = $this->copy->handle('term.blade.php');

    expect($path)->toBe('taxonomies/show.blade.php')
        ->and(File::get($this->templates.'/taxonomies/show.blade.php'))->toBe($view['source'])
        ->and($this->repository->read('taxonomies/show.blade.php'))->toMatchArray(['origin' => 'site', 'editable' => true, 'role' => 'term', 'source' => $view['source']]);
});

it('records the copy in the panel\'s activity log', function () {
    File::delete($this->templates.'/taxonomies/show.blade.php');

    $this->copy->handle('term.blade.php');

    $line = Activity::query()->where('subject_type', 'template')->sole();
    expect($line)->toMatchArray(['action' => 'created', 'subject_label' => 'taxonomies/show.blade.php'])
        ->and($line->properties['note'])->toContain('term.blade.php');
});

it('copies every page view the CMS draws without a template of the site', function () {
    File::deleteDirectory($this->templates.'/collections');
    File::deleteDirectory($this->templates.'/taxonomies');
    File::delete($this->templates.'/search.blade.php');

    expect(array_map(fn (string $view) => $this->copy->handle($view), ['entry.blade.php', 'listing.blade.php', 'term.blade.php', 'taxonomy-index.blade.php', 'search.blade.php']))
        ->toBe(['collections/show.blade.php', 'collections/index.blade.php', 'taxonomies/show.blade.php', 'taxonomies/index.blade.php', 'search.blade.php']);
    // The copies draw the pages: an entry's, from the CMS's plain view.
    $this->get('/about')->assertOk()->assertSee('A page with a rich text body and no blocks.', false);
});

it('makes the folder the copy goes in', function () {
    File::deleteDirectory($this->templates.'/taxonomies');

    expect($this->copy->handle('term.blade.php'))->toBe('taxonomies/show.blade.php')
        ->and($this->templates.'/taxonomies/show.blade.php')->toBeFile();
});

it('will not replace a template the site already has', function () {
    $mine = File::get($this->templates.'/collections/show.blade.php');

    expect(fn () => $this->copy->handle('entry.blade.php'))->toThrow(CopyRefused::class, 'already has "collections/show.blade.php"')
        ->and(File::get($this->templates.'/collections/show.blade.php'))->toBe($mine);
});

it('writes the copy in one step, and leaves nothing else behind', function () {
    File::delete($this->templates.'/taxonomies/show.blade.php');
    $before = collect(File::allFiles($this->templates, true))->map->getRelativePathname()->sort()->values()->all();

    $this->copy->handle('term.blade.php');

    // The template, and no file it was written through.
    expect(collect(File::allFiles($this->templates, true))->map->getRelativePathname()->sort()->values()->all())
        ->toBe(collect([...$before, 'taxonomies/show.blade.php'])->sort()->values()->all());
});

it('leaves no template when the copy can\'t be written, so it can be asked for again', function () {
    File::delete($this->templates.'/taxonomies/show.blade.php');
    // Nothing can be written beside the template: its folder is gone and a file is in the way.
    File::deleteDirectory($this->templates.'/taxonomies');
    File::put($this->templates.'/taxonomies', '');

    expect(fn () => $this->copy->handle('term.blade.php'))->toThrow(RuntimeException::class, 'Could not make the directory');

    File::delete($this->templates.'/taxonomies');
    expect($this->copy->handle('term.blade.php'))->toBe('taxonomies/show.blade.php')
        ->and(File::get($this->templates.'/taxonomies/show.blade.php'))->toBe($this->repository->read('term.blade.php', 'package')['source']);
});

it('will not copy a view the site has no place for', function (string $path) {
    $before = collect(File::allFiles($this->templates))->map->getRelativePathname()->sort()->values()->all();

    expect(fn () => $this->copy->handle($path))->toThrow(CopyRefused::class, 'would never be used')
        ->and(collect(File::allFiles($this->templates))->map->getRelativePathname()->sort()->values()->all())->toBe($before);
})->with([
    ['components/form.blade.php'],
    ['draft-banner.blade.php'],
]);

it('copies only the CMS\'s own views', function (string $path) {
    expect(fn () => $this->copy->handle($path))->toThrow(TemplateNotFound::class);
})->with([
    'a template of the site' => ['partials/card.blade.php'],
    'something that isn\'t there' => ['nope.blade.php'],
    'its mail' => ['mail/comment-posted.blade.php'],
    'up and out' => ['../../composer.json'],
]);
