<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Sunrice\Models\ActivityLog;
use Sunrice\VisualEditor\Css\Stylesheet;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(WithStarterSite::class);

const SVE_STYLES = '/cms/visual-editor/api/styles';

beforeEach(function () {
    $this->setUpStarterSite();
    $this->css = $this->theme.'/app.css';
    $this->original = File::get($this->css);
    File::deleteDirectory(storage_path('app/sunrice/template-backups'));

    /** Where the first declaration of a property starts, in the first rule for a selector. */
    $this->at = fn (string $selector, ?string $property = null): int => $property === null
        ? Stylesheet::read(File::get($this->css))->styling($selector)[0]->start
        : (int) Stylesheet::read(File::get($this->css))->styling($selector)[0]->declaration($property)?->start;
    $this->change = fn (array $changes, array $more = []) => postJson(SVE_STYLES.'/files/app.css/changes', ['version' => sha1(File::get($this->css)), 'changes' => $changes] + $more);
});

afterEach(fn () => File::deleteDirectory(storage_path('app/sunrice/template-backups')));

// --- What the stylesheets say ---------------------------------------------------

it('lists the theme\'s stylesheets, and where new rules go', function () {
    actingAsSuperAdmin();
    File::ensureDirectoryExists($this->theme.'/css');
    File::put($this->theme.'/css/print.css', '.card { border: 0 }');
    File::put($this->theme.'/notes.txt', '.card { color: red }');

    $styles = getJson(SVE_STYLES)->assertOk()->json('styles');

    expect(array_column($styles['files'], 'path'))->toBe(['app.css', 'css/print.css'])
        ->and($styles['files'][0])->toMatchArray(['version' => sha1($this->original), 'exists' => true, 'reason' => null])
        ->and($styles['files'][0]['url'])->toEndWith('/sunrice-theme/app.css')
        ->and($styles['rules'])->toBe([])
        ->and($styles['class'])->toBeNull()
        ->and($styles['own'])->toMatchArray(['path' => 'editor.css', 'exists' => false, 'version' => sha1('')]);
});

it('says what the stylesheets say about a class', function () {
    actingAsSuperAdmin();

    $styles = getJson(SVE_STYLES.'?class=card')->assertOk()->json('styles');
    $rules = collect($styles['rules']);

    expect($styles['selector'])->toBe('.card')
        // The rule for the class itself first, then those that mention it. Not ".cards", not ".card-meta".
        ->and($rules->pluck('selector')->all())->toBe(['.card', '.card h2, .card h3'])
        ->and($rules->pluck('exact')->all())->toBe([true, false])
        ->and($rules[0])->toMatchArray(['file' => 'app.css', 'within' => [], 'line' => 59, 'id' => ($this->at)('.card')])
        ->and(array_map(fn (array $each) => [$each['property'], $each['value']], $rules[0]['declarations']))->toBe([['border', '1px solid var(--line)'], ['border-radius', '12px'], ['padding', '20px']])
        ->and($rules[0]['declarations'][2]['id'])->toBe(($this->at)('.card', 'padding'));
});

it('says which at-rules a rule is inside', function () {
    actingAsSuperAdmin();

    $rules = collect(getJson(SVE_STYLES.'?class=site-menu')->json('styles.rules'));

    expect($rules->where('exact', true)->pluck('within')->all())->toBe([[], ['@media (max-width: 760px)']]);
});

it('finds the rules for a class that CSS writes with escapes', function () {
    actingAsSuperAdmin();
    File::append($this->css, "\n.sm\\:flex { display: flex; }\n");

    getJson(SVE_STYLES.'?class='.urlencode('sm:flex'))->assertOk()
        ->assertJsonPath('styles.selector', '.sm\:flex')
        ->assertJsonPath('styles.rules.0.declarations.0.value', 'flex');
});

