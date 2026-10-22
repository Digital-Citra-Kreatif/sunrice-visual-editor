<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Sunrice\Admin\Panel;
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

it('lets nobody in when turned off, super admins included', function () {
    $admin = actingAsSuperAdmin();

    expect(Access::allowed($admin))->toBeFalse();
    get('/admin/visual-editor')->assertNotFound();
    getJson('/admin/visual-editor/api/templates')->assertNotFound();
    getJson('/admin/visual-editor/api/templates/partials/footer.blade.php')->assertNotFound();
});

it('adds nothing to the panel but its permissions when turned off', function () {
    $panel = app(Panel::class);

    expect(array_keys($panel->getBundles()))->not->toContain('visual-editor')
        ->and(collect($panel->navigationFor(actingAsSuperAdmin()))->flatten()->implode(' '))->not->toContain('visual-editor')
        ->and(array_map(fn (string $tool) => (new $tool)->name(), $panel->getTools()))->not->toContain('visual_editor_edit_template')
        // Roles keep them on a copy where the editor is off.
        ->and(array_keys($panel->getPermissions()))->toContain('visual-editor.open', 'visual-editor.code');
});

it('renders every page as always when turned off, whatever the address says', function () {
    $this->setUpStarterSite();
    actingAsSuperAdmin();

    expect((string) get('/?sve=1')->assertOk()->getContent())->not->toContain('data-sve', 'sve-manifest')
        ->and(Route::getRoutes()->getByName('sunrice.cms.home')->gatherMiddleware())->not->toContain(EditorRender::class);
});

it('still links the site\'s custom CSS when turned off: it is the site\'s, and the deployed site has it', function () {
    $this->setUpStarterSite();
    File::put($this->theme.'/custom.css', '.hero { color: tomato; }');

    expect((string) get('/')->assertOk()->getContent())->toContain('/cms/custom.css?v=', 'data-sunrice-custom-css');
});

it('still loads the site\'s custom script when turned off, and offers no way to write it', function () {
    $this->setUpStarterSite();
    File::put($this->theme.'/custom.js', 'console.log(1);');
    actingAsSuperAdmin();

    expect((string) get('/')->assertOk()->getContent())->toContain('/cms/custom.js?v=', 'data-sunrice-custom-js');
    getJson('/admin/visual-editor/api/scripts/custom')->assertNotFound();
});
