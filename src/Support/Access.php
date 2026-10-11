<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may use the editor, and for what. Three permissions, registered
 * with Sunrice and given to roles in its admin (Users → Roles):
 *
 *   visual-editor.open   look: the page, its templates, their layers
 *   visual-editor.edit   change templates and stylesheets without writing code
 *   visual-editor.code   write code: the code panel, and Blade or PHP typed anywhere
 *
 * Each includes the ones above it. Super Admins have all three, as they
 * have every permission. None of it holds while the package is off.
 *
 * And one apart from those, in none of them and holding none of them:
 *
 *   visual-editor.build  run the site's build command (config `build.command`)
 *
 * It runs a program on the server, which is no part of editing a template
 * however much of one may be edited.
 */
final class Access
{
    public const OPEN = 'visual-editor.open';

    public const EDIT = 'visual-editor.edit';

    public const CODE = 'visual-editor.code';

    public const BUILD = 'visual-editor.build';

    /** Each permission with the ones that include it. */
    private const INCLUDED_IN = [
        self::OPEN => [self::OPEN, self::EDIT, self::CODE],
        self::EDIT => [self::EDIT, self::CODE],
        self::CODE => [self::CODE],
        self::BUILD => [self::BUILD],
    ];

    /** What Sunrice's role editor lists: each permission and what it says there. */
    public const LABELS = [
        self::OPEN => 'Open the visual editor',
        self::EDIT => 'Change templates and styles in the visual editor',
        self::CODE => 'Edit template code in the visual editor',
        self::BUILD => 'Run the site\'s build command from the visual editor',
    ];

    public static function enabled(): bool
    {
        return (bool) config('sunrice-visual-editor.enabled', false);
    }

    /** Whether someone may use the editor at all. */
    public static function allowed(?Authenticatable $user): bool
    {
        return self::may($user, self::OPEN);
    }

    /** Whether someone has one of the editor's permissions, by its own name or by one that includes it. */
    public static function may(?Authenticatable $user, string $permission): bool
    {
        if (! self::enabled() || $user === null || ! method_exists($user, 'can')) {
            return false;
        }
        foreach (self::INCLUDED_IN[$permission] ?? [] as $enough) {
            if ($user->can($enough)) {
                return true;
            }
        }

        return false;
    }
}