it('reads stylesheets of every theme folder, and leaves alone what it could not send', function () {
    actingAsSuperAdmin();
    File::put($this->theme.'/latin.css', ".card { content: \"caf\xE9\" }");
    File::put($this->theme.'/huge.css', str_repeat('.card { color: red } ', 110_000));

    $styles = getJson(SVE_STYLES.'?class=card')->json('styles');

    expect(array_column($styles['files'], 'path'))->toBe(['app.css', 'latin.css'])
        ->and($styles['files'][1]['reason'])->toContain('UTF-8')
        ->and(array_unique(array_column($styles['rules'], 'file')))->toBe(['app.css']);
});

it('refuses a class that is not one', function (string $query) {
    actingAsSuperAdmin();

    getJson(SVE_STYLES.'?'.$query)->assertStatus(422)->assertJsonPath('reason', 'invalid');
})->with(['class=a+b', 'class[]=a', 'class='.str_repeat('a', 201)]);

// --- Changes ------------------------------------------------------------------

it('changes one declaration and nothing else in the file', function () {
    $admin = actingAsSuperAdmin();

    $response = ($this->change)([['type' => 'set', 'declaration' => ($this->at)('.card', 'padding'), 'value' => '32px']], ['class' => 'card'])->assertOk();

    $expected = str_replace('.card { border: 1px solid var(--line); border-radius: 12px; padding: 20px; }', '.card { border: 1px solid var(--line); border-radius: 12px; padding: 32px; }', $this->original);
    expect(File::get($this->css))->toBe($expected)->not->toBe($this->original)
        ->and($response->json('file'))->toBe(['path' => 'app.css', 'version' => sha1($expected)])
        // The file as it was, to undo with; what the stylesheets say now, for the panel.
        ->and($response->json('before'))->toBe($this->original)
        ->and($response->json('styles.rules.0.declarations.2.value'))->toBe('32px')
        ->and($response->json('styles.files.0.version'))->toBe(sha1($expected))
        ->and(glob(storage_path('app/sunrice/template-backups/*/sunrice-theme/app.css')))->toHaveCount(1)
        ->and(File::get(glob(storage_path('app/sunrice/template-backups/*/sunrice-theme/app.css'))[0]))->toBe($this->original)
        ->and(ActivityLog::query()->where('subject_type', 'template')->sole())->toMatchArray(['action' => 'updated', 'subject_label' => 'sunrice-theme/app.css', 'user_id' => $admin->getKey()]);
});

it('adds, renames and takes out declarations, all or none', function () {
    actingAsSuperAdmin();

    ($this->change)([
        ['type' => 'add', 'rule' => ($this->at)('.card'), 'property' => 'background', 'value' => 'Canvas'],
        ['type' => 'rename', 'declaration' => ($this->at)('.card', 'padding'), 'property' => 'padding-block'],
        ['type' => 'remove', 'declaration' => ($this->at)('.card', 'border-radius')],
    ])->assertOk();

    expect(File::get($this->css))->toBe(str_replace('.card { border: 1px solid var(--line); border-radius: 12px; padding: 20px; }', '.card { border: 1px solid var(--line); padding-block: 20px; background: Canvas; }', $this->original));

    $now = File::get($this->css);
    ($this->change)([
        ['type' => 'set', 'declaration' => ($this->at)('.hero', 'padding'), 'value' => '0'],
        ['type' => 'set', 'declaration' => ($this->at)('.card', 'border'), 'value' => '0; } body { display: none'],
    ])->assertStatus(422)->assertJsonPath('reason', 'rejected')->assertJsonPath('change', 1);

    expect(File::get($this->css))->toBe($now);
});

