<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Sunrice\Admin\Actions\Permissions\SyncPermissions;
use Sunrice\Admin\Panel;
use Sunrice\VisualEditor\Support\Access;
use Workbench\App\Models\User;
use Workbench\App\Seeders\DemoSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(SyncPermissions $sync): void
    {
        // As `sunrice:install` does: the permissions, and a super admin (admin@example.com / Sunrice-Demo-2026!).
        $sync->handle();
        User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => bcrypt('Sunrice-Demo-2026!')],
        )->assignRole(SyncPermissions::superAdminRole());

        // Someone who may change templates in the visual editor and not write code
        // (designer@example.com / Sunrice-Demo-2026!): what the editor holds such a person to can be tried.
        $guard = Access::guard();
        $designer = Role::findOrCreate('designer', $guard);
        $designer->syncPermissions(array_map(fn (string $name) => Permission::findOrCreate($name, $guard), [Panel::ACCESS, Access::EDIT]));
        User::query()->firstOrCreate(
            ['email' => 'designer@example.com'],
            ['name' => 'Designer', 'password' => bcrypt('Sunrice-Demo-2026!')],
        )->assignRole($designer);

        $this->call(DemoSeeder::class);
    }
}
