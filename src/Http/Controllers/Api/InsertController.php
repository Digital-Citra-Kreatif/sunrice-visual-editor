<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sunrice\VisualEditor\Insert\Library;
use Sunrice\VisualEditor\Support\Access;

/**
 * What can be inserted into a template of this site: each a piece of
 * Blade for an insertBefore, insertAfter or append operation.
 */
class InsertController extends Controller
{
    public function __invoke(Request $request, Library $library): JsonResponse
    {
        return response()->json(['inserts' => $library->all(code: Access::may($request->user(Access::guard()), Access::CODE))]);
    }
}
