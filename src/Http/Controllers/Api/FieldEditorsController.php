<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Support\SunriceBridge;

final class FieldEditorsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $locale = $request->query('locale');
        $locale = is_string($locale) && preg_match('/^[A-Za-z0-9_-]{2,20}$/', $locale) === 1 ? $locale : null;

        return response()->json(SunriceBridge::fieldEditors($request->user(Access::guard()), $request->integer('entry'), $locale));
    }
}
