<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sunrice\Facades\Sunrice;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Tests\DisabledTestCase;

use function Pest\Laravel\get;

uses(DisabledTestCase::class);

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
});

it('adds nothing to the admin when turned off', function () {
    $sunrice = app(\Sunrice\Sunrice::class);

    expect(implode(' ', $sunrice->adminScripts()))->not->toContain('sunrice-visual-editor')
        ->and(implode(' ', $sunrice->adminStyles()))->not->toContain('sunrice-visual-editor')
        ->and(collect(Sunrice::navigationItems())->pluck('href'))->not->toContain('visual-editor');
});
