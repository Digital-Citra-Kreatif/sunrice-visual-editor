<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers\Api;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sunrice\VisualEditor\Actions\CopyPackageView;
use Sunrice\VisualEditor\Actions\SaveTemplate;
use Sunrice\VisualEditor\Blade\EditRejected;
use Sunrice\VisualEditor\Blade\Operations;
use Sunrice\VisualEditor\Templates\Conflict;
use Sunrice\VisualEditor\Templates\CopyRefused;
use Sunrice\VisualEditor\Templates\DoesNotCompile;
use Sunrice\VisualEditor\Templates\TemplateFile;
use Sunrice\VisualEditor\Templates\TemplateNotFound;
use Sunrice\VisualEditor\Templates\TemplateRepository;

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
    public function operations(Request $request, SaveTemplate $save, string $path): JsonResponse
    {
        return $this->answer(function () use ($request, $save, $path) {
            $version = $this->version($request);
            $operations = $request->input('operations');
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
            $save->handle($path, $version, $applied->source);

            return ['template' => $this->templates->read($path), 'focus' => $applied->focus];
        });
    }

    /**
     * Saves a template's source as written in the code panel.
     *
     * Body: {version, source}.
     */
    public function source(Request $request, SaveTemplate $save, string $path): JsonResponse
    {
        return $this->answer(function () use ($request, $save, $path) {
            $source = $request->input('source');
            if (! is_string($source)) {
                throw new InvalidRequest('"source" has to be the template\'s text.');
            }
            if (strlen($source) > self::MAX_SOURCE) {
                throw new InvalidRequest('That is too large to be a template.');
            }

            $save->handle($path, $this->version($request), $source);

            return ['template' => $this->templates->read($path)];
        });
    }

    /** Copies one of Sunrice's fallback views into the site and answers with the new template. */
    public function copy(CopyPackageView $copy, string $path): JsonResponse
    {
        return $this->answer(fn () => ['template' => $this->templates->read($copy->handle($path))], 201);
    }

    private function version(Request $request): string
    {
        $version = $request->input('version');
        if (! is_string($version) || $version === '') {
            throw new InvalidRequest('"version" has to be the version of the template the change was made for.');
        }

        return $version;
    }

    private function origin(Request $request): string
    {
        return $request->query('origin') === TemplateFile::PACKAGE ? TemplateFile::PACKAGE : TemplateFile::SITE;
    }

    /**
     * Runs the work and turns what it returns, or refuses with, into JSON.
     *
     * @param  Closure(): array<string, mixed>  $work
     */
    private function answer(Closure $work, int $status = 200): JsonResponse
    {
        try {
            return response()->json($work(), $status);
        } catch (TemplateNotFound $e) {
            return $this->refuse('not_found', $e->getMessage(), 404);
        } catch (Conflict $e) {
            return $this->refuse('conflict', $e->getMessage().' Load it again before changing it.', 409, ['version' => $e->current]);
        } catch (EditRejected $e) {
            return $this->refuse('rejected', $e->getMessage(), 422, ['operation' => $e->operation]);
        } catch (CopyRefused $e) {
            return $this->refuse('rejected', $e->getMessage(), 422);
        } catch (DoesNotCompile $e) {
            return $this->refuse('syntax', $e->getMessage(), 422);
        } catch (InvalidRequest $e) {
            return $this->refuse('invalid', $e->getMessage(), 422);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function refuse(string $reason, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['reason' => $reason, 'message' => $message] + $extra, $status);
    }
}
