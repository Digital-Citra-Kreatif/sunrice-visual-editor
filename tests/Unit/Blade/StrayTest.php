<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Edit;
use Sunrice\VisualEditor\Blade\EditRejected;
use Sunrice\VisualEditor\Blade\Fragment;
use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Operations;
use Sunrice\VisualEditor\Blade\Rules;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Blade\Unsupported;

/*
 * Tags that don't nest: one half in an @if and the other outside it, an end
 * tag left out, a layout split over two files. The tree keeps each such tag
 * as it is written and reads everything around it as usual.
 */

/** The tree as indented lines: one per node, stray ones marked with "!". */
function outline(Node $node, int $depth = 0): array
{
    $lines = [];
    foreach ($node->children as $child) {
        if ($child->type === 'text' && trim($child->open) === '') {
            continue;
        }
        $lines[] = str_repeat('  ', $depth).($child->stray !== null ? '! ' : '').match ($child->type) {
            'element' => "<{$child->name}>",
            'block' => "@{$child->name} …",
            'text' => trim($child->open),
            default => trim($child->open),
        };
        array_push($lines, ...outline($child, $depth + 1));
    }

    return $lines;
}

/** The first node whose own text starts with $head. */
function nodeStarting(Template $template, string $head): Node
{
    foreach ($template->root->walk() as $node) {
        if (str_starts_with($node->open, $head)) {
            return $node;
        }
    }

    throw new RuntimeException("No node starts with {$head}");
}

it('keeps a tag whose other half is elsewhere, and reads the rest as usual', function (string $blade, array $outline) {
    $template = Template::open($blade);

    expect($template->print())->toBe($blade)
        ->and(outline($template->root))->toBe($outline);
})->with([
    'a link opened in one @if and closed in another' => [
        '@if ($url) <a href="{{ $url }}"> @endif <img src="a.png"> @if ($url) </a> @endif',
        ['@if …', '  @if ($url)', '    ! <a href="{{ $url }}">', '<img>', '@if …', '  @if ($url)', '    ! </a>'],
    ],
    'an element opened in both arms of a condition' => [
        '@if ($dark) <div class="dark"> @else <div> @endif <p>Text</p> </div>',
        ['@if …', '  @if ($dark)', '    ! <div class="dark">', '  @else', '    ! <div>', '<p>', '  Text', '! </div>'],
    ],
    'end tags left out' => [
        '<ul><li>One<li>Two</ul>',
        ['<ul>', '  ! <li>', '  One', '  ! <li>', '  Two'],
    ],
    'one end tag too many' => [
        '<div><p>Text</p></div></div>',
        ['<div>', '  <p>', '    Text', '! </div>'],
    ],
    'the top half of a layout' => [
        '<body class="page"><header><h1>{{ $title }}</h1></header><main>',
        ['! <body class="page">', '<header>', '  <h1>', '    {{ $title }}', '! <main>'],
    ],
    'the bottom half' => [
        '</main><footer>{{ $year }}</footer></body>',
        ['! </main>', '<footer>', '  {{ $year }}', '! </body>'],
    ],
    'an element closed further out than the one left open' => [
        '<div><span>a</div><p>b</p></span>',
        ['<div>', '  ! <span>', '  a', '<p>', '  b', '! </span>'],
    ],
    'an element left open at the end of a loop' => [
        '<table>@foreach ($rows as $row) <tr><td>{{ $row }} @endforeach</table>',
        ['<table>', '  @foreach …', '    @foreach ($rows as $row)', '      ! <tr>', '      ! <td>', '      {{ $row }}'],
    ],
    'a condition opened inside a tag and closed outside it' => [
        '<ul @if ($open) class="open"><li>One</li> @endif </ul>',
        ['<ul>', '  <li>', '    One', '  ! @endif'],
    ],
    'a @case inside a condition inside its @switch' => [
        '@switch($t) @case(1) @if ($a) @case(2) @endif x @break @endswitch',
        ['@switch …', '  @switch($t)', '  @case(1)', '    @if …', '      @if ($a)', '        ! @case(2)', '    x', '    @break'],
    ],
]);

it('says why each one is stray', function () {
    $template = Template::open('<main>@if ($a) <div> @endif <p>x</p></section> @endforeach');

    expect(array_map(fn (Node $node) => [$node->open, $node->stray], $template->strays()))->toBe([
        ['<main>', '<main> is not closed here'],
        ['<div>', '<div> is not closed here'],
        ['</section>', '</section> closes nothing here'],
        ['@endforeach', '@endforeach has nothing to close'],
    ])->and(Template::open('<main><p>x</p></main>')->strays())->toBe([]);
});

