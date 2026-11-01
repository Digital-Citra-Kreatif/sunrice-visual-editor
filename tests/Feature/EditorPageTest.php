<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Sunrice\Admin\Assets\PluginBundle;
use Sunrice\Admin\Panel;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

uses(WithStarterSite::class);

it('shows the editor to a super admin', function () {
    actingAsSuperAdmin();

    get('/admin/visual-editor')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('VisualEditor/Editor', false)
            ->where('startPath', '/'));
});

it('says which widths the page can be shown at, as far as the setting holds widths', function () {
    actingAsSuperAdmin();

    get('/admin/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('widths', [
        ['name' => 'Desktop', 'width' => 1280, 'media' => null],
        ['name' => 'Tablet', 'width' => 768, 'media' => '(max-width: 1024px)'],
        ['name' => 'Mobile', 'width' => 390, 'media' => '(max-width: 640px)'],
    ]));

    config(['sunrice-visual-editor.widths' => [
        ['name' => ' Wide ', 'width' => '1600', 'media' => '@media (min-width: 1400px)'],
        ['name' => 'Breaks out', 'width' => 500, 'media' => '(max-width: 1px) { } body { display: none'],
        ['name' => 'Not in brackets', 'width' => 500, 'media' => 'print'],
        ['name' => 'Too narrow', 'width' => 20],
        ['name' => '', 'width' => 500],
        ['width' => 500],
        'nonsense',
    ]]);
    get('/admin/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('widths', [
        ['name' => 'Wide', 'width' => 1600, 'media' => '(min-width: 1400px)'],
        ['name' => 'Breaks out', 'width' => 500, 'media' => null],
        ['name' => 'Not in brackets', 'width' => 500, 'media' => null],
    ]));

    config(['sunrice-visual-editor.widths' => 'none']);
    get('/admin/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('widths', []));
});

it('offers the site\'s pages in the panel\'s command palette, each opening the editor on it', function () {
    $this->setUpStarterSite();
    $panel = app(Panel::class);
    expect(collect($panel->getCommandSearches())->firstWhere('id', 'visual-editor.pages')['url'])->toBe(route('sunrice.admin.visual-editor.api.pages'));

    actingAsSuperAdmin();
    $items = getJson(route('sunrice.admin.visual-editor.api.pages', ['q' => 'about']))->assertOk()->json('items');
    expect($items)->toBe([[
        'id' => $items[0]['id'],
        'label' => 'Edit About in the visual editor',
        'description' => 'Pages · /about',
        'group' => 'Visual editor',
        'icon' => 'layout-template',
        'url' => route('sunrice.admin.visual-editor.show', ['url' => '/about']),
    ]])->and(getJson(route('sunrice.admin.visual-editor.api.pages', ['q' => '']))->json('items'))->toBe([]);

    // Someone in the admin who may not open the editor is offered nothing.
    $user = createUser();
    $user->givePermissionTo(Permission::findOrCreate('sunrice.access', Access::guard()));
    actingAs($user, Access::guard());
    getJson(route('sunrice.admin.visual-editor.api.pages', ['q' => 'about']))->assertForbidden();
});

it('offers the templates in the panel\'s command palette, each opening the editor with it', function () {
    $this->setUpStarterSite();
    File::put($this->templates.'/partials/hero-banner.blade.php', '<div>Banner</div>');
    $panel = app(Panel::class);
    expect(collect($panel->getCommandSearches())->firstWhere('id', 'visual-editor.templates')['url'])->toBe(route('sunrice.admin.visual-editor.api.template-search'));

    actingAsSuperAdmin();
    $search = fn (string $q) => getJson(route('sunrice.admin.visual-editor.api.template-search', ['q' => $q]))->assertOk()->json('items');
    expect($search('HERO-banner'))->toBe([[
        'id' => 'visual-editor.template.site.partials/hero-banner.blade.php',
        'label' => 'Open template partials/hero-banner in the visual editor',
        'description' => 'Site template · partials/hero-banner.blade.php',
        'group' => 'Visual editor',
        'icon' => 'file-code',
        'url' => route('sunrice.admin.visual-editor.show', ['template' => 'partials/hero-banner.blade.php', 'origin' => 'site']),
    ]])
        ->and(collect($search('entry'))->pluck('url'))->toContain(route('sunrice.admin.visual-editor.show', ['template' => 'entry.blade.php', 'origin' => 'package']))
        ->and($search(''))->toBe([])
        ->and($search('zzz-nothing'))->toBe([])
        // Ten at most.
        ->and(count($search('.blade.php')))->toBe(10);

    // Someone in the admin who may not open the editor is offered nothing.
    $user = createUser();
    $user->givePermissionTo(Permission::findOrCreate('sunrice.access', Access::guard()));
    actingAs($user, Access::guard());
    getJson(route('sunrice.admin.visual-editor.api.template-search', ['q' => 'hero']))->assertForbidden();
});

