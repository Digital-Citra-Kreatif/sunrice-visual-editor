<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Sunrice\Admin\Panel;
use Sunrice\Cms\Models\EntryTranslation;
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

it('adds nothing to the admin when turned off', function () {
    $panel = app(Panel::class);

    expect($panel->hasPlugin('visual-editor'))->toBeTrue()
        ->and(collect($panel->getBundles())->pluck('id'))->not->toContain('visual-editor')
        ->and(array_keys($panel->getPermissions()))->not->toContain(Access::OPEN)
        ->and(collect($panel->getCommandSearches())->pluck('id'))->not->toContain('visual-editor.pages')
        ->and(collect($panel->navigationFor(createUser(superAdmin: true)))->flatMap(fn (array $group) => $group['items'])->pluck('key'))->not->toContain('visual-editor')
        ->and(array_map(fn (string $tool) => (new ReflectionClass($tool))->newInstanceWithoutConstructor()->name(), $panel->getTools()))->not->toContain('visual_editor_edit_template');
});

it('adds no link to the entry pages when turned off', function () {
    $this->setUpStarterSite();
    actingAsSuperAdmin();
    $about = EntryTranslation::query()->where('title', 'About')->with('entry.collection')->firstOrFail();

    get(route('sunrice.admin.cms.collections.entries.edit', ['collection' => $about->entry->collection->handle, 'entry' => $about->entry_id]))
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('links', []));
});

it('renders every page as always when turned off, whatever the address says', function () {
    $this->setUpStarterSite();
    actingAsSuperAdmin();

    expect((string) get('/?sve=1')->assertOk()->getContent())->not->toContain('data-sve', 'sve-manifest')
        ->and(config('sunrice-cms.site.middleware'))->not->toContain(EditorRender::class);
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
