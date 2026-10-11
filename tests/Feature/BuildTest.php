<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Sunrice\Models\ActivityLog;
use Sunrice\VisualEditor\Actions\Build;
use Sunrice\VisualEditor\Support\Access;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

/*
 * The site's build command, run from the editor: the command of the
 * site's configuration and no other, by those who have the permission for
 * it, one at a time and not for ever.
 */

const SVE_BUILD = '/cms/visual-editor/api/build';

/** A command that PHP itself runs, wherever the tests are run. */
function phpDoes(string $code): string
{
    return escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code);
}

/** Signs in someone who may use the admin and has these of the editor's permissions. */
function builder(string ...$permissions): void
{
    $user = createUser(email: 'someone'.count($permissions).substr(md5(implode(',', $permissions)), 0, 6).'@example.com');
    foreach (['sunrice.access-admin', ...$permissions] as $name) {
        $user->givePermissionTo(Permission::findOrCreate($name, config('sunrice.auth.guard', 'web')));
    }
    actingAs($user, config('sunrice.auth.guard', 'web'));
}

beforeEach(function () {
    config(['sunrice-visual-editor.build' => ['command' => phpDoes('echo "built in ", basename(getcwd());'), 'timeout' => 20]]);
});

it('runs the command of the site\'s configuration in the site\'s directory, and says what it printed', function () {
    actingAsSuperAdmin();

    $answer = postJson(SVE_BUILD)->assertOk()->json();

    expect($answer['ok'])->toBeTrue()
        ->and($answer['code'])->toBe(0)
        ->and($answer['message'])->toBe('Built.')
        ->and($answer['output'])->toBe('built in '.basename(base_path()))
        ->and($answer['seconds'])->toBeGreaterThanOrEqual(0);

    $line = ActivityLog::query()->where('subject_type', 'build')->sole();
    expect($line->subject_label)->toBe('Site build')
        ->and($line->properties['note'])->toContain('done')->toContain('echo');
});

it('runs nothing a request sends', function () {
    actingAsSuperAdmin();
    $marker = storage_path('sve-build-marker');
    @unlink($marker);

    $answer = postJson(SVE_BUILD, ['command' => phpDoes('touch("'.$marker.'");'), 'build' => ['command' => 'touch '.$marker]])->assertOk()->json();

    expect($answer['output'])->toStartWith('built in')
        ->and(file_exists($marker))->toBeFalse();
});

it('says how a build that fails failed, with what it printed', function () {
    actingAsSuperAdmin();
    config(['sunrice-visual-editor.build.command' => phpDoes('fwrite(STDERR, "\033[31merror\033[0m: no such class\n"); echo "partly done"; exit(3);')]);

    $answer = postJson(SVE_BUILD)->assertOk()->json();

    expect($answer['ok'])->toBeFalse()
        ->and($answer['code'])->toBe(3)
        ->and($answer['message'])->toContain('failed')->toContain('3')
        // Both what it wrote as output and as errors, without a terminal's colours.
        ->and($answer['output'])->toContain('error: no such class')->toContain('partly done')->not->toContain("\033");
    expect(ActivityLog::query()->where('subject_type', 'build')->sole()->properties['note'])->toContain('failed');
});

it('says so when the command is not there to run', function () {
    actingAsSuperAdmin();
    config(['sunrice-visual-editor.build.command' => 'sve-no-such-program-anywhere run build']);

    $answer = postJson(SVE_BUILD)->assertOk()->json();

    expect($answer['ok'])->toBeFalse()
        ->and($answer['message'])->toContain('a program it needs is not there')->toContain('Node')
        // What the shell said is there to read: it names the program.
        ->and($answer['output'])->toContain('sve-no-such-program-anywhere');
});

