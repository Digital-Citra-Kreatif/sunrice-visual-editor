<?php

declare(strict_types=1);

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
