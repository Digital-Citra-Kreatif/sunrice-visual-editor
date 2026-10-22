<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(WithStarterSite::class);

const SVE_API = '/admin/visual-editor/api/templates';
const SVE_FOOTER = 'partials/footer.blade.php';

beforeEach(function () {
    $this->setUpStarterSite();
    $this->footer = $this->templates.'/'.SVE_FOOTER;
    $this->original = File::get($this->footer);
    File::deleteDirectory(storage_path('app/sunrice-cms/template-backups'));
});

afterEach(fn () => File::deleteDirectory(storage_path('app/sunrice-cms/template-backups')));

/** The id of the first element of a template's source. */
function firstElement(string $source): string
{
    return Template::open($source)->elements()[0]->id();
}

it('lists the templates', function () {
    actingAsSuperAdmin();

    $templates = collect(getJson(SVE_API)->assertOk()->json('templates'));

    expect($templates->where('origin', 'site')->pluck('path'))->toContain(SVE_FOOTER, 'layouts/app.blade.php', 'show.blade.php')
        ->and($templates->where('origin', 'package')->pluck('path'))->toContain('defaults/show.blade.php')
        ->and($templates->firstWhere('path', SVE_FOOTER))->toMatchArray(['origin' => 'site', 'view' => 'sunrice.partials.footer', 'role' => 'partial', 'editable' => true, 'opens' => true])
        // The list says what there is; sources and trees come one template at a time.
        ->and($templates->first())->not->toHaveKeys(['source', 'tree']);
});

it('reads a template of the site', function () {
    actingAsSuperAdmin();

    getJson(SVE_API.'/'.SVE_FOOTER)
        ->assertOk()
        ->assertJsonPath('template.path', SVE_FOOTER)
        ->assertJsonPath('template.origin', 'site')
        ->assertJsonPath('template.source', $this->original)
        ->assertJsonPath('template.version', sha1($this->original))
        ->assertJsonPath('template.opens', true)
        ->assertJsonPath('template.tree.id', 'root');
});

it('reads one of Sunrice\'s own views', function () {
    actingAsSuperAdmin();

    getJson(SVE_API.'/defaults/show.blade.php?origin=package')
        ->assertOk()
        ->assertJsonPath('template.origin', 'package')
        ->assertJsonPath('template.view', 'sunrice::defaults.show')
        ->assertJsonPath('template.editable', false)
        ->assertJsonPath('template.copyTo', 'show.blade.php');
});

it('answers 404 for a template that isn\'t there, whatever the path tries', function (string $path) {
    actingAsSuperAdmin();
    File::put(dirname($this->templates).'/secret.blade.php', 'NOT-FOR-THE-EDITOR');

    $response = getJson(SVE_API.'/'.$path);

    expect($response->status())->toBe(404)
        ->and((string) $response->getContent())->not->toContain('NOT-FOR-THE-EDITOR');
})->with([
    'nothing there' => ['partials/nope.blade.php'],
    'up and out' => ['..%2Fsecret.blade.php'],
    'up and out, written plainly' => ['partials/../../secret.blade.php'],
    'a package view without saying so' => ['defaults/show.blade.php'],
    'a file that is no template' => ['partials/footer.php'],
]);

it('says not_found in the way the screen expects', function () {
    actingAsSuperAdmin();

    getJson(SVE_API.'/partials/nope.blade.php')
        ->assertNotFound()
        ->assertJsonPath('reason', 'not_found')
        ->assertJsonPath('message', 'The site has no template "partials/nope.blade.php".');
});

it('applies operations, saves, and answers with the new template', function () {
    actingAsSuperAdmin();
    $element = firstElement($this->original);

    $response = postJson(SVE_API.'/'.SVE_FOOTER.'/operations', [
        'version' => sha1($this->original),
        'operations' => [
            ['type' => 'addClass', 'node' => $element, 'class' => 'sve-test'],
            ['type' => 'insertAfter', 'node' => $element, 'blade' => '<p class="sve-added">{{ $note ?? \'\' }}</p>'],
        ],
    ])->assertOk();

    $saved = File::get($this->footer);
    expect($saved)->not->toBe($this->original)
        ->and($saved)->toContain('sve-test', '<p class="sve-added">{{ $note ?? \'\' }}</p>')
        ->and($response->json('template.source'))->toBe($saved)
        ->and($response->json('template.version'))->toBe(sha1($saved))
        ->and($response->json('template.tree.type'))->toBe('root');

    // The node to select: what the last operation put in, by its id in the new file.
    $focus = Template::open($saved)->find((string) $response->json('focus'));
    expect($focus?->print())->toBe('<p class="sve-added">{{ $note ?? \'\' }}</p>')
        // The old version is kept.
        ->and(glob(storage_path('app/sunrice-cms/template-backups/*/cms/'.SVE_FOOTER)))->toHaveCount(1);
});

