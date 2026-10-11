<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Sunrice\Permissions\SyncPermissions;
use Sunrice\VisualEditor\Support\Access;
use Workbench\App\Models\User;
use Workbench\App\Seeders\DemoSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Same setup as `sunrice:install`: permissions, the Super Admin
        // role and a user holding it (admin@example.com / password).
        app(SyncPermissions::class)->handle();
        $role = Role::findOrCreate(
            config('sunrice.super_admin_role', 'Super Admin'),
            config('sunrice.auth.guard', 'web'),
        );

        User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => bcrypt('password')],
        )->assignRole($role);

        // Someone who may change templates in the visual editor and not write code
        // (designer@example.com / password): what the editor holds such a person to can be tried.
        $designer = Role::findOrCreate('Designer', config('sunrice.auth.guard', 'web'));
        $designer->syncPermissions(array_map(
            fn (string $name) => Permission::findOrCreate($name, config('sunrice.auth.guard', 'web')),
            ['sunrice.access-admin', Access::EDIT],
        ));
        User::query()->firstOrCreate(
            ['email' => 'designer@example.com'],
            ['name' => 'Designer', 'password' => bcrypt('password')],
        )->assignRole($designer);

        $this->call(DemoSeeder::class);
    }
}