it('opens with the template named in the link, found among those it lists', function () {
    $this->setUpStarterSite();
    actingAsSuperAdmin();

    get('/admin/visual-editor?template=partials/card.blade.php&origin=site')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('startTemplate', ['path' => 'partials/card.blade.php', 'origin' => 'site'])
        ->where('templateMissing', false));
    get('/admin/visual-editor?template=partials/card.blade.php')->assertInertia(fn (AssertableInertia $page) => $page->where('startTemplate', ['path' => 'partials/card.blade.php', 'origin' => 'site']));
    get('/admin/visual-editor?template=entry.blade.php&origin=package')->assertInertia(fn (AssertableInertia $page) => $page->where('startTemplate', ['path' => 'entry.blade.php', 'origin' => 'package']));
    get('/admin/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('startTemplate', null)->where('templateMissing', false));
});

it('opens with no template, and says so, for one it does not list, without reading what the link named', function (string $query) {
    $this->setUpStarterSite();
    File::put(dirname($this->templates).'/secret.blade.php', 'NOT-FOR-THE-EDITOR');
    actingAsSuperAdmin();

    $response = get('/admin/visual-editor?'.$query)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('startTemplate', null)
        ->where('templateMissing', true));
    expect((string) $response->getContent())->not->toContain('NOT-FOR-THE-EDITOR');
})->with([
    'up and out' => ['template=../secret.blade.php'],
    'far up and out' => ['template=../../.env'],
    'no such template' => ['template=partials/nope'],
    'no such template, in full' => ['template=partials/nope.blade.php'],
    'from nowhere' => ['template=partials/card.blade.php&origin=elsewhere'],
    'a list' => ['template[]=partials/card.blade.php'],
]);

it('says where the code editor\'s script is, for the screen to fetch when code is first shown', function () {
    actingAsSuperAdmin();

    get('/admin/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('codeEditor', fn (string $address) => str_starts_with($address, asset('vendor/sunrice-visual-editor/code-editor.js'))
        // With a mark of the file as built, so a new build is fetched anew.
        && preg_match('/\\?v=[0-9a-f]{10}$/', $address) === 1));

    // And the admin's own pages do not carry it: it is no script of theirs.
    expect((string) get('/admin')->getContent())->not->toContain('code-editor.js');
});

it('opens on the page named in the link, and only on pages of the site', function (string $url, string $expected) {
    actingAsSuperAdmin();

    get('/admin/visual-editor?url='.urlencode($url))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('startPath', $expected));
})->with([
    'a page' => ['/about', '/about'],
    'a page with a query' => ['/articles?page=2', '/articles?page=2'],
    'another site' => ['https://example.com/', '/'],
    'another site, scheme-relative' => ['//example.com/', '/'],
    'a backslash trick' => ['/\\example.com', '/'],
    'a tab a browser would drop' => ["/\t/example.com", '/'],
    'a line break a browser would drop' => ["/\n/example.com", '/'],
    'a carriage return a browser would drop' => ["/\r/example.com", '/'],
    'a tab further in' => ["/ab\tout/x", '/'],
    'a null byte' => ["/ab\0out", '/'],
    'no leading slash' => ['about', '/'],
    'the admin itself' => ['/admin/users', '/'],
]);

it('refuses a signed-in user who may not open it, with the panel\'s own page', function () {
    $user = createUser();
    $user->givePermissionTo(Permission::findOrCreate('sunrice.access', Access::guard()));
    actingAs($user, Access::guard());

    get('/admin/visual-editor')->assertForbidden();
});

it('sends a guest to the sign-in page', function () {
    get('/admin/visual-editor')->assertRedirect('/admin/login');
});

it('registers its route, its script and stylesheet for its own page, and a sidebar link', function () {
    $panel = app(Panel::class);
    $bundle = collect($panel->getBundles())->first(fn (PluginBundle $bundle) => $bundle->id === 'visual-editor');

    expect(Route::has('sunrice.admin.visual-editor.show'))->toBeTrue()
        ->and($panel->hasPlugin('visual-editor'))->toBeTrue()
        // Loaded only when the editor's page opens.
        ->and($bundle->getPages())->toBe(['VisualEditor/'])
        ->and($bundle->getModule())->toContain('vendor/sunrice-visual-editor/visual-editor.js')
        ->and(implode(' ', $bundle->getStyles()))->toContain('vendor/sunrice-visual-editor/visual-editor.css')
        ->and(array_keys($panel->getPermissions()))->toContain(Access::OPEN, Access::EDIT, Access::CODE, Access::BUILD)
        ->and($panel->isPrivilegedPermission(Access::CODE))->toBeTrue()
        ->and(array_map(fn (string $tool) => (new ReflectionClass($tool))->newInstanceWithoutConstructor()->name(), $panel->getTools()))->toContain('visual_editor_get_template_tree', 'visual_editor_edit_template');
});

it('shows the sidebar link to those who may open the editor only', function () {
    $links = fn ($user) => collect(app(Panel::class)->navigationFor($user))->flatMap(fn (array $group) => $group['items'])->pluck('key');

    expect($links(createUser()))->not->toContain('visual-editor')
        ->and($links(createUser(superAdmin: true, email: 'admin@example.com')))->toContain('visual-editor');
});