it('refuses operations made for another version of the file', function () {
    actingAsSuperAdmin();
    $element = firstElement($this->original);
    File::append($this->footer, "\n{{-- edited elsewhere --}}\n");
    $theirs = File::get($this->footer);

    postJson(SVE_API.'/'.SVE_FOOTER.'/operations', [
        'version' => sha1($this->original),
        'operations' => [['type' => 'addClass', 'node' => $element, 'class' => 'sve-test']],
    ])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'conflict')
        ->assertJsonPath('version', sha1($theirs));

    expect(File::get($this->footer))->toBe($theirs);
});

it('refuses an operation that can\'t be made, and says which', function (array $operations, ?int $index, string $message) {
    actingAsSuperAdmin();
    foreach ($operations as $i => $operation) {
        if ($operation['node'] === 'first') {
            $operations[$i]['node'] = firstElement($this->original);
        }
    }

    postJson(SVE_API.'/'.SVE_FOOTER.'/operations', ['version' => sha1($this->original), 'operations' => $operations])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'rejected')
        ->assertJsonPath('operation', $index)
        ->assertJsonPath('message', fn (string $text) => str_contains($text, $message));

    expect(File::get($this->footer))->toBe($this->original)
        ->and(glob(storage_path('app/sunrice-cms/template-backups/*/cms/'.SVE_FOOTER)))->toBe([]);
})->with([
    'a node that isn\'t there' => [[['type' => 'remove', 'node' => '99999']], 0, 'no node "99999"'],
    'an unknown operation, after a good one' => [[['type' => 'addClass', 'node' => 'first', 'class' => 'a'], ['type' => 'explode', 'node' => 'first']], 1, 'no operation "explode"'],
    'something missing' => [[['type' => 'addClass', 'node' => 'first']], 0, 'needs "class"'],
    'a snippet that doesn\'t nest' => [[['type' => 'insertAfter', 'node' => 'first', 'blade' => '<div>']], 0, 'never closed'],
    // Refused for what the operations leave as a whole, not for one of them.
    'a result that doesn\'t compile' => [[['type' => 'insertAfter', 'node' => 'first', 'blade' => '@if ($a +*) x @endif']], null, 'doesn\'t compile'],
]);

it('takes text exactly as it was sent', function () {
    actingAsSuperAdmin();

    // A blank line first, spaces last: Laravel would trim both from ordinary input.
    $source = "\n<footer class=\"site-footer\">\n    <p>Text</p>\n</footer>\n  ";
    putJson(SVE_API.'/'.SVE_FOOTER.'/source', ['version' => sha1($this->original), 'source' => $source])
        ->assertOk()
        ->assertJsonPath('template.source', $source);
    expect(File::get($this->footer))->toBe($source);

    // An empty value stays an empty value, and a snippet keeps its line breaks.
    postJson(SVE_API.'/'.SVE_FOOTER.'/operations', [
        'version' => sha1($source),
        'operations' => [
            ['type' => 'setAttribute', 'node' => firstElement($source), 'name' => 'data-empty', 'value' => ''],
            ['type' => 'setAttribute', 'node' => firstElement($source), 'name' => 'hidden', 'value' => null],
            ['type' => 'append', 'node' => firstElement($source), 'blade' => "<pre>\n  kept\n</pre>"],
        ],
    ])->assertOk();

    expect(File::get($this->footer))->toContain('<footer class="site-footer" data-empty="" hidden>', "<pre>\n  kept\n</pre>");
});

it('refuses a body that is no JSON object', function (string $body) {
    actingAsSuperAdmin();

    $this->call('PUT', SVE_API.'/'.SVE_FOOTER.'/source', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)
        ->assertStatus(422)
        ->assertJsonPath('reason', 'invalid');

    expect(File::get($this->footer))->toBe($this->original);
})->with([
    'nothing' => [''],
    'not JSON' => ['version=abc&source=x'],
    'a list' => ['["abc", "x"]'],
    'a string' => ['"abc"'],
]);

it('refuses a request that doesn\'t say what it has to', function (array $body, string $message) {
    actingAsSuperAdmin();

    postJson(SVE_API.'/'.SVE_FOOTER.'/operations', $body)
        ->assertStatus(422)
        ->assertJsonPath('reason', 'invalid')
        ->assertJsonPath('message', fn (string $text) => str_contains($text, $message));

    expect(File::get($this->footer))->toBe($this->original);
})->with([
    'no version' => [['operations' => [['type' => 'remove', 'node' => '0']]], '"version"'],
    'no operations' => [['version' => 'abc'], '"operations"'],
    'an empty list' => [['version' => 'abc', 'operations' => []], '"operations"'],
    'operations that are no list' => [['version' => 'abc', 'operations' => ['type' => 'remove', 'node' => '0']], '"operations"'],
    'an operation that is no object' => [['version' => 'abc', 'operations' => ['remove']], 'Each operation'],
    'too many' => [['version' => 'abc', 'operations' => array_fill(0, 201, ['type' => 'remove', 'node' => '0'])], 'more than 200'],
]);

