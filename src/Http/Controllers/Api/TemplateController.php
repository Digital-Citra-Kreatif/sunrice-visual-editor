<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sunrice\VisualEditor\Actions\CopyPackageView;
use Sunrice\VisualEditor\Actions\SaveTemplate;
use Sunrice\VisualEditor\Blade\EditRejected;
use Sunrice\VisualEditor\Blade\Operations;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Blade\Unsupported;
use Sunrice\VisualEditor\Fields\Picker;
use Sunrice\VisualEditor\Fields\Reference;
use Sunrice\VisualEditor\Guard\CodeGuard;
use Sunrice\VisualEditor\Guard\CodeRefused;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Templates\Backups;
use Sunrice\VisualEditor\Templates\Conflict;
use Sunrice\VisualEditor\Templates\TemplateFile;
use Sunrice\VisualEditor\Templates\TemplateRepository;
use Symfony\Component\HttpFoundation\AcceptHeader;

/**
 * The editor's API for templates: list, read, change, copy.
 *
 * Every answer is JSON. A refusal has a `reason` the screen can act on and
 * a `message` for the person:
 *
 *   forbidden  403  not someone who may use the editor
 *   not_found  404  no such template
 *   conflict   409  the file changed since it was read; `version` is what it is now
 *   rejected   422  an operation can't be made; `operation` says which
 *   syntax     422  the source doesn't compile
 *   invalid    422  the request itself is wrong
 */
class TemplateController extends Controller
{
    use AnswersAsJson;

    /** The most operations one request may carry. */
    private const MAX_OPERATIONS = 200;

    /** The largest source a request may carry, in bytes. Templates are a few kilobytes. */
    private const MAX_SOURCE = 1_000_000;

    public function __construct(private readonly TemplateRepository $templates) {}

    /** Every template of the site, then Sunrice's own views. */
    public function index(): JsonResponse
    {
        return response()->json(['templates' => $this->templates->all()]);
    }

    /** One template: its source, version and tree. `?origin=package` for one of Sunrice's views. */
    public function show(Request $request, string $path): JsonResponse
    {
        return $this->answer(fn () => ['template' => $this->templates->read($path, $this->origin($request))]);
    }

    /**
     * Applies operations to a template and saves it.
     *
     * Body: {version, operations: [{type, node, …}, …]}.
     */
    public function operations(Request $request, SaveTemplate $save, CodeGuard $guard, string $path): JsonResponse
    {
        return $this->answer(function () use ($request, $save, $guard, $path) {
            $body = $this->body($request);
            $version = $this->version($body);
            $operations = $body['operations'] ?? null;
            if (! is_array($operations) || $operations === [] || ! array_is_list($operations)) {
                throw new InvalidRequest('"operations" has to be a list with at least one operation.');
            }
            if (count($operations) > self::MAX_OPERATIONS) {
                throw new InvalidRequest('That is more than '.self::MAX_OPERATIONS.' operations in one request.');
            }
            foreach ($operations as $operation) {
                if (! is_array($operation)) {
                    throw new InvalidRequest('Each operation has to be an object with a "type" and a "node".');
                }
            }

            // The ids in the operations are those of this version: check it before reading them.
            $source = (string) file_get_contents($this->templates->find($path)->file);
            if (TemplateRepository::version($source) !== $version) {
                throw new Conflict($path, TemplateRepository::version($source));
            }

            $applied = Operations::run($source, $operations);
            if (! $this->mayCode($request)) {
                $guard->operations($this->templates->find($path), $source, $operations, $applied->source);
            }
            $save->handle($path, $version, $applied->source);

            return ['template' => $this->templates->read($path), 'focus' => $applied->focus];
        });
    }

    /**
     * Saves a template's source as written in the code panel.
     *
     * Body: {version, source}.
     */
    public function source(Request $request, SaveTemplate $save, CodeGuard $guard, string $path): JsonResponse
    {
        return $this->answer(function () use ($request, $save, $guard, $path) {
            $body = $this->body($request);
            $source = $body['source'] ?? null;
            if (! is_string($source)) {
                throw new InvalidRequest('"source" has to be the template\'s text.');
            }
            if (strlen($source) > self::MAX_SOURCE) {
                throw new InvalidRequest('That is too large to be a template.');
            }

            if (! $this->mayCode($request)) {
                // Without code permission, source writes may only replay an exact saved
                // version for undo/redo. Arbitrary source cannot bypass the operation guard.
                $file = $this->templates->find($path);
                if (! Backups::had($file, $source)) {
                    // What the guard says holds for the text it compared with: that has to be the
                    // version the save then replaces, or code taken out in between could come back.
                    $before = (string) file_get_contents($file->file);
                    if (TemplateRepository::version($before) !== $this->version($body)) {
                        throw new Conflict($path, TemplateRepository::version($before));
                    }
                    try {
                        $guard->source($before, $source);
                    } catch (CodeRefused $e) {
                        $current = (string) file_get_contents($file->file);
                        if (TemplateRepository::version($current) !== $this->version($body)) {
                            throw new Conflict($path, TemplateRepository::version($current));
                        }
                        throw $e;
                    }
                }
            }
            $save->handle($path, $this->version($body), $source);

            return ['template' => $this->templates->read($path)];
        });
    }

