<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Support\SunriceBridge;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request to the editor's API through only for someone who has the
 * permission it takes: `EditorAccess::class.':edit'` for what changes a
 * file, nothing more than opening the editor for what reads. Comes after
 * the panel's own sign-in middleware. A page gets the panel's 403 page,
 * the editor's API a JSON answer it can show.
 */
final class EditorAccess
{
    public function handle(Request $request, Closure $next, string $needs = 'open'): Response
    {
        $permission = match ($needs) {
            'build' => Access::BUILD,
            'code' => Access::CODE,
            'edit' => Access::EDIT,
            default => Access::OPEN,
        };
        if (! Access::may(SunriceBridge::user(), $permission)) {
            // The editor's screen: the panel's own error page.
            abort_unless($request->expectsJson(), 403);

            return response()->json([
                'reason' => 'forbidden',
                'message' => match ($permission) {
                    Access::BUILD => 'You may not run the site\'s build from the visual editor.',
                    Access::CODE => 'You may not edit template code in the visual editor.',
                    Access::EDIT => 'You may look at this in the visual editor, not change it.',
                    default => 'You may not use the visual editor.',
                },
            ], 403);
        }

        return $next($request);
    }
}
