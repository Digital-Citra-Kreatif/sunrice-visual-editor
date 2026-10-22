<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Sunrice\Models\Entry;
use Sunrice\VisualEditor\Actions\SaveTemplate;
use Sunrice\VisualEditor\Blade\Rules;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Http\Middleware\EditorRender;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;
use Workbench\App\Seeders\DemoSeeder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(WithStarterSite::class);

beforeEach(function () {
    $this->setUpStarterSite();
    File::deleteDirectory(EditorRender::directory());
});

afterEach(fn () => File::deleteDirectory(EditorRender::directory()));

/**
 * The manifest a page was rendered with, by template path.
 *
 * @return array<string, array<string, mixed>>
 */
function manifestOf(TestResponse $response): array
{
    preg_match('#<script type="application/json" id="sve-manifest">(.*?)</script>#s', (string) $response->getContent(), $match);

    return collect(json_decode($match[1] ?? '{"files":[]}', true)['files'])->keyBy('path')->all();
}

/**
 * Every mark in a page: [file key, node id], in order.
 *
 * @return list<array{0: string, 1: string}>
 */
function marksIn(TestResponse $response): array
{
    preg_match_all('/ data-sve="([0-9a-f]+):(\d+)"/', (string) $response->getContent(), $marks, PREG_SET_ORDER);

    return array_map(fn (array $mark) => [$mark[1], $mark[2]], $marks);
}

/**
 * Runs the middleware around a response, for whoever is signed in.
 *
 * @return array{0: Symfony\Component\HttpFoundation\Response, 1: Request}
 */
function editorRender(string $uri, Closure $respond): array
{
    $request = Request::create($uri);
    $request->setUserResolver(fn () => auth('web')->user());

    return [app(EditorRender::class)->handle($request, $respond), $request];
}

it('marks every element of a page with the template it came from', function () {
    actingAsSuperAdmin();

    $response = get('/?sve=1')->assertOk()
        ->assertSee(DemoSeeder::SITE_NAME)
        ->assertSee(DemoSeeder::HERO_HEADING);
    $manifest = manifestOf($response);
    $marks = marksIn($response);

    // The layout, the header and footer, the page template and a partial per block all took part.
    expect(array_keys($manifest))->toContain(
        'layouts/app.blade.php',
        'partials/header.blade.php',
        'partials/footer.blade.php',
        'partials/menu.blade.php',
        'show.blade.php',
        'blocks/hero.blade.php',
        'blocks/text.blade.php',
    )
        ->and($manifest['partials/header.blade.php'])->toMatchArray(['origin' => 'site', 'view' => 'sunrice.partials.header', 'role' => 'partial', 'editable' => true, 'opens' => true])
        ->and($manifest['show.blade.php'])->toMatchArray(['role' => 'entry', 'opens' => true]);

    // Each of them left marks in the page.
    $marked = array_unique(array_column($marks, 0));
    foreach (['layouts/app.blade.php', 'partials/header.blade.php', 'partials/footer.blade.php', 'show.blade.php', 'blocks/hero.blade.php', 'blocks/text.blade.php'] as $path) {
        expect($marked)->toContain($manifest[$path]['key']);
    }

    // And every mark names a file of the manifest and an opening tag in it.
    $files = collect($manifest)->keyBy('key')->all();
    $trees = [];
    foreach ($marks as [$key, $id]) {
        expect($files)->toHaveKey($key);
        $file = $files[$key];
        $directory = $file['origin'] === 'site' ? $this->templates : dirname(WithStarterSite::starterTemplates(), 2).'/resources/views';
        $trees[$key] ??= Template::open(File::get($directory.'/'.$file['path']));
        $node = $trees[$key]->find($id);
        expect($node)->not->toBeNull()
            ->and(Rules::hasAttributes($node))->toBeTrue();
    }
    expect(count($marks))->toBeGreaterThan(20);
});

