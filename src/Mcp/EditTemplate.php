<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Sunrice\Admin\Ai\Tool;
use Sunrice\VisualEditor\Actions\SaveTemplate;
use Sunrice\VisualEditor\Blade\EditRejected;
use Sunrice\VisualEditor\Blade\Operations;
use Sunrice\VisualEditor\Guard\CodeGuard;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Support\SunriceBridge;
use Sunrice\VisualEditor\Templates\Conflict;
use Sunrice\VisualEditor\Templates\DoesNotCompile;
use Sunrice\VisualEditor\Templates\TemplateNotFound;
use Sunrice\VisualEditor\Templates\TemplateRepository;

#[Description(<<<'TEXT'
Change a Blade template of the site by operations on its nodes: each changes one thing and leaves the rest of the file as it is written. Read the template with visual_editor_get_template_tree first, and pass its "version" and node ids. The operations are applied together, all or none; the result has to open as a tree and compile, or nothing is saved and the answer says which operation was refused and why. The previous version is kept as a backup. Answers with the new version and tree, and "focus": the node the last operation made or changed.

Operations ({"type": …, "node": "<id>", …}):
- setText {text}: the text of a text node. Written as text: markup or Blade in it is shown, not run.
- setAttribute {name, value, blade?}: value null for an attribute without one. blade: true writes the value as Blade ("{{ $entry->url() }}"); otherwise it is text.
- removeAttribute {name}; addClass {class}; removeClass {class}; setId {id}; setTag {tag}.
- setExpression {expression}: what an echo prints ("$entry->title"). setEchoRaw {raw}: print as HTML ({!! !!}) or as text.
- setArguments {arguments}: what is between the brackets of a directive or of a block's arm: "$entry->featured" for @if, "$entries as $entry" for @foreach.
- insertBefore, insertAfter, append, prepend {blade}: Blade that stands on its own (every tag and block closed), put beside the node or inside it.
- wrap {wrapper}: put the node inside "<div></div>" or "@if (true)\n@endif". unwrap: take the node away and keep what is inside it.
- move {target, position}: position "before", "after" or "inside" the target node. duplicate. remove.
- setCode {code}: rewrite the node as Blade written by hand.

Ids are those of the version you read: after a change, use the tree in the answer. A node whose "can" does not list an action can't take it. Changing templates needs the "Change templates and styles in the visual editor" permission; writing Blade or PHP of your own (an expression, a condition, code inserted) needs "Edit template code" as well. Check the page afterwards with cms_render_page.
TEXT)]
final class EditTemplate extends Tool
{
    protected string $name = 'visual_editor_edit_template';

    /** The most operations one call may carry. */
    private const MAX_OPERATIONS = 200;

    public function handle(Request $request, TemplateRepository $templates, SaveTemplate $save, CodeGuard $guard): Response
    {
        $args = $request->validate([
            'path' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:64'],
            'operations' => ['required', 'array', 'min:1', 'max:'.self::MAX_OPERATIONS],
            'operations.*' => ['array'],
        ]);
        $user = SunriceBridge::user();
        if (! Access::may($user, Access::EDIT)) {
            return Response::error('You may not change templates in the visual editor (it needs the "Change templates and styles in the visual editor" permission, and the editor to be on for this site).');
        }
        if (! filter_var(config('sunrice-visual-editor.agents', true), FILTER_VALIDATE_BOOL)) {
            return Response::error('This site does not let agents change templates through the visual editor (sunrice-visual-editor.agents is off).');
        }

        $operations = [];
        try {
            $file = $templates->find($args['path']);
            $source = (string) file_get_contents($file->file);
            // The ids in the operations are those of this version: check it before reading them.
            if (TemplateRepository::version($source) !== $args['version']) {
                throw new Conflict($args['path'], TemplateRepository::version($source));
            }
            // As the request's own JSON has them: validation would trim a text and make "" null, and both mean something here.
            $operations = array_values((array) $request->get('operations'));

            $applied = Operations::run($source, $operations);
            if (! Access::may($user, Access::CODE)) {
                $guard->operations($file, $source, $operations, $applied->source);
            }
            $save->handle($args['path'], $args['version'], $applied->source);
        } catch (TemplateNotFound $e) {
            return Response::error($e->getMessage().' Call visual_editor_get_template_tree without a path for the list. The CMS\'s own views can\'t be changed.');
        } catch (Conflict $e) {
            return Response::error("Not saved: {$e->getMessage()} Its version is now {$e->current}: read it again with visual_editor_get_template_tree and make the change for that version.");
        } catch (EditRejected $e) {
            $refused = $e->operation === null ? null : ($operations[$e->operation] ?? null);
            $which = $e->operation === null ? '' : ' It was operation '.($e->operation + 1).(is_array($refused) && is_string($refused['type'] ?? null) ? ' ('.$refused['type'].')' : '').'; none of them was applied.';

            return Response::error("Not saved: {$e->getMessage()}{$which}");
        } catch (DoesNotCompile $e) {
            return Response::error("Not saved: the template would not compile: {$e->getMessage()}");
        }

        $now = $templates->read($args['path']);

        return Response::json([
            'saved' => true,
            'path' => $now['path'],
            'version' => $now['version'],
            'focus' => $applied->focus,
            'tree' => is_array($now['tree']) ? Tree::compact($now['tree']) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('The template, inside resources/views/cms: "partials/header.blade.php".')->required(),
            'version' => $schema->string()->description('The "version" visual_editor_get_template_tree (or the last visual_editor_edit_template) gave for it: the change is refused if the file has changed since.')->required(),
            'operations' => $schema->array()->items($schema->object())->description('The operations, in order: [{"type": "addClass", "node": "412", "class": "hero"}, …].')->required(),
        ];
    }
}
