<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Sunrice\Admin\Activity\Activity as ActivityLog;
use Sunrice\VisualEditor\Actions\SaveTemplate;
use Sunrice\VisualEditor\Templates\Conflict;
use Sunrice\VisualEditor\Templates\DoesNotCompile;
use Sunrice\VisualEditor\Templates\TemplateNotFound;
use Sunrice\VisualEditor\Templates\TemplateRepository;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class);

beforeEach(function () {
    $this->setUpStarterSite();
    $this->save = app(SaveTemplate::class);
    $this->file = $this->templates.'/partials/pagination.blade.php';
    $this->original = File::get($this->file);
    $this->backups = storage_path('app/sunrice-cms/template-backups');
    File::deleteDirectory($this->backups);
});

afterEach(fn () => File::deleteDirectory($this->backups));

/** Every backup of a template, oldest first. */
function backupsOf(string $directory, string $path): array
{
    $found = glob($directory.'/*/cms/'.$path) ?: [];
    sort($found);

    return $found;
}

it('writes the new source and says which version the file has now', function () {
    $new = $this->original."\n<p class=\"note\">{{ \$note }}</p>\n";

    $version = $this->save->handle('partials/pagination.blade.php', sha1($this->original), $new);

    expect(File::get($this->file))->toBe($new)
        ->and($version)->toBe(sha1($new))
        ->and($version)->toBe(TemplateRepository::version($new))
        // Nothing is left beside the file.
        ->and(glob(dirname($this->file).'/.*.tmp'))->toBe([]);
});

it('drops Laravel\'s compiled copy, so the next page is drawn from the new file', function () {
    $blade = app('blade.compiler');
    $source = View::getFinder()->find('cms.partials.pagination');
    $blade->compile($source);
    $compiled = $blade->getCompiledPath($source);
    expect($compiled)->toBeFile();

    $this->save->handle('partials/pagination.blade.php', sha1($this->original), $this->original.'<p>sve-compiled-test</p>');

    // Gone, whether or not Laravel would have looked at the file's time.
    expect($compiled)->not->toBeFile()
        ->and($blade->isExpired($source))->toBeTrue();

    // A save that changes nothing leaves it alone.
    $blade->compile($source);
    $this->save->handle('partials/pagination.blade.php', sha1($this->original.'<p>sve-compiled-test</p>'), $this->original.'<p>sve-compiled-test</p>');
    expect($compiled)->toBeFile();
    @unlink($compiled);
});

it('keeps the file readable as it was', function () {
    chmod($this->file, 0640);

    $this->save->handle('partials/pagination.blade.php', sha1($this->original), $this->original.'<p>x</p>');

    clearstatcache();
    expect(fileperms($this->file) & 0777)->toBe(0640);
});

it('keeps the previous version as a backup', function () {
    $second = $this->original.'<p>one</p>';
    $third = $this->original.'<p>two</p>';

    $this->save->handle('partials/pagination.blade.php', sha1($this->original), $second);
    // Straight after, within the same second: each save still has a backup of its own.
    $this->save->handle('partials/pagination.blade.php', sha1($second), $third);

    $backups = backupsOf($this->backups, 'partials/pagination.blade.php');
    expect($backups)->toHaveCount(2)
        ->and(array_map(fn (string $backup) => File::get($backup), $backups))->toEqualCanonicalizing([$this->original, $second]);
});

it('keeps no backup when backups are off', function () {
    config(['sunrice-visual-editor.backups' => false]);

    $this->save->handle('partials/pagination.blade.php', sha1($this->original), $this->original.'<p>x</p>');

    expect(backupsOf($this->backups, 'partials/pagination.blade.php'))->toBe([]);
});

it('does nothing when the source is what the file already holds', function () {
    $modified = filemtime($this->file);

    expect($this->save->handle('partials/pagination.blade.php', sha1($this->original), $this->original))->toBe(sha1($this->original))
        ->and(backupsOf($this->backups, 'partials/pagination.blade.php'))->toBe([]);

    clearstatcache();
    expect(filemtime($this->file))->toBe($modified);
});

