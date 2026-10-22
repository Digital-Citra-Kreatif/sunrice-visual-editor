<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Sunrice\Admin\Panel;
use Sunrice\Cms\Models\EntryTranslation;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

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

it('offers "Edit with Visual Editor" on a live entry\'s page in the panel, to those who may open the editor', function () {
    $this->setUpStarterSite();
    $about = EntryTranslation::query()->where('title', 'About')->firstOrFail();
    $page = '/admin/cms/collections/pages/entries/'.$about->entry_id.'/edit';

    actingAsSuperAdmin();
    get($page)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('links', [['label' => 'Edit with Visual Editor', 'href' => '/admin/visual-editor?url=%2Fabout']]));

    // Someone who may edit the entry and not open the editor is offered nothing.
    actingAs(createUser(email: 'writer@example.com', permissions: 'cms.entries.view'));
    get($page)->assertInertia(fn (AssertableInertia $page) => $page->where('links', []));
});

it('says where the code editor\'s script is, for the screen to fetch when code is first shown', function () {
    actingAsSuperAdmin();

    get('/admin/visual-editor')->assertInertia(fn (AssertableInertia $page) => $page->where('codeEditor', fn (string $address) => str_starts_with($address, asset('vendor/sunrice-visual-editor/code-editor.js'))
        // With a mark of the file as built, so a new build is fetched anew.
        && preg_match('/\\?v=[0-9a-f]{10}$/', $address) === 1));

    // And the panel's other pages do not carry it: it is no script of theirs.
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

it('refuses someone in the panel who may not open the editor', function () {
    actingAs(createUser());

    get('/admin/visual-editor')->assertForbidden();
});

it('sends a guest to the sign-in page', function () {
    get('/admin/visual-editor')->assertRedirect('/admin/login');
});

it('registers its route, its bundle for its own pages and its sidebar link', function () {
    $panel = app(Panel::class);
    $bundle = $panel->getBundles()['visual-editor']->toArray();

    expect(Route::has('sunrice.admin.visual-editor.show'))->toBeTrue()
        ->and($bundle['module'])->toContain('vendor/sunrice-visual-editor/visual-editor.js')
        ->and(implode(' ', $bundle['styles']))->toContain('vendor/sunrice-visual-editor/visual-editor.css')
        ->and($bundle['pages'])->toBe(['VisualEditor/']);
});

it('shows the sidebar link to those who may open the editor', function () {
    $links = fn ($user) => collect(app(Panel::class)->navigationFor($user))->flatten()->implode(' ');

    expect($links(createUser()))->not->toContain('visual-editor')
        ->and($links(createUser(email: 'opener@example.com', permissions: Access::OPEN)))->toContain('visual-editor')
        ->and($links(createUser(superAdmin: true, email: 'admin@example.com')))->toContain('visual-editor');
});
