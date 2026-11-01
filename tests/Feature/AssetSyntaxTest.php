<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Sunrice\Admin\Activity\Activity;
use Sunrice\VisualEditor\Support\AssetSyntax;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;
use Symfony\Component\Process\ExecutableFinder;

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

it('leaves the file and its version unchanged when checks are unavailable, misconfigured or timed out, and says the checker is at fault', function (string $kind, mixed $command) {
    $file = $this->theme.'/custom.'.$kind;
    File::put($file, 'original');
    config(['sunrice-visual-editor.syntax_checks.'.$kind => $command, 'sunrice-visual-editor.syntax_checks.timeout' => 1]);
    $response = $this->putJson(($this->assetUrl)($kind), ['version' => sha1('original'), 'source' => 'candidate'])->assertStatus(422)->assertJsonPath('reason', 'checker');
    expect($response->json('message'))->toContain('Ask an administrator')->not->toContain('sh:')->not->toContain('not found')
        ->and($response->json())->not->toHaveKey('line')
        ->and(File::get($file))->toBe('original');
    // Stale writes are still 409, even if the checker cannot run.
    $this->putJson(($this->assetUrl)($kind), ['version' => 'stale', 'source' => 'candidate'])->assertStatus(409);
})->with([
    ['css', 'not an argument list'],
    ['js', ['sunrice-nonexistent-syntax-checker']],
    ['css', ['/nonexistent/checker']],
    // Found, but the shell can't start it: exit 127 is the shell's, not the code's.
    ['js', [PHP_BINARY, '-r', 'exit(127);']],
    ['css', [PHP_BINARY, '-r', 'exit(126);']],
    ['js', [PHP_BINARY, '-r', 'sleep(3);']],
]);

it('logs why a checker could not run, and keeps it from the person', function () {
    Log::spy();
    config(['sunrice-visual-editor.syntax_checks.css' => ['/nonexistent/checker']]);
    $this->putJson(($this->assetUrl)('css'), ['version' => sha1(''), 'source' => '.a {}'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'checker')
        ->assertJsonPath('message', 'The CSS syntax checker could not run. Nothing was saved. Ask an administrator to check its configuration (syntax_checks.css).');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, '/nonexistent/checker'))->once();
    expect(File::exists($this->theme.'/custom.css'))->toBeFalse();
});

