<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sunrice\VisualEditor\Support\Access;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request to the editor's API through only for someone who has the
 * permission it takes: `EditorAccess::class.':edit'` for what changes a
 * file, nothing more than opening the editor for what reads. Comes after
 * the admin's own sign-in middleware.
 */
final class EditorAccess
{
    public function handle(Request $request, Closure $next, string $needs = 'open'): Response
    {
        $permission = $needs === 'edit' ? Access::EDIT : Access::OPEN;
        if (! Access::may($request->user(config('sunrice.auth.guard')), $permission)) {
            return response()->json([
                'reason' => 'forbidden',
                'message' => $permission === Access::EDIT ? 'You may look at this in the visual editor, not change it.' : 'You may not use the visual editor.',
            ], 403);
        }

        return $next($request);
    }
}
