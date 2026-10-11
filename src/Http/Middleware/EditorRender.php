<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\EngineResolver;
use Sunrice\VisualEditor\Rendering\EditorEngine;
use Sunrice\VisualEditor\Rendering\Manifest;
use Sunrice\VisualEditor\Rendering\TailwindPreview;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Templates\TemplateRepository;
use Sunrice\VisualEditor\VisualEditorServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a page of the site for the editor's frame: the same page, with
 * every element marked with the template it came from, and a list of the
 * templates that drew it.
 *
 * It is on Sunrice's frontend routes and does nothing unless the address
 * carries `sve=1` and the person signed in may use the editor. Then, for
 * this one request, Blade's engine is swapped for one that runs marked
 * copies of the templates. Nothing a visitor is served is touched: not the
 * templates, not Laravel's compiled views, not a cached page.
 */
final class EditorRender
{
    /** In the address of a page rendered for the editor: /about?sve=1 */
    public const PARAMETER = 'sve';

    /** The element holding the manifest, as JSON, at the end of the page. */
    public const MANIFEST_ID = 'sve-manifest';

    public function __construct(private readonly TemplateRepository $templates) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query(self::PARAMETER) !== '1' || ! Access::allowed($request->user(config('sunrice.auth.guard')))) {
            return $next($request);
        }

        // Never into a full-page cache (spatie/laravel-responsecache, when Sunrice has it on).
        $request->attributes->set('responsecache.doNotCache', true);

        /** @var EngineResolver $engines */
        $engines = app('view.engine.resolver');
        /** @var BladeCompiler $blade */
        $blade = app('blade.compiler');
        $laravel = $engines->resolve('blade');
        $manifest = new Manifest($this->templates->files());
        $editor = new EditorEngine($blade, app('files'), $manifest, self::directory());

        $engines->register('blade', fn () => $editor);
        try {
            $response = $next($request);
        } finally {
            // Whatever comes next in this process renders as always.
            $engines->register('blade', fn () => $laravel);
        }

        return $this->finish($request, $response, $manifest);
    }

    /** Where marked copies of templates are compiled to: never Laravel's own compiled views. */
    public static function directory(): string
    {
        return storage_path('framework/views-sve');
    }

    private function finish(Request $request, Response $response, Manifest $manifest): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        // The page is shown in a frame of the admin, on the same site.
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        if ($response->isRedirection()) {
            $this->keepParameter($request, $response);

            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || ! str_contains(strtolower((string) $response->headers->get('Content-Type', 'text/html')), 'html')) {
            return $response;
        }

        // On a site whose CSS is built: Tailwind's browser build, so a class added a moment ago has its style here.
        $preview = TailwindPreview::wanted() ? TailwindPreview::tags() : '';
        $head = $preview === '' ? false : stripos($content, '</head>');
        if ($head !== false) {
            $content = substr($content, 0, $head).$preview."\n".substr($content, $head);
        } else {
            $preview = '';
        }

        // "<" and "&" written as escapes: nothing in a template's name can end the script.
        $script = '<script type="application/json" id="'.self::MANIFEST_ID.'">'
            // With what the page was answered with: one that failed to draw is still shown, and the editor says so.
            .json_encode($manifest->toArray() + ['status' => $response->getStatusCode(), 'preview' => $preview !== ''], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            .'</script>'
            // The bridge: tells the editor what the pointer is over and what was clicked.
            .'<script src="'.e(asset(VisualEditorServiceProvider::asset('bridge.js'))).'" defer></script>';
        $end = strripos($content, '</body>');
        $response->setContent($end === false ? $content.$script : substr($content, 0, $end).$script."\n".substr($content, $end));

        return $response;
    }

    /** A redirect to another page of the site stays a page for the editor. */
    private function keepParameter(Request $request, Response $response): void
    {
        $location = (string) $response->headers->get('Location');
        $host = parse_url($location, PHP_URL_HOST);
        if ($location === '' || (is_string($host) && $host !== $request->getHost())) {
            return;
        }
        if (preg_match('/[?&]'.self::PARAMETER.'=/', $location) === 1) {
            return;
        }

        [$address, $fragment] = array_pad(explode('#', $location, 2), 2, null);
        $address .= (str_contains($address, '?') ? '&' : '?').self::PARAMETER.'=1';
        $response->headers->set('Location', $address.($fragment === null ? '' : '#'.$fragment));
    }
}