it('is the same page as the one visitors get, marks aside', function () {
    actingAsSuperAdmin();

    $normal = (string) get('/about')->assertOk()->getContent();
    $editor = (string) get('/about?sve=1')->assertOk()->getContent();

    $stripped = preg_replace(['/ data-sve="[^"]*"/', '#<script type="application/json" id="sve-manifest">.*?</script><script src="[^"]*bridge\.js[^"]*" defer></script>\n#s'], '', $editor);

    expect($normal)->not->toContain('data-sve', 'sve-manifest')
        ->and($editor)->toContain('data-sve="', 'id="sve-manifest"')
        ->and($stripped)->toBe($normal);
});

it('puts the manifest and the bridge script at the end of the body and keeps the page out of caches and search engines', function () {
    actingAsSuperAdmin();

    $response = get('/?sve=1')->assertOk();

    expect((string) $response->getContent())->toMatch('#<script type="application/json" id="sve-manifest">\{.*\}</script><script src="http://localhost/vendor/sunrice-visual-editor/bridge\.js(\?v=[0-9a-f]+)?" defer></script>\s*</body>#s')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});

it('renders as always for everyone else, and for the editor without the parameter', function () {
    $plain = (string) get('/')->assertOk()->getContent();
    expect($plain)->not->toContain('data-sve', 'sve-manifest');

    // A guest asking for an editor render gets the page visitors get.
    expect((string) get('/?sve=1')->assertOk()->getContent())->not->toContain('data-sve', 'sve-manifest');

    // So does someone signed in who may not use the editor.
    $user = createUser();
    $user->givePermissionTo(Permission::findOrCreate('sunrice.access', 'web'));
    actingAs($user);
    $response = get('/?sve=1')->assertOk();
    expect((string) $response->getContent())->not->toContain('data-sve', 'sve-manifest')
        ->and((string) $response->headers->get('X-Robots-Tag'))->not->toContain('noindex');
});

it('leaves no mark behind for the next render', function () {
    $admin = actingAsSuperAdmin();
    $before = (string) get('/')->getContent();

    get('/?sve=1')->assertSee('data-sve="', false);

    // The same person, the same process, no parameter: the page as it was.
    expect((string) get('/')->getContent())->toBe($before);

    // Laravel's compiled views hold no mark; the marked copies are in a directory of their own.
    $compiled = collect(File::allFiles(config('view.compiled')))->filter(fn ($file) => str_contains($file->getContents(), 'data-sve'));
    expect($compiled)->toBeEmpty()
        ->and(File::files(EditorRender::directory()))->not->toBeEmpty()
        ->and($admin)->not->toBeNull();
});

it('shows a template as it is now, and keeps one marked copy of each', function () {
    actingAsSuperAdmin();
    $footer = $this->templates.'/partials/footer.blade.php';

    get('/?sve=1')->assertDontSee('sve-new-paragraph');
    $copies = count(File::files(EditorRender::directory()));
    // Some minutes go by: nothing is drawing the page with these copies any more.
    foreach (File::files(EditorRender::directory()) as $copy) {
        touch($copy->getPathname(), time() - 300);
    }

    app(SaveTemplate::class)->handle('partials/footer.blade.php', sha1(File::get($footer)), File::get($footer)."\n<p class=\"sve-new-paragraph\">New</p>\n");

    $response = get('/?sve=1')->assertOk();
    expect((string) $response->getContent())->toMatch('/<p data-sve="[0-9a-f]+:\d+" class="sve-new-paragraph">New<\/p>/')
        // The copy of the old footer went when the new one was made.
        ->and(count(File::files(EditorRender::directory())))->toBe($copies);
});

