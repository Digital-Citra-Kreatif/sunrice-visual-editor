<?php

declare(strict_types=1);

use Spatie\Permission\Models\Permission;
use Sunrice\Permissions\SyncPermissions;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\VisualEditorServiceProvider;

it('loads the service provider and its config', function () {
    expect(app()->getProvider(VisualEditorServiceProvider::class))->not->toBeNull()
        ->and(config('sunrice-visual-editor.backups'))->toBeTrue()
        ->and(config('sunrice-visual-editor.theme_css'))->toBe('editor.css');
});

it('lets only Super Admins use the editor', function () {
    expect(Access::allowed(null))->toBeFalse()
        ->and(Access::allowed(createUser()))->toBeFalse()
        ->and(Access::allowed(createUser(superAdmin: true, email: 'admin@example.com')))->toBeTrue();
});

it('has Sunrice create its permissions once, however often permissions are synced', function () {
    // Sunrice's sync would create a package's permission again each time, and fail (sunrice-cms#39).
    $sync = fn () => app(SyncPermissions::class)->handle();
    $sync();
    $sync();
    $sync();

    expect(Permission::query()->where('name', 'like', 'visual-editor.%')->orderBy('name')->pluck('name')->all())
        ->toBe(['visual-editor.code', 'visual-editor.edit', 'visual-editor.open'])
        // Sunrice's own are synced as they were.
        ->and(Permission::query()->where('name', 'sunrice.access-admin')->count())->toBe(1);
});
