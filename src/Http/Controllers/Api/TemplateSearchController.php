<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sunrice\VisualEditor\Templates\TemplateFile;
use Sunrice\VisualEditor\Templates\TemplateRepository;

/**
 * The panel's command palette (Cmd/Ctrl+K), on every page of the admin:
 * "Open template … in the visual editor" for the templates whose path has
 * what was typed, the site's first. Each opens the editor with that
 * template in the code.
 */
class TemplateSearchController extends Controller
{
    private const LIMIT = 10;

    public function __construct(private readonly TemplateRepository $templates) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{q?: string|null} $input */
        $input = $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $search = self::folded(trim($input['q'] ?? ''));
        if ($search === '') {
            return response()->json(['items' => []]);
        }

        $found = array_filter($this->templates->files(), fn (TemplateFile $file) => str_contains(self::folded($file->path), $search));

        return response()->json(['items' => array_map(function (TemplateFile $file): array {
            $name = substr($file->path, 0, -strlen('.blade.php'));

            return [
                'id' => 'visual-editor.template.'.$file->origin.'.'.$file->path,
                'label' => 'Open template '.$name.' in the visual editor',
                'description' => ($file->origin === TemplateFile::SITE ? 'Site template' : 'Sunrice view, read-only').' · '.$file->path,
                'group' => 'Visual editor',
                'icon' => 'file-code',
                'url' => route('sunrice.admin.visual-editor.show', ['template' => $file->path, 'origin' => $file->origin]),
            ];
        }, array_slice(array_values($found), 0, self::LIMIT))]);
    }

    /** What is typed and what is listed, alike: "Hero" finds "blocks/hero". */
    private static function folded(string $text): string
    {
        return mb_strtolower(class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($text) : $text);
    }
}
