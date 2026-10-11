<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Sunrice\VisualEditor\Actions\Build;
use Sunrice\VisualEditor\Actions\BuildRefused;

/**
 * Runs the site's build command, for the editor's Build button. Nothing
 * of the request says what is run: the command is the site's own setting.
 */
class BuildController extends Controller
{
    public function __invoke(Build $build): JsonResponse
    {
        try {
            // A build that fails is still an answer: what it printed says why.
            return response()->json($build->handle());
        } catch (BuildRefused $e) {
            return response()->json(['reason' => 'rejected', 'message' => $e->getMessage()], 422);
        }
    }
}
