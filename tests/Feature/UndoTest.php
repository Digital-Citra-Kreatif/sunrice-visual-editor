<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(WithStarterSite::class);

/*
 * Undo is the editor saving a template as it was a step before. Ten
 * changes of every kind are made through the API, and each is then taken
 * back the way the editor does it: the file has to be, each time, exactly
 * what it was.
 */

const SVE_UNDO_TEMPLATE = <<<'BLADE'
{{-- A card --}}
<section class="card">
    <h2>{{ $entry->title }}</h2>
    <p>Plain words</p>
    @if ($entry->excerpt)
        <p class="lead">{{ $entry->excerpt }}</p>
    @endif
    <ul>
        <li>One</li>
    </ul>
</section>

BLADE;

it('takes ten changes of every kind back, one by one, to the file as it was', function () {
    $this->setUpStarterSite();
    actingAsSuperAdmin();
    File::deleteDirectory(storage_path('app/sunrice/template-backups'));
    $file = $this->templates.'/partials/sve-undo.blade.php';
    $api = '/cms/visual-editor/api/templates/partials/sve-undo.blade.php';
    File::put($file, SVE_UNDO_TEMPLATE);

    // Each change names its node by the text the node starts with, in the file as it is then.
    $changes = [
        ['type' => 'addClass', 'node' => '<h2>', 'class' => 'title'],
        ['type' => 'setText', 'node' => 'Plain words', 'text' => 'Other <words> & more'],
        ['type' => 'setExpression', 'node' => '{{ $entry->excerpt }}', 'expression' => 'str($entry->excerpt)->limit(80)'],
        ['type' => 'setArguments', 'node' => 'b:@if', 'arguments' => '$entry->excerpt !== null'],
        ['type' => 'insertAfter', 'node' => '<li>One', 'blade' => "<li>\n    <a href=\"{{ \$entry->url }}\">Two</a>\n</li>"],
        ['type' => 'move', 'node' => '<ul>', 'target' => '<h2', 'position' => 'after'],
        ['type' => 'duplicate', 'node' => '<p class="lead"'],
        ['type' => 'wrap', 'node' => '<h2', 'wrapper' => '<header></header>'],
        ['type' => 'remove', 'node' => '{{-- A card'],
        // A node rewritten as code is taken as written: its later lines come with their indentation.
        ['type' => 'setCode', 'node' => '@if', 'code' => "@unless (\$entry->excerpt === null)\n        <p>{{ \$entry->excerpt }}</p>\n    @endunless"],
    ];

    $states = [SVE_UNDO_TEMPLATE];
    foreach ($changes as $change) {
        $source = end($states);
        foreach (['node', 'target'] as $name) {
            if (isset($change[$name])) {
                $arm = str_starts_with($change[$name], 'b:');
                $offset = strpos($source, $arm ? substr($change[$name], 2) : $change[$name]);
                expect($offset)->not->toBeFalse($change['type'].' '.$change[$name]);
                $change[$name] = ($arm ? 'b' : '').$offset;
            }
        }

        $changed = postJson($api.'/operations', ['version' => sha1($source), 'operations' => [$change]])->assertOk()->json('template.source');

        expect($changed)->not->toBe($source, $change['type'])
            ->and(File::get($file))->toBe($changed);
        $states[] = $changed;
    }
    expect(array_unique($states))->toHaveCount(11);

    // Back: each earlier source saved over the one after it.
    for ($step = 9; $step >= 0; $step--) {
        putJson($api.'/source', ['version' => sha1($states[$step + 1]), 'source' => $states[$step]])
            ->assertOk()
            ->assertJsonPath('template.version', sha1($states[$step]));

        expect(File::get($file))->toBe($states[$step]);
    }
    expect(File::get($file))->toBe(SVE_UNDO_TEMPLATE);

    // And forward again: redo.
    for ($step = 1; $step <= 10; $step++) {
        putJson($api.'/source', ['version' => sha1($states[$step - 1]), 'source' => $states[$step]])->assertOk();

        expect(File::get($file))->toBe($states[$step]);
    }

    // An undo made for a file that has changed otherwise is refused, and writes nothing.
    File::put($file, $states[10].'<!-- changed elsewhere -->');
    putJson($api.'/source', ['version' => sha1($states[10]), 'source' => $states[9]])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'conflict');
    expect(File::get($file))->toBe($states[10].'<!-- changed elsewhere -->');

    File::deleteDirectory(storage_path('app/sunrice/template-backups'));
});
