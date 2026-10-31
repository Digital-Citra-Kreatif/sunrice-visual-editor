<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sunrice\VisualEditor\Actions\LinkStylesheet;
use Sunrice\VisualEditor\Actions\SaveStylesheet;
use Sunrice\VisualEditor\Css\Changes;
use Sunrice\VisualEditor\Css\Styles;
use Sunrice\VisualEditor\Css\ThemeRepository;
use Sunrice\VisualEditor\Rendering\CustomCss;
use Sunrice\VisualEditor\Templates\Conflict;
use Sunrice\VisualEditor\Templates\TemplateRepository;

/**
 * The editor's API for the theme's stylesheets: what they say about a
 * class, changes to their rules, and linking one from a layout.
 */
class StyleController extends Controller
{
    use AnswersAsJson;

    /** The most changes one request may carry. */
    private const MAX_CHANGES = 100;

    /** A stylesheet up to this size is sent back as it was before a change, to undo the change with. */
    private const MAX_UNDO = 600_000;

    public function __construct(private readonly ThemeRepository $theme, private readonly Styles $styles) {}

    /** The theme's stylesheets and, with `?class=`, the rules that style that class. */
    public function index(Request $request): JsonResponse
    {
        return $this->answer(fn () => ['styles' => $this->styles->for($this->class($request->query('class')))]);
    }

    /**
     * The site's custom CSS: its whole text, the version to save it
     * against, and whether pages link it by themselves. It is saved like
     * any stylesheet, through `source`.
     */
    public function custom(): JsonResponse
    {
        return $this->answer(function () {
            $file = $this->theme->custom();
            if ($file === null) {
                throw new InvalidRequest('The configuration names no stylesheet for custom CSS (custom_css.file).');
            }
            $source = $file->source();

            return ['custom' => [
                'path' => $file->path,
                'displayPath' => $file->displayPath(),
                'url' => $file->url,
                'version' => ThemeRepository::version($source),
                'exists' => $file->exists,
                'source' => mb_check_encoding($source, 'UTF-8') ? $source : null,
                'injected' => CustomCss::injected(),
                'stack' => CustomCss::stack(),
            ]];
        });
    }

    /**
     * Changes rules of a stylesheet and saves it.
     *
     * Body: {version, changes: [{type, …}, …], class?}. See Css\Changes
     * for the changes. With `class`, the answer says what the stylesheets
     * say about that class now.
     */
    public function changes(Request $request, SaveStylesheet $save, string $path): JsonResponse
    {
        return $this->answer(function () use ($request, $save, $path) {
            $body = $this->body($request);
            $version = $this->version($body, 'stylesheet');
            $class = $this->class($body['class'] ?? null);
            $changes = $body['changes'] ?? null;
            if (! is_array($changes) || $changes === [] || ! array_is_list($changes)) {
                throw new InvalidRequest('"changes" has to be a list with at least one change.');
            }
            if (count($changes) > self::MAX_CHANGES) {
                throw new InvalidRequest('That is more than '.self::MAX_CHANGES.' changes in one request.');
            }

            // The places in the changes are those of this version: check it before reading them.
            $before = $this->theme->find($path)->source();
            if (ThemeRepository::version($before) !== $version) {
                throw new Conflict($path, ThemeRepository::version($before));
            }

            return $this->saved($save, $path, $version, $before, Changes::run($before, $changes), $class);
        });
    }

    /**
     * Saves a stylesheet's whole text: how a change is undone, and done again.
     *
     * Body: {version, source, class?}.
     */
    public function source(Request $request, SaveStylesheet $save, string $path): JsonResponse
    {
        return $this->answer(function () use ($request, $save, $path) {
            $body = $this->body($request);
            $source = $body['source'] ?? null;
            if (! is_string($source)) {
                throw new InvalidRequest('"source" has to be the stylesheet\'s text.');
            }
            if (strlen($source) > ThemeRepository::MAX_BYTES) {
                throw new InvalidRequest('That is too large to be a stylesheet.');
            }

            $class = $this->class($body['class'] ?? null);

            return $this->saved($save, $path, $this->version($body, 'stylesheet'), $this->theme->find($path)->source(), $source, $class);
        });
    }

    /**
     * Links a stylesheet of the theme from a layout.
     *
     * Body: {template, version, stylesheet}: the layout's path, the
     * version of it the request was made for, the stylesheet's path.
     */
    public function link(Request $request, LinkStylesheet $link, TemplateRepository $templates): JsonResponse
    {
        return $this->answer(function () use ($request, $link, $templates) {
            $body = $this->body($request);
            $template = $body['template'] ?? null;
            $stylesheet = $body['stylesheet'] ?? null;
            if (! is_string($template) || ! is_string($stylesheet)) {
                throw new InvalidRequest('"template" and "stylesheet" have to be the paths of the layout and of the stylesheet to link from it.');
            }
            $before = $link->handle($template, $this->version($body), $stylesheet);

            return ['template' => $templates->read($template), 'before' => $before];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function saved(SaveStylesheet $save, string $path, string $version, string $before, string $after, ?string $class): array
    {
        $now = $save->handle($path, $version, $after);

        return [
            'file' => ['path' => $path, 'version' => $now],
            // The file as it was, to undo with. Not for a file too large to keep a hundred of.
            'before' => strlen($before) <= self::MAX_UNDO && mb_check_encoding($before, 'UTF-8') ? $before : null,
            'styles' => $this->styles->for($class),
        ];
    }

    /** A class as asked about: one word, or none. */
    private function class(mixed $class): ?string
    {
        if ($class === null || $class === '') {
            return null;
        }
        if (! is_string($class) || strlen($class) > 200 || preg_match('/\s/', $class) === 1 || ! mb_check_encoding($class, 'UTF-8')) {
            throw new InvalidRequest('"class" has to be one class name.');
        }

        return $class;
    }
}
