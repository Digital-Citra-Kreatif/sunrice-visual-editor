<?php

declare(strict_types=1);

use Spatie\Permission\Models\Role;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Tests\TestCase;
use Workbench\App\Models\User;

uses(TestCase::class)->in('Feature', 'Unit');

/** A user, optionally holding the panel's super admin role. */
function createUser(bool $superAdmin = false, string $email = 'user@example.com'): User
{
    $user = User::query()->create([
        'name' => $superAdmin ? 'Admin' : 'Editor',
        'email' => $email,
        'password' => bcrypt('password'),
    ]);

    if ($superAdmin) {
        $user->assignRole(Role::findOrCreate(
            (string) config('sunrice-admin.super_admin_role', 'super-admin'),
            Access::guard(),
        ));
    }

    return $user;
}

/** Sign in as a Super Admin. */
function actingAsSuperAdmin(): User
{
    $user = createUser(superAdmin: true, email: 'admin@example.com');
    test()->actingAs($user, Access::guard());

    return $user;
}
