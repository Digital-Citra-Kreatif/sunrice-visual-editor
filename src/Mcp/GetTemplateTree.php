<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Templates\TemplateFile;
use Sunrice\VisualEditor\Templates\TemplateNotFound;
use Sunrice\VisualEditor\Templates\TemplateRepository;

#[IsReadOnly]
#[Description('Read a Blade template as a tree of nodes, to change it with edit_template: small checked edits in place of rewriting the file with write_template. Without a path: the site\'s templates and whether each opens as a tree. With a path: its "version" and "tree". Each node has an "id" (pass it to edit_template with the version it came from), a "type" (element, text, block such as @if…@endif, branch: one arm of a block, atom: an echo, a directive, a component tag), what it holds (name, text, expression, arguments, attributes, source) and "can": what may be done to it. A template that does not open as a tree ("opens": false, with the reason) can only be written whole.')]
class GetTemplateTree extends Tool
{
    protected string $name = 'get_template_tree';

    public function handle(Request $request, TemplateRepository $templates): Response
    {
        $args = $request->validate([
            'path' => ['nullable', 'string', 'max:255'],
            'origin' => ['nullable', 'in:site,package'],
        ]);
        if (! Access::may($request->user(), Access::OPEN)) {
            return Response::error('You may not use the visual editor (it needs the "Open the visual editor" permission, and the editor to be on for this site).');
        }

        if (! isset($args['path']) || $args['path'] === '') {
            return Response::json(['templates' => array_map(fn (array $template) => [
                'path' => $template['path'],
                'origin' => $template['origin'],
                'view' => $template['view'],
                'role' => $template['role'],
                'editable' => $template['editable'],
                'opens' => $template['opens'],
            ] + ($template['opens'] ? [] : ['reason' => $template['reason']]), $templates->all())]);
        }

        try {
            $template = $templates->read($args['path'], ($args['origin'] ?? 'site') === 'package' ? TemplateFile::PACKAGE : TemplateFile::SITE);
        } catch (TemplateNotFound $e) {
            return Response::error($e->getMessage().' Call get_template_tree without a path for the list.');
        }

        return Response::json([
            'path' => $template['path'],
            'origin' => $template['origin'],
            'view' => $template['view'],
            'role' => $template['role'],
            // Sunrice's own views are read-only: copy one to the site to change it.
            'editable' => $template['editable'],
            'version' => $template['version'],
            'opens' => $template['opens'],
            'reason' => $template['reason'],
            'tree' => is_array($template['tree']) ? Tree::compact($template['tree']) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The template, inside resources/views/sunrice: "partials/header.blade.php". Leave out for the list of templates.'),
            'origin' => $schema->string()->enum(['site', 'package'])->description('"site" (default): the site\'s templates. "package": Sunrice\'s own fallback views, read-only.'),
        ];
    }
}
