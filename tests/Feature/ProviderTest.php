<?php

declare(strict_types=1);

use Spatie\Permission\Models\Permission;
use Sunrice\Admin\Actions\Permissions\SyncPermissions;
use Sunrice\Admin\Panel;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\VisualEditorPlugin;
use Sunrice\VisualEditor\VisualEditorServiceProvider;

it('loads the service provider and its config', function () {
    expect(app()->getProvider(VisualEditorServiceProvider::class))->not->toBeNull()
        ->and(config('sunrice-visual-editor.backups'))->toBeTrue()
        ->and(config('sunrice-visual-editor.theme_css'))->toBe('editor.css');
});

it('lets only those given its permissions use the editor, super admins always', function () {
    expect(Access::allowed(null))->toBeFalse()
        ->and(Access::allowed(createUser()))->toBeFalse()
        ->and(Access::allowed(createUser(email: 'opener@example.com', permissions: Access::OPEN)))->toBeTrue()
        // Each includes the ones above it.
        ->and(Access::allowed(createUser(email: 'coder@example.com', permissions: Access::CODE)))->toBeTrue()
        ->and(Access::allowed(createUser(superAdmin: true, email: 'admin@example.com')))->toBeTrue();
});

it('registers its permissions with the panel, which creates them once however often they are synced', function () {
    $sync = fn () => app(SyncPermissions::class)->handle();
    $sync();
    $sync();

    expect(Permission::query()->where('name', 'like', 'visual-editor.%')->orderBy('name')->pluck('name')->all())
        ->toBe(['visual-editor.build', 'visual-editor.code', 'visual-editor.edit', 'visual-editor.open'])
        // Writing code and running the build are worth an alert when someone gets them.
        ->and(app(Panel::class)->isPrivilegedPermission(Access::CODE))->toBeTrue()
        ->and(app(Panel::class)->isPrivilegedPermission(Access::BUILD))->toBeTrue()
        ->and(app(Panel::class)->isPrivilegedPermission(Access::OPEN))->toBeFalse();
});

it('is a plugin that needs the CMS', function () {
    expect(app(Panel::class)->hasPlugin(VisualEditorPlugin::ID))->toBeTrue()
        ->and(VisualEditorPlugin::make()->requires())->toBe(['cms']);
});
