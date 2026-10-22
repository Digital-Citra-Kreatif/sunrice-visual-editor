<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Testing\TestListResponse;
use Laravel\Mcp\Server\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Sunrice\Admin\Actions\Ai\CreateAiToken;
use Sunrice\Admin\Activity\Activity as ActivityLog;
use Sunrice\Admin\Panel;
use Sunrice\VisualEditor\Blade\Operation;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Mcp\EditTemplate;
use Sunrice\VisualEditor\Mcp\GetTemplateTree;
use Sunrice\VisualEditor\Mcp\Tree;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

uses(WithStarterSite::class);

/** A server with only the package's tools, to call them as a user without a token (the panel's own server is tried at the end). */
class VisualEditorToolsServer extends Server
{
    protected array $tools = [GetTemplateTree::class, EditTemplate::class];
}

/** What a tool answered: its JSON, or its refusal under "error". */
function sveToolResult(TestResponse $response): array
{
    $result = (new ReflectionProperty(TestResponse::class, 'response'))->getValue($response)->toArray()['result'] ?? [];
    $text = (string) ($result['content'][0]['text'] ?? '');

    return ($result['isError'] ?? false) ? ['error' => $text] : (json_decode($text, true) ?? ['text' => $text]);
}

const SVE_MCP_FOOTER = 'partials/footer.blade.php';

beforeEach(function () {
    $this->setUpStarterSite();
    File::deleteDirectory(storage_path('app/sunrice-cms/template-backups'));
    $this->footer = $this->templates.'/'.SVE_MCP_FOOTER;
    $this->original = File::get($this->footer);
    // Every page draws the site's footer partial, after the layout's own.
    $layout = $this->templates.'/layout.blade.php';
    File::put($layout, str_replace('</body>', "@include('cms.partials.footer')\n</body>", File::get($layout)));

    $this->with = function (string ...$permissions) {
        $user = createUser(email: 'agent'.count($permissions).'@example.com');
        foreach (['sunrice.access', ...$permissions] as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name));
        }

        return $user;
    };
    $this->tree = fn ($user, array $arguments = []) => sveToolResult(VisualEditorToolsServer::actingAs($user)->tool(GetTemplateTree::class, $arguments));
    $this->edit = fn ($user, array $operations, array $more = []) => sveToolResult(VisualEditorToolsServer::actingAs($user)->tool(EditTemplate::class, $more + [
        'path' => SVE_MCP_FOOTER, 'version' => sha1(File::get($this->footer)), 'operations' => $operations,
    ]));
    /** The id of the first node of the footer whose own text has $marker in it. */
    $this->node = function (string $marker): string {
        foreach (Template::open(File::get($this->footer))->root->walk() as $node) {
            if (str_contains($node->open, $marker)) {
                return $node->id();
            }
        }
        throw new RuntimeException("No node has \"{$marker}\".");
    };
});

afterEach(fn () => File::deleteDirectory(storage_path('app/sunrice-cms/template-backups')));

// --- get_template_tree ----------------------------------------------------------

it('lists the templates, and says of each whether it opens as a tree', function () {
    File::copy(dirname(__DIR__).'/fixtures/edge/locked-block-closed-in-tag.blade.php', $this->templates.'/partials/broken.blade.php');

    $templates = collect(($this->tree)(actingAsSuperAdmin())['templates'])->keyBy(fn (array $each) => $each['origin'].':'.$each['path']);

    expect($templates['site:'.SVE_MCP_FOOTER])->toBe(['path' => SVE_MCP_FOOTER, 'origin' => 'site', 'view' => 'cms.partials.footer', 'role' => 'partial', 'editable' => true, 'opens' => true])
        ->and($templates['site:partials/broken.blade.php'])->toMatchArray(['opens' => false])
        ->and($templates['site:partials/broken.blade.php']['reason'])->toBeString()->not->toBe('')
        ->and($templates['package:entry.blade.php'])->toMatchArray(['editable' => false]);
});

