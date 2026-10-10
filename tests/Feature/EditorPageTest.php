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