it('leaves alone what another page being drawn is writing, or is about to run', function () {
    actingAsSuperAdmin();
    $footer = $this->templates.'/partials/footer.blade.php';
    get('/?sve=1')->assertOk();
    $stem = EditorRender::directory().'/'.hash('xxh128', View::getFinder()->find('sunrice.partials.footer'));
    $copy = collect(glob($stem.'-*.php'))->sole();

    // The frames side by side are drawn again at once after a change, each compiling the changed
    // footer: another request is halfway through writing the same copy.
    app(SaveTemplate::class)->handle('partials/footer.blade.php', sha1(File::get($footer)), File::get($footer)."\n<p class=\"sve-new-paragraph\">New</p>\n");
    get('/?sve=1')->assertOk();
    $new = collect(glob($stem.'-*.php'))->reject(fn (string $file) => $file === $copy)->sole();
    File::delete($new);
    File::put($new.'.0a1b2c3d.tmp', 'half of it');

    get('/?sve=1')->assertOk()->assertSee('sve-new-paragraph');

    expect($new)->toBeFile()
        // What the other request was writing is still there for it to finish with.
        ->and($new.'.0a1b2c3d.tmp')->toBeFile()
        // And so is the copy of the footer as it was: a page that began before the change may be about to run it.
        ->and($copy)->toBeFile();

    // The same page asked for again by three frames: the copy is there, and nothing is written or removed.
    $files = collect(File::files(EditorRender::directory()))->map->getFilename()->sort()->values()->all();
    get('/?sve=1')->assertOk();
    expect(collect(File::files(EditorRender::directory()))->map->getFilename()->sort()->values()->all())->toBe($files);
});

it('renders a template the tree can\'t hold as it is, and says so in the manifest', function () {
    actingAsSuperAdmin();
    // An @if closed inside a tag: Blade compiles it, the tree refuses it.
    File::put($this->templates.'/partials/footer.blade.php', '@if (true) <footer class="sve-odd" @endif><p>Odd footer</p></footer>');

    $response = get('/?sve=1')->assertOk()->assertSee('<footer class="sve-odd" ><p>Odd footer</p></footer>', false);
    $manifest = manifestOf($response);

    expect($manifest['partials/footer.blade.php'])->toMatchArray(['opens' => false, 'editable' => true])
        ->and(array_column(marksIn($response), 0))->not->toContain($manifest['partials/footer.blade.php']['key'])
        ->and($manifest['layouts/app.blade.php']['opens'])->toBeTrue();
});

it('marks what a stray opening tag opens, too', function () {
    actingAsSuperAdmin();
    // The footer's element is opened in one condition and closed in another.
    File::put($this->templates.'/partials/footer.blade.php', "@if (true)\n<footer class=\"sve-split\">\n@endif\n<p>Split footer</p>\n@if (true)\n</footer>\n@endif\n");

    $response = get('/?sve=1')->assertOk();

    expect((string) $response->getContent())->toMatch('/<footer data-sve="[0-9a-f]+:\d+" class="sve-split">\s*<p data-sve="[0-9a-f]+:\d+">Split footer<\/p>\s*<\/footer>/');
});

it('marks Sunrice\'s own view when the site has no template for a page', function () {
    actingAsSuperAdmin();
    File::delete($this->templates.'/show.blade.php');

    $response = get('/about?sve=1')->assertOk();
    $manifest = manifestOf($response);

    expect($manifest)->toHaveKey('defaults/show.blade.php')
        ->and($manifest['defaults/show.blade.php'])->toMatchArray(['origin' => 'package', 'editable' => false, 'copyTo' => 'show.blade.php'])
        ->and(array_column(marksIn($response), 0))->toContain($manifest['defaults/show.blade.php']['key']);
});

it('keeps other pages of the site working: listings, entries, search and a missing page', function (string $url, int $status) {
    actingAsSuperAdmin();

    $response = get($url)->assertStatus($status);

    // The page says what it was answered with: the editor tells a page that failed to draw from one that did.
    expect((string) $response->getContent())->toContain('"status":'.$status.'}</script>');

    if ($status === 200) {
        expect(marksIn($response))->not->toBeEmpty()
            ->and(manifestOf($response))->toHaveKey('layouts/app.blade.php');
    }
})->with([
    ['/articles?sve=1', 200],
    ['/articles/second-post?sve=1', 200],
    ['/search?q=post&sve=1', 200],
    ['/no-such-page?sve=1', 404],
]);

