<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Sunrice\VisualEditor\Support\Access;

class EditorController extends Controller
{
    /**
     * The editor's screen. `?url=/about` opens it on that page of the site.
     */
    public function show(Request $request): Response
    {
        abort_unless(Access::allowed($request->user(config('sunrice.auth.guard'))), 403);

        return Inertia::render('VisualEditor/Editor', [
            'startPath' => static::sitePath($request->query('url')),
        ]);
    }

    /**
     * A path on this site from what the link carried; anything else (another
     * host, a scheme, the admin itself) opens the homepage instead.
     */
    public static function sitePath(mixed $url): string
    {
        if (! is_string($url) || $url === '' || $url[0] !== '/' || str_starts_with($url, '//') || str_contains($url, '\\')) {
            return '/';
        }

        $admin = '/'.trim((string) config('sunrice.admin.path', 'cms'), '/');
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === $admin || str_starts_with($path, $admin.'/')) {
            return '/';
        }

        return $url;
    }
}
