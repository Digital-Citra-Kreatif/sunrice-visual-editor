<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sunrice\VisualEditor\Support\Access;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request to the editor's API through only for someone who may use
 * the editor. Comes after the admin's own sign-in middleware.
 */
final class EditorAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Access::allowed($request->user(config('sunrice.auth.guard')))) {
            return response()->json(['reason' => 'forbidden', 'message' => 'You may not use the visual editor.'], 403);
        }

        return $next($request);
    }
}
