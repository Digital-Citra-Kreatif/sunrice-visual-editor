<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\Admin\Activity\Activity as ActivityLog;
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

it('copies a fallback view to where the CMS looks first, and the site then uses the copy', function () {
    // The starter site has its own term template: take it away, so the CMS falls back.
    File::delete($this->templates.'/term.blade.php');
    $view = $this->repository->read('term.blade.php', 'package');

    $path = $this->copy->handle('term.blade.php');

    expect($path)->toBe('term.blade.php')
        ->and(File::get($this->templates.'/term.blade.php'))->toBe($view['source'])
        ->and($this->repository->read('term.blade.php'))->toMatchArray(['origin' => 'site', 'editable' => true, 'role' => 'term', 'source' => $view['source']]);
});

it('records the copy in the panel\'s activity log', function () {
    File::delete($this->templates.'/term.blade.php');

    $this->copy->handle('term.blade.php');

    $line = ActivityLog::query()->where('subject_type', 'template')->sole();
    expect($line)->toMatchArray(['action' => 'created'])
        ->and($line->subject_label)->toContain('a copy of the CMS\'s term.blade.php');
});

it('makes the site\'s template directory when there is none yet', function () {
    File::deleteDirectory($this->templates);

    expect($this->copy->handle('term.blade.php'))->toBe('term.blade.php')
        ->and($this->templates.'/term.blade.php')->toBeFile();
});

it('will not replace a template the site already has', function () {
    $mine = File::get($this->templates.'/entry.blade.php');

    expect(fn () => $this->copy->handle('entry.blade.php'))->toThrow(CopyRefused::class, 'already has "entry.blade.php"')
        ->and(File::get($this->templates.'/entry.blade.php'))->toBe($mine);
});

it('writes the copy in one step, and leaves nothing else behind', function () {
    File::delete($this->templates.'/term.blade.php');
    $before = collect(File::allFiles($this->templates, true))->map->getRelativePathname()->sort()->values()->all();

    $this->copy->handle('term.blade.php');

    // The template, and no file it was written through.
    expect(collect(File::allFiles($this->templates, true))->map->getRelativePathname()->sort()->values()->all())
        ->toBe(collect([...$before, 'term.blade.php'])->sort()->values()->all());
});

it('leaves no template when the copy can\'t be written, so it can be asked for again', function () {
    // The site's template directory can't be made: a file is in the way.
    File::deleteDirectory($this->templates);
    File::put($this->templates, '');

    expect(fn () => $this->copy->handle('term.blade.php'))->toThrow(RuntimeException::class, 'Could not make the directory');

    File::delete($this->templates);
    expect($this->copy->handle('term.blade.php'))->toBe('term.blade.php')
        ->and(File::get($this->templates.'/term.blade.php'))->toBe($this->repository->read('term.blade.php', 'package')['source']);
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
    'the CMS\'s mail' => ['mail/submission.blade.php'],
    'up and out' => ['../../composer.json'],
]);