it('puts a rule that does not exist yet into the editor\'s own stylesheet, which the first one makes', function () {
    actingAsSuperAdmin();
    $own = $this->theme.'/editor.css';
    expect(is_file($own))->toBeFalse();

    $response = postJson(SVE_STYLES.'/files/editor.css/changes', ['version' => sha1(''), 'class' => 'brand-new', 'changes' => [
        ['type' => 'rule', 'selector' => '.brand-new', 'declarations' => [['color', 'red']]],
    ]])->assertOk();

    expect(File::get($own))->toBe(".brand-new {\n    color: red;\n}\n")
        ->and($response->json('styles.own'))->toMatchArray(['path' => 'editor.css', 'exists' => true, 'version' => sha1(File::get($own))])
        ->and($response->json('styles.rules.0'))->toMatchArray(['file' => 'editor.css', 'selector' => '.brand-new', 'exact' => true])
        ->and($response->json('before'))->toBe('')
        // Nothing was there to keep a copy of.
        ->and(glob(storage_path('app/sunrice/template-backups/*/sunrice-theme/editor.css')))->toBe([])
        ->and(ActivityLog::query()->where('subject_type', 'template')->sole()->action)->toBe('created');

    // The next one goes after it, into the file as it is now.
    postJson(SVE_STYLES.'/files/editor.css/changes', ['version' => sha1(File::get($own)), 'changes' => [['type' => 'rule', 'selector' => '.another']]])->assertOk();
    expect(File::get($own))->toBe(".brand-new {\n    color: red;\n}\n\n.another {\n}\n");
});

it('keeps the editor\'s stylesheet where the site says', function () {
    actingAsSuperAdmin();
    config(['sunrice-visual-editor.theme_css' => 'css/visual.css']);

    getJson(SVE_STYLES)->assertJsonPath('styles.own.path', 'css/visual.css');
    postJson(SVE_STYLES.'/files/css/visual.css/changes', ['version' => sha1(''), 'changes' => [['type' => 'rule', 'selector' => '.a']]])->assertOk();
    postJson(SVE_STYLES.'/files/editor.css/changes', ['version' => sha1(''), 'changes' => [['type' => 'rule', 'selector' => '.a']]])->assertNotFound();

    expect(File::get($this->theme.'/css/visual.css'))->toBe(".a {\n}\n");

    // A name that could be no stylesheet of the theme is none.
    config(['sunrice-visual-editor.theme_css' => '../outside.css']);
    getJson(SVE_STYLES)->assertJsonPath('styles.own', null);
});

it('refuses a change made for another version of the file', function () {
    actingAsSuperAdmin();
    $change = [['type' => 'set', 'declaration' => ($this->at)('.card', 'padding'), 'value' => '32px']];
    File::append($this->css, "\n/* saved in a code editor meanwhile */\n");
    $now = File::get($this->css);

    postJson(SVE_STYLES.'/files/app.css/changes', ['version' => sha1($this->original), 'changes' => $change])
        ->assertStatus(409)->assertJsonPath('reason', 'conflict')->assertJsonPath('version', sha1($now));

    expect(File::get($this->css))->toBe($now);
});

it('refuses a request that does not say what it has to', function (array $body) {
    actingAsSuperAdmin();

    postJson(SVE_STYLES.'/files/app.css/changes', $body + ['version' => sha1($this->original)])->assertStatus(422)->assertJsonPath('reason', 'invalid');

    expect(File::get($this->css))->toBe($this->original);
})->with([
    'no changes' => [[]],
    'an empty list' => [['changes' => []]],
    'changes that are no list' => [['changes' => ['type' => 'set']]],
    'too many' => [['changes' => array_fill(0, 101, ['type' => 'rule', 'selector' => '.a'])]],
    'no version' => [['version' => '', 'changes' => [['type' => 'rule', 'selector' => '.a']]]],
    'a class that is none' => [['changes' => [['type' => 'rule', 'selector' => '.a']], 'class' => 'a b']],
]);

