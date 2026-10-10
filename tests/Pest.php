<?php

declare(strict_types=1);

use Spatie\Permission\Models\Role;
use Sunrice\VisualEditor\Tests\TestCase;
use Workbench\App\Models\User;

uses(TestCase::class)->in('Feature', 'Unit');

/** A user, optionally holding Sunrice's Super Admin role. */
function createUser(bool $superAdmin = false, string $email = 'user@example.com'): User
{
    $user = User::query()->create([
        'name' => $superAdmin ? 'Admin' : 'Editor',
        'email' => $email,
        'password' => bcrypt('password'),
    ]);

    if ($superAdmin) {
        $user->assignRole(Role::findOrCreate(
            config('sunrice.super_admin_role'),
            config('sunrice.auth.guard', 'web'),
        ));
    }

    return $user;
}

/** Sign in as a Super Admin. */
function actingAsSuperAdmin(): User
{
    $user = createUser(superAdmin: true, email: 'admin@example.com');
    test()->actingAs($user, config('sunrice.auth.guard', 'web'));

    return $user;
}
