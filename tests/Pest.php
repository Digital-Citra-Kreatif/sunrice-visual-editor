<?php

declare(strict_types=1);

use Sunrice\Admin\Actions\Permissions\SyncPermissions;
use Sunrice\Admin\Panel;
use Sunrice\VisualEditor\Tests\TestCase;
use Workbench\App\Models\User;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * A user who may open the panel, with these permissions besides; or a
 * super admin, who has every permission.
 */
function createUser(bool $superAdmin = false, string $email = 'user@example.com', string ...$permissions): User
{
    app(SyncPermissions::class)->handle();
    $user = User::query()->create([
        'name' => $superAdmin ? 'Admin' : 'Editor',
        'email' => $email,
        'password' => bcrypt('password'),
    ]);
    $user->givePermissionTo([Panel::ACCESS, ...$permissions]);
    if ($superAdmin) {
        $user->assignRole(SyncPermissions::superAdminRole());
    }

    return $user;
}

/** Sign in as a super admin. */
function actingAsSuperAdmin(): User
{
    $user = createUser(superAdmin: true, email: 'admin@example.com');
    test()->actingAs($user);

    return $user;
}