it('saves source written in the code panel', function () {
    actingAsSuperAdmin();
    // Code the tree can't hold, but Blade compiles: the code panel is where such a file is edited.
    $source = "@if (\$wide) <footer class=\"wide\" @endif>\n    <p>Text</p>\n</footer>\n";

    putJson(SVE_API.'/'.SVE_FOOTER.'/source', ['version' => sha1($this->original), 'source' => $source])
        ->assertOk()
        ->assertJsonPath('template.source', $source)
        ->assertJsonPath('template.version', sha1($source))
        ->assertJsonPath('template.opens', false)
        ->assertJsonPath('template.tree', null);

    expect(File::get($this->footer))->toBe($source);
});

it('refuses source that doesn\'t compile, and writes nothing', function () {
    actingAsSuperAdmin();

    putJson(SVE_API.'/'.SVE_FOOTER.'/source', ['version' => sha1($this->original), 'source' => '<footer>@if ($a)</footer>'])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'syntax')
        ->assertJsonPath('message', fn (string $text) => str_contains($text, 'syntax error'));

    expect(File::get($this->footer))->toBe($this->original);
});

it('refuses source for another version of the file, or none at all', function () {
    actingAsSuperAdmin();

    putJson(SVE_API.'/'.SVE_FOOTER.'/source', ['version' => sha1('something else'), 'source' => '<footer></footer>'])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'conflict')
        ->assertJsonPath('version', sha1($this->original));
    putJson(SVE_API.'/'.SVE_FOOTER.'/source', ['version' => sha1($this->original)])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'invalid');
    putJson(SVE_API.'/'.SVE_FOOTER.'/source', ['version' => sha1($this->original), 'source' => str_repeat('a', 1_000_001)])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'invalid');

    expect(File::get($this->footer))->toBe($this->original);
});

it('changes none of Sunrice\'s own views', function () {
    actingAsSuperAdmin();
    $view = getJson(SVE_API.'/defaults/show.blade.php?origin=package')->json('template');

    putJson(SVE_API.'/defaults/show.blade.php/source', ['version' => $view['version'], 'source' => '<p>x</p>'])->assertNotFound();
    postJson(SVE_API.'/defaults/show.blade.php/operations', ['version' => $view['version'], 'operations' => [['type' => 'remove', 'node' => '0']]])->assertNotFound();
    putJson(SVE_API.'/defaults/show.blade.php/source?origin=package', ['version' => $view['version'], 'source' => '<p>x</p>'])->assertNotFound();

    expect(getJson(SVE_API.'/defaults/show.blade.php?origin=package')->json('template.version'))->toBe($view['version']);
});

it('copies a fallback view into the site', function () {
    actingAsSuperAdmin();
    File::delete($this->templates.'/taxonomies/show.blade.php');
    $source = getJson(SVE_API.'/defaults/term.blade.php?origin=package')->json('template.source');

    postJson(SVE_API.'/defaults/term.blade.php/copy')
        ->assertCreated()
        ->assertJsonPath('template.path', 'taxonomies/show.blade.php')
        ->assertJsonPath('template.origin', 'site')
        ->assertJsonPath('template.editable', true)
        ->assertJsonPath('template.source', $source);

    expect(File::get($this->templates.'/taxonomies/show.blade.php'))->toBe($source);
});

it('refuses a copy the site has no room for', function (string $path, int $status, string $reason) {
    actingAsSuperAdmin();

    postJson(SVE_API.'/'.$path.'/copy')->assertStatus($status)->assertJsonPath('reason', $reason);
})->with([
    'the site already has that template' => ['defaults/show.blade.php', 422, 'rejected'],
    'Sunrice looks for it nowhere in the site' => ['components/form.blade.php', 422, 'rejected'],
    'not one of Sunrice\'s views' => [SVE_FOOTER, 404, 'not_found'],
]);

it('lets only those who may use the editor in', function (string $method, string $uri) {
    $body = ['version' => sha1($this->original), 'source' => '<p>x</p>', 'operations' => [['type' => 'remove', 'node' => '0']]];

    // A guest.
    $this->json($method, $uri, $body)->assertUnauthorized();

    // Someone who may use the admin, but is no Super Admin.
    $user = createUser();
    $user->givePermissionTo(Permission::findOrCreate('sunrice.access', 'web'));
    actingAs($user);
    $this->json($method, $uri, $body)->assertForbidden()->assertJsonPath('reason', 'forbidden');

    expect(File::get($this->footer))->toBe($this->original);
})->with([
    ['GET', SVE_API],
    ['GET', '/admin/visual-editor/api/inserts'],
    ['GET', SVE_API.'/'.SVE_FOOTER],
    ['POST', SVE_API.'/'.SVE_FOOTER.'/operations'],
    ['PUT', SVE_API.'/'.SVE_FOOTER.'/source'],
    ['POST', SVE_API.'/defaults/term.blade.php/copy'],
]);

it('names its routes', function () {
    foreach (['index', 'show', 'operations', 'source', 'copy'] as $name) {
        expect(Route::has('sunrice.admin.visual-editor.api.templates.'.$name))->toBeTrue();
    }
    expect(route('sunrice.admin.visual-editor.api.templates.show', ['path' => SVE_FOOTER], false))->toBe(SVE_API.'/'.SVE_FOOTER);
});