it('gives a template as a tree an agent can name nodes of', function () {
    $read = ($this->tree)(actingAsSuperAdmin(), ['path' => SVE_MCP_FOOTER]);

    expect($read)->toMatchArray(['path' => SVE_MCP_FOOTER, 'origin' => 'site', 'editable' => true, 'opens' => true, 'reason' => null, 'version' => sha1($this->original)])
        ->and($read['tree'])->toMatchArray(['id' => 'root', 'type' => 'root']);

    // Every node of the template is there under its id, with what it holds and what may be done to it.
    $nodes = [];
    $walk = function (array $node) use (&$walk, &$nodes): void {
        $nodes[$node['id']] = $node;
        array_map($walk, $node['children'] ?? []);
    };
    $walk($read['tree']);
    $footer = $nodes[($this->node)('<footer')];
    expect($footer)->toMatchArray(['type' => 'element', 'name' => 'footer'])
        ->and($footer['can'])->toContain('move', 'delete', 'wrap', 'insert', 'attributes')
        ->and($footer['attributes'])->toContain(['name' => 'class', 'value' => 'site-footer'])
        // Nothing a screen needs and an agent does not.
        ->and($footer)->not->toHaveKeys(['label', 'start', 'end'])
        ->and(collect($nodes)->where('type', 'atom')->where('atom', 'echo')->first())->toHaveKey('expression');
});

it('reads one of the CMS\'s own views, and says it can\'t be changed', function () {
    $read = ($this->tree)(actingAsSuperAdmin(), ['path' => 'entry.blade.php', 'origin' => 'package']);

    expect($read)->toMatchArray(['origin' => 'package', 'editable' => false, 'opens' => true])
        ->and($read['tree']['children'])->not->toBeEmpty();
});

it('says so when a template is not there, or does not open', function () {
    $admin = actingAsSuperAdmin();
    File::copy(dirname(__DIR__).'/fixtures/edge/locked-block-closed-in-tag.blade.php', $this->templates.'/partials/broken.blade.php');

    expect(($this->tree)($admin, ['path' => 'partials/nothing.blade.php'])['error'])->toContain('has no template')
        ->and(($this->tree)($admin, ['path' => '../../.env'])['error'])->toContain('has no template')
        ->and(($this->tree)($admin, ['path' => 'partials/broken.blade.php']))->toMatchArray(['opens' => false, 'tree' => null]);
});

// --- edit_template ----------------------------------------------------------------

it('changes one thing in a template and leaves the rest as written', function () {
    $admin = actingAsSuperAdmin();

    $answer = ($this->edit)($admin, [['type' => 'addClass', 'node' => ($this->node)('<footer'), 'class' => 'wide']]);

    $expected = str_replace('<footer class="site-footer">', '<footer class="site-footer wide">', $this->original);
    expect(File::get($this->footer))->toBe($expected)->not->toBe($this->original)
        ->and($answer)->toMatchArray(['saved' => true, 'path' => SVE_MCP_FOOTER, 'version' => sha1($expected)])
        // The node that was changed, by its id in the file as it is now, and the tree to go on from.
        ->and($answer['focus'])->toBe(($this->node)('<footer'))
        ->and($answer['tree']['id'])->toBe('root')
        ->and(glob(storage_path('app/sunrice-cms/template-backups/*/cms/'.SVE_MCP_FOOTER)))->toHaveCount(1)
        ->and(ActivityLog::query()->where('subject_type', 'template')->sole())->toMatchArray(['action' => 'updated', 'subject_label' => SVE_MCP_FOOTER, 'user_id' => (string) $admin->getKey()]);

    // The page is drawn with it.
    $this->get('/')->assertOk()->assertSee('site-footer wide', false);
});

it('applies several operations together, and goes on from the tree it answers with', function () {
    $admin = actingAsSuperAdmin();

    $first = ($this->edit)($admin, [
        ['type' => 'append', 'node' => ($this->node)('<footer'), 'blade' => '<p class="made-by-agent">Text</p>'],
        ['type' => 'setAttribute', 'node' => ($this->node)('<footer'), 'name' => 'data-note', 'value' => ''],
        ['type' => 'setAttribute', 'node' => ($this->node)('<footer'), 'name' => 'title', 'value' => '  kept as typed  '],
    ]);
    expect($first['saved'])->toBeTrue()
        ->and(File::get($this->footer))->toContain('<p class="made-by-agent">Text</p>', 'data-note=""', 'title="  kept as typed  "');

    $second = sveToolResult(VisualEditorToolsServer::actingAs($admin)->tool(EditTemplate::class, [
        'path' => SVE_MCP_FOOTER, 'version' => $first['version'],
        'operations' => [['type' => 'setText', 'node' => ($this->node)('Text'), 'text' => 'Written by an agent, with <b>markup</b> shown and {{ not }} run']],
    ]));
    expect($second['saved'])->toBeTrue()
        // On the page it reads as it was typed: the markup as text, the braces as braces.
        ->and(html_entity_decode((string) $this->get('/')->assertOk()->getContent()))->toContain('Written by an agent, with <b>markup</b> shown and {{ not }} run')
        ->and((string) $this->get('/')->getContent())->not->toContain('with <b>markup</b>');
});

