<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Sunrice\Facades\Sunrice;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('shows the editor to a Super Admin', function () {
    actingAsSuperAdmin();

    get('/cms/visual-editor')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('VisualEditor/Editor', false)
            ->where('startPath', '/'));
});

it('says which widths the page can be shown at, as far as the setting holds widths', function () {
    actingAsSuperAdmin();

    get('/cms/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('widths', [
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
    get('/cms/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('widths', [
        ['name' => 'Wide', 'width' => 1600, 'media' => '(min-width: 1400px)'],
        ['name' => 'Breaks out', 'width' => 500, 'media' => null],
        ['name' => 'Not in brackets', 'width' => 500, 'media' => null],
    ]));

    config(['sunrice-visual-editor.widths' => 'none']);
    get('/cms/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('widths', []));
});

it('says where the code editor\'s script is, for the screen to fetch when code is first shown', function () {
    actingAsSuperAdmin();

    get('/cms/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('codeEditor', fn (string $address) => str_starts_with($address, asset('vendor/sunrice-visual-editor/code-editor.js'))
        // With a mark of the file as built, so a new build is fetched anew.
        && preg_match('/\\?v=[0-9a-f]{10}$/', $address) === 1));

    // And the admin's own pages do not carry it: it is no script of theirs.
    expect((string) get('/cms')->getContent())->not->toContain('code-editor.js');
});

it('opens on the page named in the link, and only on pages of the site', function (string $url, string $expected) {
    actingAsSuperAdmin();

    get('/cms/visual-editor?url='.urlencode($url))
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
    'the admin itself' => ['/cms/users', '/'],
]);

it('refuses a signed-in user who is not a Super Admin', function () {
    $user = createUser();
    $user->givePermissionTo(Permission::findOrCreate('sunrice.access-admin', config('sunrice.auth.guard', 'web')));
    actingAs($user, config('sunrice.auth.guard', 'web'));

    get('/cms/visual-editor')->assertForbidden();
});

it('sends a guest to the sign-in page', function () {
    get('/cms/visual-editor')->assertRedirect('/cms/login');
});

it('registers its route, admin script, stylesheet and sidebar link', function () {
    $sunrice = app(\Sunrice\Sunrice::class);

    expect(Route::has('sunrice.admin.visual-editor.show'))->toBeTrue()
        ->and(implode(' ', $sunrice->adminScripts()))->toContain('vendor/sunrice-visual-editor/visual-editor.js')
        ->and(implode(' ', $sunrice->adminStyles()))->toContain('vendor/sunrice-visual-editor/visual-editor.css')
        ->and(collect(Sunrice::navigationItems())->pluck('href'))->toContain('visual-editor');
});

it('shows the sidebar link to Super Admins only', function () {
    $item = collect(Sunrice::navigationItems())->firstWhere('href', 'visual-editor');

    expect($item['can'](createUser()))->toBeFalse()
        ->and($item['can'](createUser(superAdmin: true, email: 'admin@example.com')))->toBeTrue();
});
