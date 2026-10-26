<?php

declare(strict_types=1);

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