it('writes only stylesheets of the theme', function (string $path) {
    actingAsSuperAdmin();
    File::put(dirname($this->theme).'/outside.css', '.a { color: red }');
    File::put($this->theme.'/app.js.css.txt', '.a { color: red }');

    $this->postJson(SVE_STYLES.'/files/'.$path.'/changes', ['version' => sha1('.a { color: red }'), 'changes' => [['type' => 'rule', 'selector' => '.b']]])->assertNotFound();
    $this->putJson(SVE_STYLES.'/files/'.$path.'/source', ['version' => sha1('.a { color: red }'), 'source' => '.b {}'])->assertNotFound();

    expect(File::get(dirname($this->theme).'/outside.css'))->toBe('.a { color: red }');
})->with([
    'up and out' => ['../outside.css'],
    'up and back in' => ['css/../../outside.css'],
    'one that is not there' => ['other.css'],
    'a template' => ['../sunrice/show.blade.php/x.css'],
]);

// --- The whole text: undo and redo ----------------------------------------------

it('saves a stylesheet as it was, to undo a change', function () {
    actingAsSuperAdmin();
    $before = ($this->change)([['type' => 'set', 'declaration' => ($this->at)('.card', 'padding'), 'value' => '32px']])->json('before');
    $changed = File::get($this->css);

    $response = putJson(SVE_STYLES.'/files/app.css/source', ['version' => sha1($changed), 'source' => $before, 'class' => 'card'])->assertOk();

    expect(File::get($this->css))->toBe($this->original)
        ->and($response->json('file.version'))->toBe(sha1($this->original))
        ->and($response->json('before'))->toBe($changed)
        ->and($response->json('styles.rules.0.declarations.2.value'))->toBe('20px');

    // Not over a change it has not seen.
    putJson(SVE_STYLES.'/files/app.css/source', ['version' => sha1($changed), 'source' => $changed])->assertStatus(409);
    putJson(SVE_STYLES.'/files/app.css/source', ['version' => sha1($this->original)])->assertStatus(422)->assertJsonPath('reason', 'invalid');
    putJson(SVE_STYLES.'/files/app.css/source', ['version' => sha1($this->original), 'source' => str_repeat('a', 2_000_001)])->assertStatus(422)->assertJsonPath('reason', 'invalid');
});

it('does not send back a file too large to keep many of', function () {
    actingAsSuperAdmin();
    File::put($this->css, $large = str_repeat(".x { color: red; }\n", 40_000).'.card { padding: 0; }');

    ($this->change)([['type' => 'set', 'declaration' => ($this->at)('.card', 'padding'), 'value' => '1px']])->assertOk()->assertJsonPath('before', null);

    expect(File::get($this->css))->toBe(substr($large, 0, -4).'1px; }');
});

// --- Linking a stylesheet from the layout ---------------------------------------

it('links the editor\'s stylesheet from the layout, after the stylesheets it links already', function () {
    actingAsSuperAdmin();
    $layout = $this->templates.'/layouts/app.blade.php';
    $before = File::get($layout);
    postJson(SVE_STYLES.'/files/editor.css/changes', ['version' => sha1(''), 'changes' => [['type' => 'rule', 'selector' => '.a']]])->assertOk();

    $response = postJson(SVE_STYLES.'/link', ['template' => 'layouts/app.blade.php', 'version' => sha1($before), 'stylesheet' => 'editor.css'])->assertOk();

    $link = "    @if (is_file(\$sunriceEditorCss = public_path('sunrice-theme/editor.css')))\n        <link rel=\"stylesheet\" href=\"{{ asset('sunrice-theme/editor.css') }}?v={{ filemtime(\$sunriceEditorCss) }}\">\n    @endif\n";
    $after = File::get($layout);
    expect($after)->toBe(str_replace("?v={{ filemtime(\$sunriceCss) }}\">\n    @endif\n", "?v={{ filemtime(\$sunriceCss) }}\">\n    @endif\n".$link, $before))
        ->not->toBe($before)
        ->and($response->json('before'))->toBe($before)
        ->and($response->json('template'))->toMatchArray(['path' => 'layouts/app.blade.php', 'version' => sha1($after), 'opens' => true]);

    // The page is drawn with it, and a second link is not added.
    $this->get('/')->assertOk();
    postJson(SVE_STYLES.'/link', ['template' => 'layouts/app.blade.php', 'version' => sha1($after), 'stylesheet' => 'editor.css'])
        ->assertStatus(422)->assertJsonPath('reason', 'rejected');
    expect(File::get($layout))->toBe($after);
});

