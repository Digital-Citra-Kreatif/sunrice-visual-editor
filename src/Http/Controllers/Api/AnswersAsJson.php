<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers\Api;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sunrice\VisualEditor\Blade\EditRejected;
use Sunrice\VisualEditor\Css\CssRejected;
use Sunrice\VisualEditor\Css\StylesheetNotFound;
use Sunrice\VisualEditor\Templates\Conflict;
use Sunrice\VisualEditor\Templates\CopyRefused;
use Sunrice\VisualEditor\Templates\DoesNotCompile;
use Sunrice\VisualEditor\Templates\TemplateNotFound;

/**
 * How the editor's API answers: JSON, and a refusal with a `reason` the
 * screen can act on and a `message` for the person.
 *
 *   forbidden  403  not someone who may use the editor
 *   not_found  404  no such template or stylesheet
 *   conflict   409  the file changed since it was read; `version` is what it is now
 *   rejected   422  a change can't be made; `operation` or `change` says which of a list
 *   syntax     422  the source doesn't compile
 *   invalid    422  the request itself is wrong
 */
trait AnswersAsJson
{
    /**
     * Runs the work and turns what it returns, or refuses with, into JSON.
     *
     * @param  Closure(): array<string, mixed>  $work
     */
    private function answer(Closure $work, int $status = 200): JsonResponse
    {
        try {
            return response()->json($work(), $status);
        } catch (TemplateNotFound|StylesheetNotFound $e) {
            return $this->refuse('not_found', $e->getMessage(), 404);
        } catch (Conflict $e) {
            return $this->refuse('conflict', $e->getMessage().' Load it again before changing it.', 409, ['version' => $e->current]);
        } catch (EditRejected $e) {
            return $this->refuse('rejected', $e->getMessage(), 422, ['operation' => $e->operation]);
        } catch (CssRejected $e) {
            return $this->refuse('rejected', $e->getMessage(), 422, ['change' => $e->change]);
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

    /**
     * The request's JSON, read from the body as it was sent.
     *
     * Not through $request->input(): Laravel trims the text it finds
     * there and turns "" into null, and a template's leading blank line, a
     * snippet's last newline and an empty attribute value all mean
     * something here.
     *
     * @return array<array-key, mixed>
     */
    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        if (! is_array($body) || array_is_list($body)) {
            throw new InvalidRequest('The request has to be a JSON object.');
        }

        return $body;
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function version(array $body, string $of = 'template'): string
    {
        $version = $body['version'] ?? null;
        if (! is_string($version) || $version === '') {
            throw new InvalidRequest("\"version\" has to be the version of the {$of} the change was made for.");
        }

        return $version;
    }
}