it('refuses a change made for another version of the file, and says which it is now', function () {
    $answer = ($this->edit)(actingAsSuperAdmin(), [['type' => 'remove', 'node' => ($this->node)('<footer')]], ['version' => sha1('older')]);

    expect($answer['error'])->toContain('has changed since', sha1($this->original), 'visual_editor_get_template_tree')
        ->and(File::get($this->footer))->toBe($this->original);
});

it('applies all of the operations or none, and says which was refused', function () {
    $answer = ($this->edit)(actingAsSuperAdmin(), [
        ['type' => 'addClass', 'node' => ($this->node)('<footer'), 'class' => 'wide'],
        ['type' => 'setTag', 'node' => '99999', 'tag' => 'div'],
    ]);

    expect($answer['error'])->toContain('Not saved', 'operation 2 (setTag)', 'none of them was applied')
        ->and(File::get($this->footer))->toBe($this->original);
});

it('refuses what would not compile, and what is no operation', function (array $operation, string $says) {
    $answer = ($this->edit)(actingAsSuperAdmin(), [$operation + ['node' => ($this->node)('<footer')]]);

    expect($answer['error'])->toContain('Not saved', $says)
        ->and(File::get($this->footer))->toBe($this->original);
})->with([
    'Blade that does not compile' => [['type' => 'append', 'blade' => '{{ $a-> }}'], 'compile'],
    'Blade that leaves a block open' => [['type' => 'append', 'blade' => '@if ($a)'], ''],
    'an operation there is none of' => [['type' => 'explode'], 'There is no operation'],
    'an operation without what it takes' => [['type' => 'addClass'], 'needs "class"'],
]);

it('refuses a template that is not the site\'s to change', function (string $path) {
    $answer = sveToolResult(VisualEditorToolsServer::actingAs(actingAsSuperAdmin())->tool(EditTemplate::class, ['path' => $path, 'version' => 'x', 'operations' => [['type' => 'remove', 'node' => '0']]]));

    expect($answer['error'])->toContain('has no template');
})->with(['draft-banner.blade.php', 'partials/nothing.blade.php', '../../.env']);

// --- Who may ---------------------------------------------------------------------

it('holds an agent to what its user may do in the editor', function () {
    $viewer = ($this->with)(Access::OPEN);
    $editor = ($this->with)(Access::OPEN, Access::EDIT);
    $nobody = ($this->with)();
    $addClass = [['type' => 'addClass', 'node' => ($this->node)('<footer'), 'class' => 'wide']];

    expect(($this->tree)($nobody, ['path' => SVE_MCP_FOOTER])['error'])->toContain('may not use the visual editor')
        ->and(($this->tree)($viewer, ['path' => SVE_MCP_FOOTER])['tree']['id'])->toBe('root')
        ->and(($this->edit)($viewer, $addClass)['error'])->toContain('may not change templates')
        ->and(($this->edit)($nobody, $addClass)['error'])->toContain('may not change templates')
        ->and(File::get($this->footer))->toBe($this->original)
        ->and(($this->edit)($editor, $addClass)['saved'])->toBeTrue();
});

it('lets an agent write code only for a user who may edit code', function () {
    $editor = ($this->with)(Access::EDIT);
    $coder = ($this->with)(Access::EDIT, Access::CODE);
    $code = [['type' => 'append', 'node' => ($this->node)('<footer'), 'blade' => '<p>{{ phpinfo() }}</p>']];

    expect(($this->edit)($editor, $code)['error'])->toContain('Not saved', 'you may not edit code')
        ->and(File::get($this->footer))->toBe($this->original)
        // What is no code is theirs to add.
        ->and(($this->edit)($editor, [['type' => 'append', 'node' => ($this->node)('<footer'), 'blade' => '<p>Plain</p>']])['saved'])->toBeTrue()
        ->and(($this->edit)($coder, [['type' => 'append', 'node' => ($this->node)('<footer'), 'blade' => '<p>{{ now()->year }}</p>']])['saved'])->toBeTrue();
});

it('changes nothing where the site keeps agents from templates, or the editor is off', function () {
    $admin = actingAsSuperAdmin();
    $addClass = [['type' => 'addClass', 'node' => ($this->node)('<footer'), 'class' => 'wide']];

    config(['sunrice-visual-editor.agents' => false]);
    expect(($this->edit)($admin, $addClass)['error'])->toContain('sunrice-visual-editor.agents')
        // Reading is not changing.
        ->and(($this->tree)($admin, ['path' => SVE_MCP_FOOTER])['opens'])->toBeTrue();

    config(['sunrice-visual-editor.agents' => true, 'sunrice-visual-editor.enabled' => false]);
    expect(($this->edit)($admin, $addClass)['error'])->toContain('may not change templates')
        ->and(($this->tree)($admin)['error'])->toContain('may not use the visual editor')
        ->and(File::get($this->footer))->toBe($this->original);
});

