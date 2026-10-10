<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Sunrice\Permissions\SyncPermissions;
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

        $this->call(DemoSeeder::class);
    }
}
