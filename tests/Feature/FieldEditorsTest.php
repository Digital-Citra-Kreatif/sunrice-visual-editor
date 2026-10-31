<?php

declare(strict_types=1);

use Spatie\Permission\Models\Permission;
use Sunrice\Cms\Models\EntryTranslation;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

uses(WithStarterSite::class);

beforeEach(function () {
    $this->setUpStarterSite();
    $this->about = EntryTranslation::query()->where('title', 'About')->with('entry.collection')->firstOrFail();
    $this->signInFields = function (string ...$permissions) {
        $user = createUser(email: uniqid().'@example.com');
        foreach (['sunrice.access', ...$permissions] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, Access::guard()));
        }
        actingAs($user, Access::guard());
    };
});

it('does not turn visual-editor access into CMS permissions or expose content values', function (string $permission) {
    ($this->signInFields)($permission);
    getJson('/admin/visual-editor/api/field-editors?entry='.$this->about->entry_id)
        ->assertOk()->assertExactJson(['sources' => [], 'entry' => null, 'manage' => []]);
})->with([Access::OPEN, Access::EDIT, Access::CODE]);

it('links the current translation and its actual blueprint to native CMS editors', function () {
    actingAsSuperAdmin();
    config(['sunrice-cms.locales' => ['en', 'id']]);
    $translated = $this->about->replicate();
    $translated->forceFill(['locale' => 'id', 'title' => 'Tentang', 'slug' => 'tentang'])->save();
    $entry = $this->about->entry;
    $response = getJson('/admin/visual-editor/api/field-editors?entry='.$entry->id.'&locale=id')->assertOk();
    expect($response->json('entry.title'))->toBe($entry->translations()->where('locale', 'id')->firstOrFail()->title)
        ->and($response->json('entry.values'))->toBe(route('sunrice.admin.cms.entries.edit', ['entry' => $entry->id, 'locale' => 'id'], false))
        ->and($response->json('entry.definitions.0.url'))->toBe(route('sunrice.admin.cms.blueprints.edit', ['blueprint' => $entry->blueprintHandle()], false))
        ->and($response->json('sources.fieldset:hero.definitions.0.url'))->toBe('/admin/cms/fieldsets/hero/edit')
        ->and($response->json('sources.global:site.values'))->toContain('/cms/globals/site/values')
        ->and($response->json('sources.menu:main.values'))->toContain('/cms/menus/main/links');
    // The links use the CMS's real routes, including its short entry redirect.
    $this->get($response->json('entry.values'))->assertRedirect();
    $this->get($response->json('entry.definitions.0.url'))->assertOk();
});

it('honors scoped content permissions without granting blueprint editing', function () {
    ($this->signInFields)(Access::OPEN, 'cms.entries.pages.view');
    $response = getJson('/admin/visual-editor/api/field-editors?entry='.$this->about->entry_id)->assertOk();
    expect($response->json('entry.values'))->not->toBeNull()
        ->and($response->json('entry.definitions'))->toBe([])
        ->and($response->json('sources.collection:pages.values'))->toBe('/admin/cms/collections/pages/entries')
        ->and($response->json('sources.collection:articles'))->toBeNull()
        ->and($response->json('sources.global:site'))->toBeNull()
        ->and($response->json('sources.fieldset:hero'))->toBeNull();
});

it('honors definition permissions independently of content permissions', function () {
    ($this->signInFields)(Access::OPEN, 'cms.blueprints.update', 'cms.fieldsets.update');
    $response = getJson('/admin/visual-editor/api/field-editors?entry='.$this->about->entry_id)->assertOk();
    expect($response->json('entry'))->toBeNull()
        ->and($response->json('sources.collection:pages.values'))->toBeNull()
        ->and($response->json('sources.collection:pages.definitions'))->not->toBe([])
        ->and($response->json('sources.fieldset:hero.definitions'))->not->toBe([]);
});

it('drops the current-page editor link when its collection has been trashed', function () {
    actingAsSuperAdmin();
    $this->about->entry->collection->delete();
    getJson('/admin/visual-editor/api/field-editors?entry='.$this->about->entry_id)->assertOk()->assertJsonPath('entry', null);
});

it('requires editor access and keeps the editor metadata endpoint read-only', function () {
    ($this->signInFields)('cms.entries.view', 'cms.blueprints.update');
    getJson('/admin/visual-editor/api/field-editors')->assertForbidden();
    actingAsSuperAdmin();
    $this->postJson('/admin/visual-editor/api/field-editors', ['entry' => $this->about->entry_id])->assertNotFound();
});

it('keeps a blueprint source on field groups and supports package view references', function () {
    actingAsSuperAdmin();
    $path = 'blocks/hero.blade.php';
    $version = sha1(file_get_contents($this->templates.'/'.$path));
    $response = getJson('/admin/visual-editor/api/templates/'.$path.'/fields?version='.$version)->assertOk();
    expect($response->json('variables.0.groups.0.source'))->toBe(['subject' => 'fieldset', 'handle' => 'hero']);
    $package = getJson('/admin/visual-editor/api/templates/entry.blade.php?origin=package')->assertOk()->json('template');
    getJson('/admin/visual-editor/api/templates/entry.blade.php/fields?origin=package&version='.$package['version'])
        ->assertOk()->assertJsonPath('version', $package['version']);
    getJson('/admin/visual-editor/api/templates/'.$path.'/fields?version=stale')->assertStatus(409);
});