// --- The tools themselves ---------------------------------------------------------

it('describes the tools for an agent to choose and call them', function () {
    $tools = collect((new ReflectionProperty(TestListResponse::class, 'items'))->getValue(VisualEditorToolsServer::actingAs(actingAsSuperAdmin())->tools()))->keyBy('name');

    expect($tools->keys()->all())->toBe(['visual_editor_get_template_tree', 'visual_editor_edit_template'])
        ->and($tools['visual_editor_edit_template']['description'])->toContain('visual_editor_get_template_tree', 'setText', 'insertBefore', 'all or none', 'cms_render_page')
        ->and($tools['visual_editor_edit_template']['inputSchema']['required'])->toBe(['path', 'version', 'operations'])
        ->and($tools['visual_editor_get_template_tree']['description'])->toContain('visual_editor_edit_template', 'version')
        ->and($tools['visual_editor_get_template_tree']['annotations']['readOnlyHint'] ?? null)->toBeTrue();
});

it('names every operation there is in the description of the tool that takes them', function () {
    $described = (new ReflectionClass(EditTemplate::class))->getAttributes(Description::class)[0]->newInstance()->value;

    foreach (Operation::types() as $type) {
        expect($described)->toContain($type);
    }
});

it('is on the panel\'s server, for agents with an access token of someone who may use the editor', function () {
    expect(app(Panel::class)->getTools())->toContain(GetTemplateTree::class, EditTemplate::class);

    $call = function ($user, string $tool, array $arguments) {
        [, $token] = app(CreateAiToken::class)->handle($user, 'Agent');

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]],
            ['Authorization' => "Bearer {$token}"])->assertOk()->json('result');
    };

    $designer = ($this->with)(Access::EDIT, 'sunrice.ai.connect');
    $read = $call($designer, 'visual_editor_get_template_tree', ['path' => SVE_MCP_FOOTER]);
    expect($read['isError'] ?? false)->toBeFalse()
        ->and(json_decode($read['content'][0]['text'], true)['version'])->toBe(sha1($this->original));

    $edited = $call($designer, 'visual_editor_edit_template', ['path' => SVE_MCP_FOOTER, 'version' => sha1($this->original),
        'operations' => [['type' => 'addClass', 'node' => ($this->node)('<footer'), 'class' => 'wide']]]);
    expect($edited['isError'] ?? false)->toBeFalse()
        ->and(File::get($this->footer))->toContain('<footer class="site-footer wide">');

    // Someone who may not use the editor gets no tree.
    $nobody = ($this->with)('sunrice.ai.connect');
    expect($call($nobody, 'visual_editor_get_template_tree', [])['content'][0]['text'])->toContain('may not use the visual editor');
});

it('leaves out of the tree what only a screen needs', function () {
    expect(Tree::compact([
        'id' => '12', 'type' => 'element', 'kind' => null, 'name' => 'a', 'label' => 'a.button', 'start' => 12, 'end' => 80,
        'can' => ['move' => true, 'delete' => true, 'wrap' => false, 'insert' => true, 'attributes' => true],
        'attributes' => [
            ['name' => 'href', 'value' => '{{ $url }}', 'dynamic' => true, 'code' => false, 'source' => 'href="{{ $url }}"'],
            ['name' => 'class', 'value' => 'button', 'dynamic' => false, 'code' => false, 'source' => 'class="button"'],
            ['name' => '@class', 'value' => null, 'dynamic' => true, 'code' => true, 'source' => "@class(['a'])"],
        ],
        'children' => [['id' => '40', 'type' => 'text', 'kind' => null, 'name' => '', 'label' => 'Read', 'start' => 40, 'end' => 44, 'text' => 'Read', 'can' => ['move' => true]]],
    ]))->toBe([
        'id' => '12', 'type' => 'element', 'name' => 'a',
        'attributes' => [
            ['name' => 'href', 'value' => '{{ $url }}', 'dynamic' => true],
            ['name' => 'class', 'value' => 'button'],
            ['name' => '@class', 'dynamic' => true, 'code' => "@class(['a'])"],
        ],
        'can' => ['move', 'delete', 'insert', 'attributes'],
        'children' => [['id' => '40', 'type' => 'text', 'text' => 'Read', 'can' => ['move']]],
    ]);
});
