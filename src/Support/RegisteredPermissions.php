<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Support;

use Spatie\Permission\Models\Permission;
use Sunrice\Permissions\PermissionRegistry;

/**
 * Works around a fault in Sunrice's permission sync, until it is mended
 * there (sunrice-cms#39).
 *
 * `Sunrice::registerPermission()` takes a name of the package's own, not
 * one starting with "sunrice.". The sync then creates every permission the
 * registry names that it does not find, and it looks only among those
 * that start with "sunrice.": a package's permission is created by the
 * first sync and created again by the second, which fails, since it is
 * there. A sync runs whenever a collection, a taxonomy or a form is saved.
 *
 * This is the registry the sync is given: the same, less the packages'
 * permissions that exist already. Nothing else sees it.
 */
final class RegisteredPermissions extends PermissionRegistry
{
    public function names(): array
    {
        $names = parent::names();
        $ofPackages = array_values(array_filter($names, fn (string $name) => ! str_starts_with($name, 'sunrice.')));
        if ($ofPackages === []) {
            return $names;
        }
        $there = Permission::query()
            ->where('guard_name', config('sunrice.auth.guard', 'web'))
            ->whereIn('name', $ofPackages)
            ->pluck('name')
            ->all();

        return array_values(array_diff($names, $there));
    }
}