    /**
     * What can be read at a place in a template: the variables there,
     * with their fields and the Blade a click on each writes.
     *
     * `?version=` the version the node's id is of; `?node=` the place
     * (none: the template as a whole); `?inside=1` for something that
     * goes into the node, not in its place.
     */
    public function fields(Request $request, Picker $picker, string $path): JsonResponse
    {
        $response = $this->answer(function () use ($request, $picker, $path) {
            $file = $this->templates->find($path, $this->origin($request));
            $source = (string) file_get_contents($file->file);
            $version = $request->query('version');
            if (! is_string($version) || $version === '') {
                throw new InvalidRequest('"version" has to be the version of the template the place is in.');
            }
            if (TemplateRepository::version($source) !== $version) {
                throw new Conflict($path, TemplateRepository::version($source));
            }
            try {
                $template = Template::open($source);
            } catch (Unsupported $e) {
                throw new EditRejected('This template can only be edited as code: '.lcfirst($e->getMessage()).'.');
            }
            $id = $request->query('node');
            $node = is_string($id) && $id !== '' ? $template->find($id) : null;
            if (is_string($id) && $id !== '' && $node === null) {
                throw new InvalidRequest('The template has no node "'.$id.'".');
            }

            $variables = $picker->at($file, $template, $node, $request->boolean('inside'));

            return ['version' => $version, 'variables' => $request->boolean('reference') ? Reference::variables($variables) : $variables];
        });
        if ($request->boolean('reference')) {
            // Parent panel middleware may replace Vary. Never let a cache reuse
            // an encoded response for a client that asked for identity.
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->setVary('Accept-Encoding', false);
            $encodings = AcceptHeader::fromString($request->header('Accept-Encoding', ''));
            $gzip = $encodings->get('gzip') ?? $encodings->get('*');
            if ($response->isOk() && $gzip !== null && $gzip->getQuality() > 0 && function_exists('gzencode')) {
                $compressed = gzencode($response->getContent(), 6);
                if ($compressed !== false) {
                    $response->setContent($compressed);
                    $response->headers->set('Content-Encoding', 'gzip');
                }
            }
        }

        return $response;
    }

    /**
     * What can be read at a place in a template's text as it is being
     * typed: nothing is saved and nothing is run, the text is only read.
     *
     * Body: {source, offset}, the offset in bytes. A text that does not
     * open as a tree (a tag half typed) is answered with what the file is
     * given and what every template has, and `exact` false.
     */
    public function fieldsAt(Request $request, Picker $picker, string $path): JsonResponse
    {
        return $this->answer(function () use ($request, $picker, $path) {
            $body = $this->body($request);
            $source = $body['source'] ?? null;
            $offset = $body['offset'] ?? null;
            if (! is_string($source) || strlen($source) > self::MAX_SOURCE) {
                throw new InvalidRequest('"source" has to be the template\'s text.');
            }
            if (! is_int($offset) || $offset < 0) {
                throw new InvalidRequest('"offset" has to be a place in the text, in bytes.');
            }
            $file = $this->templates->find($path);

            try {
                $template = Template::open($source);
            } catch (Unsupported $e) {
                return ['variables' => $picker->anywhere($file, $source), 'exact' => false, 'why' => $e->getMessage()];
            }
            [$node, $inside] = $template->at(min($offset, strlen($source)));

            return ['variables' => $picker->at($file, $template, $node, $inside), 'exact' => true, 'why' => null];
        });
    }

    /** Copies one of Sunrice's fallback views into the site and answers with the new template. */
    public function copy(CopyPackageView $copy, string $path): JsonResponse
    {
        return $this->answer(fn () => ['template' => $this->templates->read($copy->handle($path))], 201);
    }

    /** Whether whoever asks may edit code: put Blade and PHP of their own into a template. */
    private function mayCode(Request $request): bool
    {
        return Access::may($request->user(Access::guard()), Access::CODE);
    }

    private function origin(Request $request): string
    {
        return $request->query('origin') === TemplateFile::PACKAGE ? TemplateFile::PACKAGE : TemplateFile::SITE;
    }
}
