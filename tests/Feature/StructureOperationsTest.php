<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\VisualEditor\Blade\Edit;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\postJson;

uses(WithStarterSite::class);

/*
 * What the Layers panel and the buttons of the Settings panel send to
 * move, copy, wrap, unwrap and delete a node, through the API, on a
 * template of the site: the file is exactly what the edit of the tree
 * (V1.4) makes of it.
 */

const SVE_STRUCTURE_TEMPLATE = <<<'BLADE'
<section class="card">
    {{-- A note --}}
    <h2>{{ $entry->title }}</h2>
    <ul>
        <li>One</li>
        <li>Two</li>
    </ul>
    @if ($entry->excerpt)
        <p>{{ $entry->excerpt }}</p>
    @endif
</section>
<footer>
    <p>Plain words</p>
</footer>

BLADE;

const SVE_STRUCTURE_API = '/cms/visual-editor/api/templates/partials/sve-structure.blade.php/operations';

/** The id of the node that starts at some text; "b:…" for the arm that starts there. */
function sveStructureNode(string $at): string
{
    $arm = str_starts_with($at, 'b:');
    $offset = strpos(SVE_STRUCTURE_TEMPLATE, $arm ? substr($at, 2) : $at);
    expect($offset)->not->toBeFalse();

    return ($arm ? 'b' : '').$offset;
}

/** The template with some of its lines taken out and others put in, by line number from 1. */
function sveStructureLines(array $without, array $with = []): string
{
    $lines = explode("\n", SVE_STRUCTURE_TEMPLATE);
    $out = [];
    foreach ($lines as $index => $line) {
        foreach ($with[$index + 1] ?? [] as $before) {
            $out[] = $before;
        }
        if (! in_array($index + 1, $without, true)) {
            $out[] = $line;
        }
    }

    return implode("\n", $out);
}

beforeEach(function () {
    $this->setUpStarterSite();
    actingAsSuperAdmin();
    $this->file = $this->templates.'/partials/sve-structure.blade.php';
    File::put($this->file, SVE_STRUCTURE_TEMPLATE);
});

it('moves a node to where the tree\'s own edit puts it', function (string $node, string $target, string $position, string $expected) {
    // The edit of V1.4, made on the tree itself.
    $template = Template::open(SVE_STRUCTURE_TEMPLATE);
    Edit::move($template->find(sveStructureNode($node)), $template->find(sveStructureNode($target)), $position);

    $response = postJson(SVE_STRUCTURE_API, [
        'version' => sha1(SVE_STRUCTURE_TEMPLATE),
        'operations' => [['type' => 'move', 'node' => sveStructureNode($node), 'target' => sveStructureNode($target), 'position' => $position]],
    ])->assertOk();

    // What was moved is what the panel goes on showing: the same node, at its new indentation.
    $squeezed = fn (?string $blade) => preg_replace('/\s+/', '', (string) $blade);
    $moved = Template::open($expected)->find((string) $response->json('focus'));

    expect(File::get($this->file))->toBe($template->print())
        ->and(File::get($this->file))->toBe($expected)
        ->and($moved)->not->toBeNull()
        ->and($squeezed($moved?->print()))->toBe($squeezed(Template::open(SVE_STRUCTURE_TEMPLATE)->find(sveStructureNode($node))?->print()));
})->with([
    'one step down' => ['<h2>', '<ul>', 'after', sveStructureLines([3], [8 => ['    <h2>{{ $entry->title }}</h2>']])],
    'one step up' => ['<ul>', '<h2>', 'before', sveStructureLines([3], [8 => ['    <h2>{{ $entry->title }}</h2>']])],
    'into another element, last' => ['<li>One', '<footer>', 'inside', sveStructureLines([5], [14 => ['    <li>One</li>']])],
    'beside something in another element' => ['<h2>', '<p>Plain', 'before', sveStructureLines([3], [13 => ['    <h2>{{ $entry->title }}</h2>']])],
    'into the arm of a block' => ['<h2>', 'b:@if', 'inside', sveStructureLines([3], [10 => ['        <h2>{{ $entry->title }}</h2>']])],
    'out of a block, which stays' => ['<p>{{', '<h2>', 'after', sveStructureLines([9], [4 => ['    <p>{{ $entry->excerpt }}</p>']])],
    'a block with what it holds' => ['@if', '<footer>', 'inside', sveStructureLines([8, 9, 10], [14 => ['    @if ($entry->excerpt)', '        <p>{{ $entry->excerpt }}</p>', '    @endif']])],
    'a comment' => ['{{--', '<footer>', 'before', sveStructureLines([2], [12 => ['{{-- A note --}}']])],
]);

