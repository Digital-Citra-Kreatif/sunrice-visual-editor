<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Sunrice\VisualEditor\Fields\Catalog;
use Sunrice\VisualEditor\Fields\Field;
use Sunrice\VisualEditor\Support\SunriceBridge;

/**
 * What the site's content is made of, for the controls that choose from
 * it: the collections with their fields (to list, order and filter
 * entries by) and the taxonomies.
 */
class SiteController extends Controller
{
    public function __invoke(Catalog $catalog): JsonResponse
    {
        return response()->json([
            'collections' => array_map(fn (array $collection) => [
                ...$collection,
                'fields' => array_map(fn (Field $field) => [
                    'handle' => $field->handle,
                    'label' => $field->label,
                    'what' => $field->shape->name(),
                    'kind' => $field->shape->kind,
                ], $catalog->fields('collection', $collection['handle'])),
            ], SunriceBridge::collections()),
            'taxonomies' => SunriceBridge::taxonomies(),
        ]);
    }
}