it('gives the command the path the web server\'s process has, which PHP does not list for a request', function () {
    actingAsSuperAdmin();
    config(['sunrice-visual-editor.build.command' => 'printenv PATH']);

    // Behind a web server the path is in the process and not in what PHP lists: a process started from there went without.
    $listed = [$_SERVER['PATH'] ?? null, $_ENV['PATH'] ?? null];
    unset($_SERVER['PATH'], $_ENV['PATH']);
    try {
        $answer = postJson(SVE_BUILD)->assertOk()->json();
    } finally {
        [$server, $env] = $listed;
        if ($server !== null) {
            $_SERVER['PATH'] = $server;
        }
        if ($env !== null) {
            $_ENV['PATH'] = $env;
        }
    }

    expect($answer['ok'])->toBeTrue($answer['output'])
        ->and($answer['output'])->toBe(getenv('PATH'));
});

it('stops a build that takes too long', function () {
    actingAsSuperAdmin();
    config(['sunrice-visual-editor.build' => ['command' => phpDoes('echo "started"; sleep(20);'), 'timeout' => 1]]);
    $began = microtime(true);

    $answer = postJson(SVE_BUILD)->assertOk()->json();

    expect($answer['ok'])->toBeFalse()
        ->and($answer['message'])->toContain('stopped')->toContain('1 seconds')
        ->and(microtime(true) - $began)->toBeLessThan(10);
});

it('gives back the end of a great deal of output', function () {
    actingAsSuperAdmin();
    config(['sunrice-visual-editor.build.command' => phpDoes('echo str_repeat("line of output\n", 5000), "the last line";')]);

    $output = postJson(SVE_BUILD)->assertOk()->json('output');

    expect(strlen($output))->toBeLessThan(8100)
        ->and($output)->toStartWith('…')->toEndWith('the last line');
});

it('runs one build at a time', function () {
    actingAsSuperAdmin();
    $directory = storage_path('framework/cache/sunrice-visual-editor');
    @mkdir($directory, 0755, true);
    // Another request is in the middle of one.
    $other = fopen($directory.'/build.lock', 'c');
    flock($other, LOCK_EX);

    try {
        postJson(SVE_BUILD)->assertStatus(422)->assertJsonPath('reason', 'rejected')->assertJsonPath('message', 'A build is running already. Wait for it to end.');
    } finally {
        flock($other, LOCK_UN);
        fclose($other);
    }
    postJson(SVE_BUILD)->assertOk()->assertJsonPath('ok', true);
});

it('has nothing to run where no command is set', function (mixed $command) {
    actingAsSuperAdmin();
    config(['sunrice-visual-editor.build.command' => $command]);

    expect(Build::command())->toBeNull();
    postJson(SVE_BUILD)->assertStatus(422)->assertJsonPath('reason', 'rejected');
    get('/cms/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('can.build', false));
})->with([null, '', '   ', false, [['npm', 'run', 'build']]]);

it('is no part of editing, however much of it someone may do', function () {
    foreach ([[Access::OPEN], [Access::EDIT], [Access::CODE]] as $permissions) {
        builder(...$permissions);
        postJson(SVE_BUILD)->assertForbidden()->assertJsonPath('reason', 'forbidden');
        get('/cms/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('can.build', false));
    }
    expect(ActivityLog::query()->where('subject_type', 'build')->count())->toBe(0);
});

it('takes its own permission, and that of using the editor at all', function () {
    builder(Access::OPEN, Access::BUILD);
    postJson(SVE_BUILD)->assertOk()->assertJsonPath('ok', true);
    get('/cms/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('can.build', true)->where('can.edit', false)->where('can.code', false));

    // Building alone opens nothing: the editor is not theirs to use.
    builder(Access::BUILD);
    postJson(SVE_BUILD)->assertForbidden();
});

it('says whether the site\'s dev server is running, which leaves nothing to build', function () {
    actingAsSuperAdmin();
    get('/cms/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('devServer', false)->where('can.build', true));

    file_put_contents(public_path('hot'), 'http://localhost:5173');
    try {
        get('/cms/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('devServer', true));
    } finally {
        unlink(public_path('hot'));
    }
});
