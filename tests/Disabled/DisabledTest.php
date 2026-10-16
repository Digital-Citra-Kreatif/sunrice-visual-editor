<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Sunrice\Facades\Sunrice;
use Sunrice\VisualEditor\Http\Middleware\EditorRender;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Tests\DisabledTestCase;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

uses(DisabledTestCase::class, WithStarterSite::class);

it('registers no editor routes when turned off', function () {
    $names = collect(Route::getRoutes()->getRoutesByName())->keys()
        ->filter(fn (string $name) => str_starts_with($name, 'sunrice.admin.visual-editor.'));

    expect(Access::enabled())->toBeFalse()
        ->and($names)->toBeEmpty();
});

it('lets nobody in when turned off, Super Admins included', function () {
    $admin = actingAsSuperAdmin();

    expect(Access::allowed($admin))->toBeFalse();
    get('/cms/visual-editor')->assertNotFound();
    getJson('/cms/visual-editor/api/templates')->assertNotFound();
    getJson('/cms/visual-editor/api/templates/partials/footer.blade.php')->assertNotFound();
});

it('adds nothing to the admin when turned off', function () {
    $sunrice = app(\Sunrice\Sunrice::class);

    expect(implode(' ', $sunrice->adminScripts()))->not->toContain('sunrice-visual-editor')
        ->and(implode(' ', $sunrice->adminStyles()))->not->toContain('sunrice-visual-editor')
        ->and(collect(Sunrice::navigationItems())->pluck('href'))->not->toContain('visual-editor');
});

it('renders every page as always when turned off, whatever the address says', function () {
    $this->setUpStarterSite();
    actingAsSuperAdmin();

    expect((string) get('/?sve=1')->assertOk()->getContent())->not->toContain('data-sve', 'sve-manifest')
        ->and(Route::getRoutes()->getByName('sunrice.frontend.home')->gatherMiddleware())->not->toContain(EditorRender::class);
});

it('still links the site\'s custom CSS when turned off: it is the site\'s, and the deployed site has it', function () {
    $this->setUpStarterSite();
    File::put($this->theme.'/custom.css', '.hero { color: tomato; }');

    expect((string) get('/')->assertOk()->getContent())->toContain('/sunrice-theme/custom.css?v=', 'data-sunrice-custom-css');
});
