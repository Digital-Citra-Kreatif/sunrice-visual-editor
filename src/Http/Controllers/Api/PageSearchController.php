<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sunrice\VisualEditor\Support\SunriceBridge;

/**
 * The panel's command palette (Cmd/Ctrl+K), on every page of the admin:
 * "Edit … in the visual editor" for the pages whose title has what was
 * typed. Each opens the editor on that page of the site.
 */
class PageSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{q?: string|null} $input */
        $input = $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $search = trim($input['q'] ?? '');

        return response()->json(['items' => $search === '' ? [] : array_map(fn (array $page) => [
            'id' => 'visual-editor.'.$page['id'],
            'label' => 'Edit '.$page['title'].' in the visual editor',
            'description' => $page['collection'].' · '.$page['path'],
            'group' => 'Visual editor',
            'icon' => 'layout-template',
            'url' => route('sunrice.admin.visual-editor.show', ['url' => $page['path']]),
        ], SunriceBridge::pages($search))]);
    }
}