describe('the middleware on its own', function () {
    beforeEach(fn () => actingAsSuperAdmin());

    it('keeps an editor render out of the full-page cache', function () {
        [, $request] = editorRender('/?sve=1', fn () => new Response('<html><body></body></html>'));
        expect($request->attributes->get('responsecache.doNotCache'))->toBeTrue();

        [, $request] = editorRender('/', fn () => new Response('<html><body></body></html>'));
        expect($request->attributes->has('responsecache.doNotCache'))->toBeFalse();
    });

    it('puts Laravel\'s engine back, even when the page fails', function () {
        $engines = app('view.engine.resolver');
        $laravel = $engines->resolve('blade');

        $during = null;
        editorRender('/?sve=1', function () use ($engines, &$during) {
            $during = $engines->resolve('blade');

            return new Response('<html><body></body></html>');
        });
        expect($during)->not->toBe($laravel)
            ->and($engines->resolve('blade'))->toBe($laravel);

        expect(fn () => editorRender('/?sve=1', fn () => throw new RuntimeException('The page failed')))->toThrow(RuntimeException::class, 'The page failed')
            ->and($engines->resolve('blade'))->toBe($laravel);
    });

    it('keeps a redirect inside the site an editor render', function (string $to, string $expected) {
        [$response] = editorRender('http://localhost/old?sve=1', fn () => new RedirectResponse($to));

        expect($response->headers->get('Location'))->toBe($expected);
    })->with([
        'a path' => ['/new', '/new?sve=1'],
        'a path with a query and a fragment' => ['/new?page=2#top', '/new?page=2&sve=1#top'],
        'the same site, in full' => ['http://localhost/new', 'http://localhost/new?sve=1'],
        'already one' => ['/new?sve=1', '/new?sve=1'],
        'another site' => ['https://example.com/new', 'https://example.com/new'],
    ]);

    it('adds a manifest to HTML only', function () {
        [$json] = editorRender('/?sve=1', fn () => response()->json(['a' => 1]));
        [$text] = editorRender('/?sve=1', fn () => new Response('<body>plain</body>', 200, ['Content-Type' => 'text/plain']));
        [$bare] = editorRender('/?sve=1', fn () => new Response('<p>No body tag</p>'));

        expect($json->getContent())->toBe('{"a":1}')
            ->and($text->getContent())->toBe('<body>plain</body>')
            ->and($bare->getContent())->toStartWith('<p>No body tag</p><script type="application/json" id="sve-manifest">{"files":[],"status":200}</script><script src="')
            ->and($bare->getContent())->toEndWith('" defer></script>')
            ->and($json->headers->get('Cache-Control'))->toContain('no-store');
    });
});

it('is on Sunrice\'s frontend routes, after the session', function () {
    $middleware = Route::getRoutes()->getByName('sunrice.frontend.home')->gatherMiddleware();

    expect($middleware)->toContain(EditorRender::class)
        ->and(array_search(EditorRender::class, $middleware, true))->toBeGreaterThan(array_search('web', $middleware, true));
});

it('says in the manifest which entry the page is of, with its page in the admin, and none for a listing', function () {
    actingAsSuperAdmin();
    $entryOf = function (string $path): ?array {
        preg_match('#<script type="application/json" id="sve-manifest">(.*?)</script>#s', (string) get($path.'?sve=1')->assertOk()->getContent(), $match);

        return json_decode($match[1] ?? '{}', true)['entry'] ?? null;
    };
    $about = $entryOf('/about');

    expect($about)->toMatchArray(['title' => 'About'])
        ->and($about['id'])->toBeInt()
        ->and($about['edit'])->toBe("/admin/collections/pages/entries/{$about['id']}/edit")
        ->and(Entry::query()->find($about['id'])?->collection?->handle)->toBe('pages')
        // A listing draws many entries, each with its card: it is of none of them.
        ->and($entryOf('/articles'))->toBeNull()
        // Visitors get no manifest at all.
        ->and((string) get('/about')->getContent())->not->toContain('sve-manifest');
});