it('still answers syntax for code a working checker refuses', function () {
    config(['sunrice-visual-editor.syntax_checks.css' => [PHP_BINARY, '-r', 'fwrite(STDERR, "Unexpected } on line 3"); exit(1);']]);
    $this->putJson(($this->assetUrl)('css'), ['version' => sha1(''), 'source' => '}}} @@@ {'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'syntax')
        ->assertJsonPath('line', 3)
        ->assertJsonPath('message', "CSS check failed on line 3. Nothing was saved.\nUnexpected } on line 3");
});

it('checks CSS out of the box with its own checker, and names the line', function (string $source, int $line) {
    $response = $this->putJson(($this->assetUrl)('css'), ['version' => sha1(''), 'source' => $source])->assertStatus(422)->assertJsonPath('reason', 'syntax')->assertJsonPath('line', $line);
    expect($response->json('message'))->toStartWith("CSS check failed on line {$line}. Nothing was saved.\nLine {$line}: ")
        ->and(File::exists($this->theme.'/custom.css'))->toBeFalse();
    $this->getJson('/admin/visual-editor/api/styles/custom')->assertOk()->assertJsonPath('custom.checked', true)->assertJsonPath('custom.checker', 'builtin');
})->with([
    'a rule never closed' => [".hero {\n  color: red;\n", 1],
    'braces closing nothing' => ["\n}}} @@@ {", 2],
    'a bracket closed by the wrong pair' => [".a {\n  width: calc(1px + 2px;\n}", 3],
    'a string never closed' => [".a::before {\n  content: \"x;\n}", 2],
    'a comment never closed' => [".a {}\n/* note", 2],
]);

it('saves valid CSS out of the box', function () {
    $source = "/* { */ .sm\\:flex { display: flex }\n.a::before { content: \"}\" }\n@media (max-width: 640px) { .b { background: url(images/it's.png) } }\n";
    $this->putJson(($this->assetUrl)('css'), ['version' => sha1(''), 'source' => $source])->assertOk();
    expect(File::get($this->theme.'/custom.css'))->toBe($source);
});

it('does not hold a stylesheet to the built-in check it already failed', function () {
    File::put($this->theme.'/custom.css', ".a { color: red; }\n}\n");
    $source = ".a { color: blue; }\n}\n";
    $this->putJson(($this->assetUrl)('css'), ['version' => sha1(".a { color: red; }\n}\n"), 'source' => $source])->assertOk();
    expect(File::get($this->theme.'/custom.css'))->toBe($source);
});

it('checks JS out of the box with node --check when Node.js is there', function () {
    $node = (new ExecutableFinder)->find('node');
    $this->getJson('/admin/visual-editor/api/scripts/custom')->assertOk()->assertJsonPath('custom.checked', $node !== null)->assertJsonPath('custom.checker', $node === null ? null : 'node');
    $response = $this->putJson(($this->assetUrl)('js'), ['version' => sha1(''), 'source' => "window.a=1;\nfunction( {{{"]);
    if ($node === null) {
        $response->assertOk();

        return;
    }
    $response->assertStatus(422)->assertJsonPath('reason', 'syntax')->assertJsonPath('line', 2);
    expect(File::exists($this->theme.'/custom.js'))->toBeFalse();
    $this->putJson(($this->assetUrl)('js'), ['version' => sha1(''), 'source' => 'window.a = 1;'])->assertOk();
});

it('shows what node --check says about the code, not its stack or its version', function () {
    if ((new ExecutableFinder)->find('node') === null) {
        $this->markTestSkipped('Node.js is not installed.');
    }
    config(['sunrice-visual-editor.syntax_checks.js' => ['node', '--check', '-']]);
    $response = $this->putJson(($this->assetUrl)('js'), ['version' => sha1(''), 'source' => "window.a=1;\nfunction( {{{"])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'syntax')
        ->assertJsonPath('line', 2);
    $message = (string) $response->json('message');
    expect($message)->toStartWith("JS check failed on line 2. Nothing was saved.\n[stdin]:2\nfunction( {{{\n^^^^^^^^")
        ->toContain('SyntaxError')
        ->not->toContain('node:internal')
        ->not->toContain('Node.js v')
        ->not->toMatch('/^\s+at /m')
        ->and(strlen($message))->toBeLessThan(1200);
});

it('keeps a checker\'s answer short', function () {
    [$text, $line] = AssetSyntax::shown(implode("\n", array_map(fn (int $n) => "line {$n} ".str_repeat('x', 80), range(1, 40)))."\n    at frame (node:internal/x:1:1)\nNode.js v22.0.0");
    expect(substr_count($text, "\n"))->toBeLessThan(20)
        ->and(strlen($text))->toBeLessThanOrEqual(1003)
        ->and($line)->toBe(1);
    expect(AssetSyntax::shown("[stdin]:7\nboom\n    at wrapSafe (node:internal/modules/cjs/loader:1472:18)\n\nNode.js v20.19.2"))->toBe(["[stdin]:7\nboom", 7]);
});

it('checks nothing where a check is set to false', function (string $kind) {
    config(['sunrice-visual-editor.syntax_checks.'.$kind => false]);
    $source = $kind === 'js' ? 'function (' : '.hero {';
    $this->putJson(($this->assetUrl)($kind), ['version' => sha1(''), 'source' => $source])->assertOk();
    expect(File::get($this->theme.'/custom.'.$kind))->toBe($source);
    $this->getJson($kind === 'js' ? '/admin/visual-editor/api/scripts/custom' : '/admin/visual-editor/api/styles/custom')->assertOk()->assertJsonPath('custom.checked', false)->assertJsonPath('custom.checker', null);
})->with(['css', 'js']);

it('does not overwrite a file changed externally while the checker was running', function (string $kind) {
    $file = $this->theme.'/custom.'.$kind;
    File::put($file, 'original');
    // Simulate another writer during a successful validation command.
    config(['sunrice-visual-editor.syntax_checks.'.$kind => [PHP_BINARY, '-r', 'file_put_contents('.var_export($file, true).', "external");']]);
    $this->putJson(($this->assetUrl)($kind), ['version' => sha1('original'), 'source' => 'candidate'])->assertStatus(409)->assertJsonPath('version', sha1('external'));
    expect(File::get($file))->toBe('external');
})->with(['css', 'js']);