it('leaves a stray tag, and whatever holds one, where it is', function () {
    $blade = "<article>\n    @if (\$url)\n        <a href=\"{{ \$url }}\">\n    @endif\n    <h3>{{ \$title }}</h3>\n    @if (\$url)\n        </a>\n    @endif\n</article>\n<p>After</p>\n";
    $template = Template::open($blade);
    $open = nodeStarting($template, '<a href');
    $close = nodeStarting($template, '</a>');
    [$article, $heading, $paragraph] = $template->elements();
    $condition = $open->parent->parent;

    // The tag itself, the @if around it and the <article> around that all stay.
    foreach ([$open, $close, $condition, $article] as $fixed) {
        expect(Rules::movable($fixed))->toBeFalse()
            ->and(Rules::wrappable($fixed))->toBeFalse()
            ->and(fn () => Edit::remove($fixed))->toThrow(EditRejected::class)
            ->and(fn () => Edit::duplicate($fixed))->toThrow(EditRejected::class)
            ->and(fn () => Edit::move($fixed, $paragraph, 'after'))->toThrow(EditRejected::class)
            ->and(fn () => Edit::wrap($fixed, '<div></div>'))->toThrow(EditRejected::class)
            ->and(fn () => Edit::unwrap($fixed))->toThrow(EditRejected::class)
            ->and(fn () => Edit::setCode($fixed, '<b>x</b>'))->toThrow(EditRejected::class);
    }
    expect($template->print())->toBe($blade);

    // What is between and around them is edited like anything else.
    expect(Rules::movable($heading))->toBeTrue();
    Edit::addClass($heading, 'title');
    Edit::insertAfter($heading, '<p>{{ $excerpt }}</p>');
    Edit::insertAfter($open, '<span class="badge">New</span>');
    Edit::move($paragraph, $heading, 'before');
    Edit::addClass($article, 'card');

    expect($template->print())->toBe("<article class=\"card\">\n    @if (\$url)\n        <a href=\"{{ \$url }}\">\n        <span class=\"badge\">New</span>\n    @endif\n    <p>After</p>\n    <h3 class=\"title\">{{ \$title }}</h3>\n    <p>{{ \$excerpt }}</p>\n    @if (\$url)\n        </a>\n    @endif\n</article>\n");
});

it('edits the attributes of an opening tag left without its end', function () {
    $blade = '<body class="page {{ $extra }}"><main id="content"><p>x</p></main></html>';
    $template = Template::open($blade);
    [$body, $end] = $template->strays();

    Edit::addClass($body, 'dark');
    Edit::removeClass($body, 'page');
    Edit::setAttribute($body, 'data-theme', 'a "b"');
    Edit::setId($body, 'top');
    Edit::removeAttribute($body, 'id');

    expect($template->print())->toBe('<body class="{{ $extra }} dark" data-theme="a &quot;b&quot;"><main id="content"><p>x</p></main></html>')
        ->and($body->stray)->not->toBeNull()
        // Its end tag is somewhere else: the name can't change here alone.
        ->and(fn () => Edit::setTag($body, 'div'))->toThrow(EditRejected::class, 'other half')
        ->and(fn () => Edit::setTag($body, 'br'))->toThrow(EditRejected::class)
        // An end tag has nothing to set.
        ->and(fn () => Edit::addClass($end, 'x'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::setAttribute($end, 'id', 'x'))->toThrow(EditRejected::class);

    // The same through operations: the file opens again with the same tags stray.
    $out = Operations::apply($blade, [['type' => 'addClass', 'node' => '0', 'class' => 'dark']]);
    expect($out)->toBe('<body class="page {{ $extra }} dark"><main id="content"><p>x</p></main></html>')
        ->and(Template::open($out)->strays())->toHaveCount(2);
});

it('shows a stray tag in the tree data with what can be done to it', function () {
    $tree = Template::open('<body class="page"><p>x</p></main>')->toArray()['tree'];
    [$body, $paragraph, $end] = $tree['children'];

    expect($body)->toMatchArray(['type' => 'atom', 'kind' => 'code', 'atom' => 'tag', 'name' => 'body', 'label' => '<body class="page">', 'source' => '<body class="page">', 'stray' => '<body> is not closed here'])
        ->and($body['can'])->toBe(['move' => false, 'delete' => false, 'wrap' => false, 'insert' => false, 'attributes' => true])
        ->and(array_column($body['attributes'], 'value', 'name'))->toBe(['class' => 'page'])
        ->and($end)->toMatchArray(['atom' => 'tag', 'name' => 'main', 'source' => '</main>', 'stray' => '</main> closes nothing here'])
        ->and($end['can'])->toBe(['move' => false, 'delete' => false, 'wrap' => false, 'insert' => false, 'attributes' => false])
        ->and($end)->not->toHaveKey('attributes')
        ->and($paragraph)->not->toHaveKey('stray')
        ->and($paragraph['can']['move'])->toBeTrue();
});

it('takes nothing stray in what is put into a template', function (string $snippet) {
    $template = Template::open('<div><p>x</p></div>');
    [$div, $paragraph] = $template->elements();

    expect(fn () => Fragment::parse($snippet))->toThrow(EditRejected::class)
        ->and(fn () => Edit::append($div, $snippet))->toThrow(EditRejected::class)
        ->and(fn () => Edit::insertAfter($paragraph, $snippet))->toThrow(EditRejected::class)
        ->and(fn () => Edit::setCode($paragraph, $snippet))->toThrow(EditRejected::class)
        ->and(fn () => Edit::wrap($paragraph, $snippet))->toThrow(EditRejected::class)
        ->and($template->print())->toBe('<div><p>x</p></div>');
})->with([
    'an element never closed' => ['<section>'],
    'an end tag alone' => ['</div>'],
    'end tags left out' => ['<ul><li>One<li>Two</ul>'],
    'a tag across a condition' => ['@if ($a) <b> @endif x @if ($a) </b> @endif'],
    'half a condition' => ['@if ($a) x'],
    'the end of one' => ['x @endif'],
]);

it('still refuses a file whose Blade blocks don\'t pair up in the tree', function (string $blade, string $reason) {
    expect(fn () => Template::open($blade))->toThrow(Unsupported::class, $reason);
})->with([
    'a block closed inside a tag' => ['@if ($wide) <div class="wide" @endif>x</div>', '@if is never closed'],
    'a component never closed' => ['<x-card><p>x</p>', '<x-card> is never closed'],
    'a component closed and never opened' => ['<p>x</p></x-card>', '</x-card> has no opening tag'],
]);
