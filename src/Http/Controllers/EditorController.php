<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Sunrice\VisualEditor\Actions\Build;
use Sunrice\VisualEditor\Rendering\TailwindPreview;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Support\Widths;
use Sunrice\VisualEditor\VisualEditorServiceProvider;

class EditorController extends Controller
{
    /**
     * The editor's screen. `?url=/about` opens it on that page of the site.
     */
    public function show(Request $request): Response
    {
        $user = $request->user(config('sunrice.auth.guard'));
        abort_unless(Access::allowed($user), 403);

        return Inertia::render('VisualEditor/Editor', [
            'startPath' => static::sitePath($request->query('url')),
            'widths' => Widths::configured(),
            // The code editor is a script of its own, fetched when code is first shown.
            'codeEditor' => asset(VisualEditorServiceProvider::asset('code-editor.js')),
            // What whoever opens it may do, for the screen to offer no more: the API decides for itself.
            'can' => [
                'edit' => Access::may($user, Access::EDIT),
                'code' => Access::may($user, Access::CODE),
                // Where the site has a build command, and for who may run it: the Build button.
                'build' => Build::command() !== null && Access::may($user, Access::BUILD),
            ],
            // The site's own Vite dev server is running: it keeps the CSS up with the templates, and nothing needs building.
            'devServer' => TailwindPreview::devServer(),
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
        // A browser drops tabs and line breaks from an address before reading it: "/<tab>/example.com"
        // is "//example.com" to it, another site. No path of this site has a control character in it.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
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
