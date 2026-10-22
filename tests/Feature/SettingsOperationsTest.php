<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\postJson;

uses(WithStarterSite::class);

/*
 * What each control of the Settings panel sends, through the API, on a
 * template of the site: the file changes where the control says and
 * nowhere else.
 */

const SVE_SETTINGS_TEMPLATE = <<<'BLADE'
<section class="card" id="intro">
    <h2>{{ $entry->title }}</h2>
    <p>Plain words</p>
    @if ($entry->excerpt)
        <p class="lead" title="Summary">{{ $entry->excerpt }}</p>
    @endif
    @foreach ($entry->tags as $tag)
        @include('sunrice.partials.card', ['entry' => $tag])
    @endforeach
    @php($n = 1)
</section>

BLADE;

it('changes what a control names and nothing else', function (array $operation, array $changes) {
    $this->setUpStarterSite();
    actingAsSuperAdmin();
    $file = $this->templates.'/partials/sve-settings.blade.php';
    File::put($file, SVE_SETTINGS_TEMPLATE);

    // "b:@if" is the arm that starts at @if; anything else the node that starts there.
    $arm = str_starts_with($operation['node'], 'b:');
    $operation['node'] = ($arm ? 'b' : '').strpos(SVE_SETTINGS_TEMPLATE, $arm ? substr($operation['node'], 2) : $operation['node']);

    $response = postJson('/admin/visual-editor/api/templates/partials/sve-settings.blade.php/operations', [
        'version' => sha1(SVE_SETTINGS_TEMPLATE),
        'operations' => [$operation],
    ])->assertOk();

    // Each change is one stretch of the file, found once, replaced once.
    $expected = SVE_SETTINGS_TEMPLATE;
    foreach ($changes as $from => $to) {
        expect(substr_count($expected, $from))->toBe(1);
        $expected = str_replace($from, $to, $expected);
    }

    expect(File::get($file))->toBe($expected)
        ->and($response->json('template.source'))->toBe($expected)
        // The node the panel goes on showing is in the new file.
        ->and(Template::open($expected)->find((string) $response->json('focus')))->not->toBeNull();
})->with([
    'Tag' => [['type' => 'setTag', 'node' => '<section', 'tag' => 'article'], ['<section class="card" id="intro">' => '<article class="card" id="intro">', '</section>' => '</article>']],
    'ID' => [['type' => 'setId', 'node' => '<section', 'id' => 'top'], ['id="intro"' => 'id="top"']],
    'ID, emptied' => [['type' => 'setId', 'node' => '<section', 'id' => ''], [' id="intro"' => '']],
    'a class added' => [['type' => 'addClass', 'node' => '<section', 'class' => 'wide'], ['class="card"' => 'class="card wide"']],
    'the first class' => [['type' => 'addClass', 'node' => '<h2>', 'class' => 'title'], ['<h2>' => '<h2 class="title">']],
    'the last class removed' => [['type' => 'removeClass', 'node' => '<p class', 'class' => 'lead'], ['<p class="lead" title' => '<p title']],
    'a new attribute' => [['type' => 'setAttribute', 'node' => '<h2>', 'name' => 'data-x', 'value' => 'a "b"'], ['<h2>' => '<h2 data-x="a &quot;b&quot;">']],
    'an attribute\'s value' => [['type' => 'setAttribute', 'node' => '<p class', 'name' => 'title', 'value' => 'Short'], ['title="Summary"' => 'title="Short"']],
    'a value written in Blade' => [['type' => 'setAttribute', 'node' => '<p class', 'name' => 'title', 'value' => '{{ $entry->title }}', 'blade' => true], ['title="Summary"' => 'title="{{ $entry->title }}"']],
    'an attribute removed' => [['type' => 'removeAttribute', 'node' => '<p class', 'name' => 'title'], [' title="Summary"' => '']],
    'Text' => [['type' => 'setText', 'node' => 'Plain words', 'text' => 'New <words>'], ['Plain words' => 'New &lt;words&gt;']],
    'Prints' => [['type' => 'setExpression', 'node' => '{{ $entry->title }}', 'expression' => '$entry->heading'], ['{{ $entry->title }}' => '{{ $entry->heading }}']],
    'Print as HTML' => [['type' => 'setEchoRaw', 'node' => '{{ $entry->excerpt }}', 'raw' => true], ['{{ $entry->excerpt }}' => '{!! $entry->excerpt !!}']],
    'Shown when' => [['type' => 'setArguments', 'node' => 'b:@if', 'arguments' => '$entry->excerpt && $show'], ['@if ($entry->excerpt)' => '@if ($entry->excerpt && $show)']],
    'Each one as' => [['type' => 'setArguments', 'node' => 'b:@foreach', 'arguments' => '$entry->tags as $term'], ['$entry->tags as $tag' => '$entry->tags as $term']],
    'View and Passes' => [['type' => 'setArguments', 'node' => '@include', 'arguments' => "'sunrice.partials.pagination', ['of' => \$tag]"], ["'sunrice.partials.card', ['entry' => \$tag]" => "'sunrice.partials.pagination', ['of' => \$tag]"]],
    'As written' => [['type' => 'setCode', 'node' => '@php', 'code' => '@php($n = 2)'], ['@php($n = 1)' => '@php($n = 2)']],
]);
