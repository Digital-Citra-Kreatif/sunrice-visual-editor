<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Tests\DisabledTestCase;

uses(DisabledTestCase::class);

it('registers no editor routes when turned off', function () {
    $names = collect(Route::getRoutes()->getRoutesByName())->keys()
        ->filter(fn (string $name) => str_starts_with($name, 'sunrice.admin.visual-editor.'));

    expect(Access::enabled())->toBeFalse()
        ->and($names)->toBeEmpty();
});

it('lets nobody in when turned off, Super Admins included', function () {
    expect(Access::allowed(createUser(superAdmin: true, email: 'admin@example.com')))->toBeFalse();
});
