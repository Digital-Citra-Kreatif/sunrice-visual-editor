<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\Admin\Activity\Activity;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class);

beforeEach(function () {
    $this->setUpStarterSite();
    actingAsSuperAdmin();
    $this->assetUrl = fn (string $kind): string => $kind === 'js' ? '/admin/visual-editor/api/scripts/custom' : '/admin/visual-editor/api/styles/files/custom.css/source';
});

it('optionally checks JS syntax without executing the submitted program', function () {
    config(['sunrice-visual-editor.syntax_checks.js' => ['node', '--check']]);
    $marker = $this->theme.'/must-not-run';
    $source = 'require("fs").writeFileSync('.json_encode($marker).', "ran");';
    $this->putJson(($this->assetUrl)('js'), ['version' => sha1(''), 'source' => $source])->assertOk();
    expect(File::exists($marker))->toBeFalse()->and(File::get($this->theme.'/custom.js'))->toBe($source);
    $activity = Activity::query()->count();
    $this->putJson(($this->assetUrl)('js'), ['version' => sha1($source), 'source' => 'function ('])->assertStatus(422)->assertJsonPath('reason', 'syntax');
    expect(File::get($this->theme.'/custom.js'))->toBe($source)->and(Activity::query()->count())->toBe($activity);
    $this->getJson('/admin/visual-editor/api/scripts/custom')->assertOk()->assertJsonPath('custom.checked', true);
});

it('passes exact CSS on stdin to a configured checker and refuses its failures before creating the file', function () {
    config(['sunrice-visual-editor.syntax_checks.css' => [PHP_BINARY, '-r', 'if (stream_get_contents(STDIN) !== ".hero { color: red; }\n") { fwrite(STDERR, "Invalid CSS"); exit(1); }']]);
    $this->putJson(($this->assetUrl)('css'), ['version' => sha1(''), 'source' => '.hero { INVALID'])->assertStatus(422)->assertJsonPath('reason', 'syntax');
    expect(File::exists($this->theme.'/custom.css'))->toBeFalse();
    $this->putJson(($this->assetUrl)('css'), ['version' => sha1(''), 'source' => ".hero { color: red; }\n"])->assertOk();
    $this->getJson('/admin/visual-editor/api/styles/custom')->assertOk()->assertJsonPath('custom.checked', true);
});

it('leaves the file and its version unchanged when checks are unavailable, misconfigured or timed out', function (string $kind, mixed $command) {
    $file = $this->theme.'/custom.'.$kind;
    File::put($file, 'original');
    config(['sunrice-visual-editor.syntax_checks.'.$kind => $command, 'sunrice-visual-editor.syntax_checks.timeout' => 1]);
    $this->putJson(($this->assetUrl)($kind), ['version' => sha1('original'), 'source' => 'candidate'])->assertStatus(422)->assertJsonPath('reason', 'syntax');
    expect(File::get($file))->toBe('original');
    // Stale writes are still 409, even if the checker cannot run.
    $this->putJson(($this->assetUrl)($kind), ['version' => 'stale', 'source' => 'candidate'])->assertStatus(409);
})->with([
    ['css', 'not an argument list'],
    ['js', ['sunrice-nonexistent-syntax-checker']],
    ['js', [PHP_BINARY, '-r', 'sleep(3);']],
]);

it('keeps checks opt-in for existing sites', function (string $kind) {
    $source = $kind === 'js' ? 'function (' : '.hero {';
    $this->putJson(($this->assetUrl)($kind), ['version' => sha1(''), 'source' => $source])->assertOk();
    expect(File::get($this->theme.'/custom.'.$kind))->toBe($source);
})->with(['css', 'js']);

it('does not overwrite a file changed externally while the checker was running', function (string $kind) {
    $file = $this->theme.'/custom.'.$kind;
    File::put($file, 'original');
    // Simulate another writer during a successful validation command.
    config(['sunrice-visual-editor.syntax_checks.'.$kind => [PHP_BINARY, '-r', 'file_put_contents('.var_export($file, true).', "external");']]);
    $this->putJson(($this->assetUrl)($kind), ['version' => sha1('original'), 'source' => 'candidate'])->assertStatus(409)->assertJsonPath('version', sha1('external'));
    expect(File::get($file))->toBe('external');
})->with(['css', 'js']);
