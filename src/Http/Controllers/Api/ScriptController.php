<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sunrice\VisualEditor\Actions\SaveCustomScript;
use Sunrice\VisualEditor\Css\ThemeFile;
use Sunrice\VisualEditor\Rendering\CustomJs;
use Sunrice\VisualEditor\Support\AssetSyntax;

/**
 * The editor's API for the site's custom script: read by anyone who may
 * open the editor, written only by those who may edit code (routes), since
 * it runs in every visitor's browser.
 */
class ScriptController extends Controller
{
    use AnswersAsJson;

    /** The custom script: its text, the version to save it against, and whether pages load it by themselves. */
    public function show(): JsonResponse
    {
        return $this->answer(fn () => ['custom' => $this->described($this->file())]);
    }

    /**
     * Saves the custom script's whole text.
     *
     * Body: {version, source}.
     */
    public function save(Request $request, SaveCustomScript $save): JsonResponse
    {
        return $this->answer(function () use ($request, $save) {
            $file = $this->file();
            $body = $this->body($request);
            $source = $body['source'] ?? null;
            if (! is_string($source) || ! mb_check_encoding($source, 'UTF-8')) {
                throw new InvalidRequest('"source" has to be the script\'s text, as UTF-8.');
            }
            if (strlen($source) > CustomJs::MAX_BYTES) {
                throw new InvalidRequest('That is too large for the custom script.');
            }
            $save->handle($this->version($body, 'script'), $source);

            return ['custom' => $this->described($file)];
        });
    }

    private function file(): ThemeFile
    {
        return CustomJs::file() ?? throw new InvalidRequest('The configuration names no file for custom JS (custom_js.file).');
    }

    /**
     * @return array<string, mixed>
     */
    private function described(ThemeFile $file): array
    {
        $source = $file->source();

        return [
            'path' => $file->path,
            'displayPath' => $file->displayPath(),
            'url' => $file->url,
            'version' => CustomJs::version($source),
            'exists' => is_file($file->file),
            'source' => mb_check_encoding($source, 'UTF-8') ? $source : null,
            'injected' => CustomJs::injected(),
            'stack' => CustomJs::stack(),
            'checked' => AssetSyntax::configured('js'),
        ];
    }
}