it('copies, wraps, unwraps and deletes', function (array $operation, string $expected) {
    $operation['node'] = sveStructureNode($operation['node']);

    postJson(SVE_STRUCTURE_API, ['version' => sha1(SVE_STRUCTURE_TEMPLATE), 'operations' => [$operation]])->assertOk();

    expect(File::get($this->file))->toBe($expected);
})->with([
    'a copy, after it' => [['type' => 'duplicate', 'node' => '<li>Two'], sveStructureLines([], [7 => ['        <li>Two</li>']])],
    'a copy of a block' => [['type' => 'duplicate', 'node' => '@if'], sveStructureLines([], [11 => ['    @if ($entry->excerpt)', '        <p>{{ $entry->excerpt }}</p>', '    @endif']])],
    'wrapped in a container' => [['type' => 'wrap', 'node' => '<h2>', 'wrapper' => '<div></div>'], sveStructureLines([3], [4 => ['    <div>', '        <h2>{{ $entry->title }}</h2>', '    </div>']])],
    'wrapped in a condition' => [['type' => 'wrap', 'node' => '<ul>', 'wrapper' => "@if (true)\n@endif"], sveStructureLines([4, 5, 6, 7], [8 => ['    @if (true)', '        <ul>', '            <li>One</li>', '            <li>Two</li>', '        </ul>', '    @endif']])],
    'text wrapped in a link' => [['type' => 'wrap', 'node' => 'Plain words', 'wrapper' => '<a href="#"></a>'], sveStructureLines([13], [14 => ['    <p><a href="#">Plain words</a></p>']])],
    'an element unwrapped' => [['type' => 'unwrap', 'node' => '<ul>'], sveStructureLines([4, 5, 6, 7], [8 => ['    <li>One</li>', '    <li>Two</li>']])],
    'a block unwrapped' => [['type' => 'unwrap', 'node' => '@if'], sveStructureLines([8, 9, 10], [11 => ['    <p>{{ $entry->excerpt }}</p>']])],
    'a comment deleted' => [['type' => 'remove', 'node' => '{{--'], sveStructureLines([2])],
    'a block deleted' => [['type' => 'remove', 'node' => '@if'], sveStructureLines([8, 9, 10])],
    'an element deleted' => [['type' => 'remove', 'node' => '<footer>'], sveStructureLines([12, 13, 14])],
    // Text is not taken out like a node: Delete in the panel empties it.
    'text emptied' => [['type' => 'setText', 'node' => 'Plain words', 'text' => ''], sveStructureLines([13], [14 => ['    <p></p>']])],
]);

it('refuses what the tree doesn\'t allow, and leaves the file alone', function (array $operation, string $message) {
    $operation['node'] = sveStructureNode($operation['node']);
    if (isset($operation['target'])) {
        $operation['target'] = sveStructureNode($operation['target']);
    }

    postJson(SVE_STRUCTURE_API, ['version' => sha1(SVE_STRUCTURE_TEMPLATE), 'operations' => [$operation]])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'rejected')
        ->assertJsonPath('message', $message);

    expect(File::get($this->file))->toBe(SVE_STRUCTURE_TEMPLATE);
})->with([
    'into something inside itself' => [['type' => 'move', 'node' => '<ul>', 'target' => '<li>One', 'position' => 'after'], 'A node can\'t be moved into or beside itself.'],
    'an arm of a block, deleted' => [['type' => 'remove', 'node' => 'b:@if'], 'This can\'t be moved or removed on its own.'],
    'text, moved' => [['type' => 'move', 'node' => 'Plain words', 'target' => '<h2>', 'position' => 'after'], 'This can\'t be moved or removed on its own.'],
    'something with nothing inside, unwrapped' => [['type' => 'unwrap', 'node' => '{{ $entry->title'], 'There is nothing inside this to keep.'],
    'a wrapper that is not empty' => [['type' => 'wrap', 'node' => '<h2>', 'wrapper' => '<div><p></p></div>'], 'The wrapper has to be one empty element or block, such as <div class="card"></div>.'],
]);