it('links at the end of a head that links no stylesheet', function () {
    actingAsSuperAdmin();
    File::put($this->templates.'/layouts/bare.blade.php', $bare = "<html>\n<head>\n    <title>x</title>\n</head>\n<body>@yield('content')</body>\n</html>\n");

    postJson(SVE_STYLES.'/link', ['template' => 'layouts/bare.blade.php', 'version' => sha1($bare), 'stylesheet' => 'app.css'])->assertOk();

    expect(File::get($this->templates.'/layouts/bare.blade.php'))->toBe("<html>\n<head>\n    <title>x</title>\n    @if (is_file(\$sunriceAppCss = public_path('sunrice-theme/app.css')))\n        <link rel=\"stylesheet\" href=\"{{ asset('sunrice-theme/app.css') }}?v={{ filemtime(\$sunriceAppCss) }}\">\n    @endif\n</head>\n<body>@yield('content')</body>\n</html>\n");
});

it('refuses a link it can\'t make', function (array $body, int $status, string $reason) {
    actingAsSuperAdmin();
    $layout = File::get($this->templates.'/layouts/app.blade.php');
    $footer = File::get($this->templates.'/partials/footer.blade.php');

    postJson(SVE_STYLES.'/link', array_map(fn ($value) => match ($value) {
        '{layout}' => sha1($layout),
        '{footer}' => sha1($footer),
        default => $value,
    }, $body))->assertStatus($status)->assertJsonPath('reason', $reason);

    expect(File::get($this->templates.'/layouts/app.blade.php'))->toBe($layout)
        ->and(File::get($this->templates.'/partials/footer.blade.php'))->toBe($footer);
})->with([
    'a template without a head' => [['template' => 'partials/footer.blade.php', 'version' => '{footer}', 'stylesheet' => 'app.css'], 422, 'rejected'],
    'a stylesheet it links already' => [['template' => 'layouts/app.blade.php', 'version' => '{layout}', 'stylesheet' => 'app.css'], 422, 'rejected'],
    'a stylesheet that is not there' => [['template' => 'layouts/app.blade.php', 'version' => '{layout}', 'stylesheet' => 'other.css'], 404, 'not_found'],
    'a template that is not there' => [['template' => 'layouts/other.blade.php', 'version' => '{layout}', 'stylesheet' => 'app.css'], 404, 'not_found'],
    'another version of the layout' => [['template' => 'layouts/app.blade.php', 'version' => 'older', 'stylesheet' => 'app.css'], 409, 'conflict'],
    'no stylesheet named' => [['template' => 'layouts/app.blade.php', 'version' => '{layout}'], 422, 'invalid'],
]);

// --- Who may ------------------------------------------------------------------

it('is for those who may use the editor only', function (string $method, string $uri, array $body) {
    // A guest.
    $this->json($method, $uri, $body)->assertUnauthorized();

    // Someone who may use the admin, but is no Super Admin.
    $user = createUser();
    $user->givePermissionTo(Permission::findOrCreate('sunrice.access-admin', config('sunrice.auth.guard', 'web')));
    actingAs($user, config('sunrice.auth.guard', 'web'));
    $this->json($method, $uri, $body)->assertForbidden()->assertJsonPath('reason', 'forbidden');

    expect(File::get($this->css))->toBe($this->original);
})->with([
    ['GET', SVE_STYLES.'?class=card', []],
    ['POST', SVE_STYLES.'/files/app.css/changes', ['version' => 'x', 'changes' => [['type' => 'rule', 'selector' => '.a']]]],
    ['PUT', SVE_STYLES.'/files/app.css/source', ['version' => 'x', 'source' => '']],
    ['POST', SVE_STYLES.'/link', ['template' => 'layouts/app.blade.php', 'version' => 'x', 'stylesheet' => 'app.css']],
]);
