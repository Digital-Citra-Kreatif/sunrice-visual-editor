<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may use the editor. For now: Super Admins, while the package is
 * enabled. The editor's own permissions replace this later (plan V7.1).
 */
final class Access
{
    public static function enabled(): bool
    {
        return (bool) config('sunrice-visual-editor.enabled', false);
    }

    public static function allowed(?Authenticatable $user): bool
    {
        return self::enabled()
            && $user !== null
            && method_exists($user, 'hasRole')
            && $user->hasRole(config('sunrice.super_admin_role', 'Super Admin'));
    }
}