it('refuses when the file has changed since it was read', function () {
    $version = sha1($this->original);
    // Meanwhile, in a code editor:
    File::put($this->file, $this->original."\n{{-- edited elsewhere --}}\n");
    $theirs = File::get($this->file);

    try {
        $this->save->handle('partials/pagination.blade.php', $version, $this->original.'<p>mine</p>');
        $thrown = null;
    } catch (Conflict $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(Conflict::class)
        ->and($thrown->path)->toBe('partials/pagination.blade.php')
        ->and($thrown->current)->toBe(sha1($theirs))
        // Their version is untouched, and nothing was backed up.
        ->and(File::get($this->file))->toBe($theirs)
        ->and(backupsOf($this->backups, 'partials/pagination.blade.php'))->toBe([]);
});

it('lets only the first of two saves made from the same version through', function () {
    $version = sha1($this->original);

    $this->save->handle('partials/pagination.blade.php', $version, $this->original.'<p>first</p>');

    expect(fn () => $this->save->handle('partials/pagination.blade.php', $version, $this->original.'<p>second</p>'))->toThrow(Conflict::class)
        ->and(File::get($this->file))->toBe($this->original.'<p>first</p>');
});

it('refuses a source that doesn\'t compile, and writes nothing', function (string $source, string $reason) {
    expect(fn () => $this->save->handle('partials/pagination.blade.php', sha1($this->original), $source))->toThrow(DoesNotCompile::class, $reason)
        ->and(File::get($this->file))->toBe($this->original)
        ->and(backupsOf($this->backups, 'partials/pagination.blade.php'))->toBe([])
        ->and(glob(dirname($this->file).'/.*.tmp'))->toBe([]);
})->with([
    'a condition never closed' => ['@if ($a) <p>x</p>', 'syntax error'],
    'PHP that is no PHP' => ['<p>{{ $a +* }}</p>', 'syntax error'],
    'a component the site doesn\'t have' => ['<x-sve-missing />', 'sve-missing'],
]);

it('saves a template that names a missing component when it named it before', function () {
    $old = '<x-sve-missing /><p>Old</p>';
    File::put($this->file, $old);

    $this->save->handle('partials/pagination.blade.php', sha1($old), '<x-sve-missing /><p>New</p>');

    expect(File::get($this->file))->toBe('<x-sve-missing /><p>New</p>')
        ->and(fn () => $this->save->handle('partials/pagination.blade.php', sha1('<x-sve-missing /><p>New</p>'), '<x-sve-missing />@if ($a)'))->toThrow(DoesNotCompile::class);
});

it('saves code the tree can\'t hold, as long as it compiles', function () {
    // From the code panel: an @if closed inside a tag. Blade compiles it; the tree refuses it.
    $source = '@if ($wide) <div class="wide" @endif><p>x</p></div>';

    expect($this->save->handle('partials/pagination.blade.php', sha1($this->original), $source))->toBe(sha1($source))
        ->and(File::get($this->file))->toBe($source);
});

it('writes nowhere but to a template of the site', function (string $path) {
    $outside = dirname($this->templates).'/outside.blade.php';
    File::put($outside, 'outside');

    expect(fn () => $this->save->handle($path, sha1('outside'), '<p>x</p>'))->toThrow(TemplateNotFound::class)
        ->and(File::get($outside))->toBe('outside');
})->with([
    'up and out' => ['../outside.blade.php'],
    'up and back in' => ['partials/../../outside.blade.php'],
    'an absolute path' => ['/etc/hosts'],
    'a template that isn\'t there' => ['partials/new.blade.php'],
    'one of the CMS\'s own views' => ['draft-banner.blade.php'],
    'a file that is no template' => ['partials/pagination.php'],
]);

it('records the save in Sunrice\'s activity log, and nothing when nothing was written', function () {
    $admin = actingAsSuperAdmin();
    $lines = fn () => ActivityLog::query()->where('subject_type', 'template')->get();

    // Refused, or nothing to do: no line.
    $this->save->handle('partials/pagination.blade.php', sha1($this->original), $this->original);
    rescue(fn () => $this->save->handle('partials/pagination.blade.php', sha1($this->original), '@if ($a)'), report: false);
    rescue(fn () => $this->save->handle('partials/pagination.blade.php', 'another version', '<p>x</p>'), report: false);
    expect($lines())->toHaveCount(0);

    $this->save->handle('partials/pagination.blade.php', sha1($this->original), $this->original.'<p>x</p>');

    expect($lines())->toHaveCount(1)
        ->and($lines()->first())->toMatchArray([
            'action' => 'updated',
            'subject_type' => 'template',
            'subject_label' => 'partials/pagination.blade.php',
            'user_id' => $admin->getKey(),
            'properties' => ['note' => 'Visual editor'],
        ]);
});

it('saves all the same when the activity log is off', function () {
    config(['sunrice-admin.activity.enabled' => false]);

    $this->save->handle('partials/pagination.blade.php', sha1($this->original), $this->original.'<p>x</p>');

    expect(File::get($this->file))->toBe($this->original.'<p>x</p>')
        ->and(ActivityLog::query()->where('subject_type', 'template')->count())->toBe(0);
});

it('shows the new version to the next page that is rendered', function () {
    $this->get('/articles')->assertOk()->assertDontSee('Saved by the editor');

    $new = $this->original."\n<p>Saved by the editor</p>\n";
    $this->save->handle('partials/pagination.blade.php', sha1($this->original), $new);

    $this->get('/articles')->assertOk()->assertSee('Saved by the editor');
});
