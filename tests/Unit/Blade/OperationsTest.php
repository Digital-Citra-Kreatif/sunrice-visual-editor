<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Edit;
use Sunrice\VisualEditor\Blade\EditRejected;
use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Operation;
use Sunrice\VisualEditor\Blade\Operations;
use Sunrice\VisualEditor\Blade\ResultRejected;
use Sunrice\VisualEditor\Blade\Template;

/** The id of the node that starts where $needle first appears. */
function nodeAt(string $source, string $needle, string $prefix = ''): string
{
    return $prefix.strpos($source, $needle);
}

/** The exception a call throws, or null. */
function rejection(Closure $call): ?EditRejected
{
    try {
        $call();
    } catch (EditRejected $e) {
        return $e;
    }

    return null;
}

it('has an operation for every edit, taking the same things in the same order', function () {
    $kinds = ['string' => 'text', '?string' => '?text', 'bool' => 'flag', Node::class => 'node'];
    $edits = [];
    foreach ((new ReflectionClass(Edit::class))->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC) as $method) {
        $parameters = array_slice($method->getParameters(), 1);
        $edits[$method->getName()] = array_map(fn (ReflectionParameter $parameter) => $kinds[(string) $parameter->getType()], $parameters);
    }
    $operations = [];
    foreach (Operation::types() as $type) {
        $operations[$type] = array_values(Operation::takes($type));
    }

    expect($operations)->toBe($edits);
});

it('applies operations in order, each naming a node of the file as it was', function () {
    $source = "<section class=\"hero\">\n    <h1>{{ \$entry->title }}</h1>\n    <p>Intro</p>\n</section>\n";
    $out = Operations::apply($source, [
        ['type' => 'addClass', 'node' => nodeAt($source, '<section'), 'class' => 'wide'],
        ['type' => 'insertAfter', 'node' => nodeAt($source, '<h1>'), 'blade' => '<p class="lead">{{ $entry->excerpt }}</p>'],
        // The file is longer by now; the ids are still those of the file that was sent.
        ['type' => 'setText', 'node' => nodeAt($source, 'Intro'), 'text' => 'Welcome'],
        ['type' => 'setExpression', 'node' => nodeAt($source, '{{ $entry->title }}'), 'expression' => '$entry->heading'],
        ['type' => 'setAttribute', 'node' => nodeAt($source, '<p>'), 'name' => 'id', 'value' => 'intro'],
    ]);

    expect($out)->toBe("<section class=\"hero wide\">\n    <h1>{{ \$entry->heading }}</h1>\n    <p class=\"lead\">{{ \$entry->excerpt }}</p>\n    <p id=\"intro\">Welcome</p>\n</section>\n");
});

it('makes every kind of edit', function (array $operation, string $expected) {
    $source = '<ul class="a b" id="x"><li>One</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>';
    foreach (['node', 'target'] as $key) {
        if (isset($operation[$key])) {
            $operation[$key] = str_starts_with($operation[$key], 'b:') ? nodeAt($source, substr($operation[$key], 2), 'b') : nodeAt($source, $operation[$key]);
        }
    }

    expect(Operations::apply($source, [$operation]))->toBe($expected);
})->with([
    'setAttribute' => [['type' => 'setAttribute', 'node' => '<ul', 'name' => 'role', 'value' => 'list'], '<ul class="a b" id="x" role="list"><li>One</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'setAttribute, as Blade' => [['type' => 'setAttribute', 'node' => '<ul', 'name' => 'id', 'value' => '{{ $id }}', 'blade' => true], '<ul class="a b" id="{{ $id }}"><li>One</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'setAttribute, bare' => [['type' => 'setAttribute', 'node' => '<ul', 'name' => 'hidden', 'value' => null], '<ul class="a b" id="x" hidden><li>One</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'removeAttribute' => [['type' => 'removeAttribute', 'node' => '<ul', 'name' => 'id'], '<ul class="a b"><li>One</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'addClass' => [['type' => 'addClass', 'node' => '<ul', 'class' => 'c'], '<ul class="a b c" id="x"><li>One</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'removeClass' => [['type' => 'removeClass', 'node' => '<ul', 'class' => 'a'], '<ul class="b" id="x"><li>One</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'setId' => [['type' => 'setId', 'node' => '<ul', 'id' => 'y'], '<ul class="a b" id="y"><li>One</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'setTag' => [['type' => 'setTag', 'node' => '<ul', 'tag' => 'ol'], '<ol class="a b" id="x"><li>One</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ol>'],
    'setText' => [['type' => 'setText', 'node' => 'One', 'text' => 'A & B'], '<ul class="a b" id="x"><li>A &amp; B</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'setExpression' => [['type' => 'setExpression', 'node' => '{{ $b }}', 'expression' => '$b->name'], '<ul class="a b" id="x"><li>One</li>@if ($a) <li>{{ $b->name }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'setEchoRaw' => [['type' => 'setEchoRaw', 'node' => '{{ $b }}', 'raw' => true], '<ul class="a b" id="x"><li>One</li>@if ($a) <li>{!! $b !!}</li> @endif<li>@php($c = 1)</li></ul>'],
    'setArguments' => [['type' => 'setArguments', 'node' => 'b:@if', 'arguments' => '$a && $b'], '<ul class="a b" id="x"><li>One</li>@if ($a && $b) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'setCode' => [['type' => 'setCode', 'node' => '@php', 'code' => '@php($c = 2)'], '<ul class="a b" id="x"><li>One</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 2)</li></ul>'],
    'insertBefore' => [['type' => 'insertBefore', 'node' => '<li>One', 'blade' => '<li>Zero</li>'], '<ul class="a b" id="x"><li>Zero</li><li>One</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'insertAfter' => [['type' => 'insertAfter', 'node' => '<li>One', 'blade' => '<li>Two</li>'], '<ul class="a b" id="x"><li>One</li><li>Two</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'append' => [['type' => 'append', 'node' => '<ul', 'blade' => '<li>Last</li>'], '<ul class="a b" id="x"><li>One</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li><li>Last</li></ul>'],
    'prepend' => [['type' => 'prepend', 'node' => 'b:@if', 'blade' => '<li>First</li>'], '<ul class="a b" id="x"><li>One</li>@if ($a) <li>First</li><li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'remove' => [['type' => 'remove', 'node' => '<li>One'], '<ul class="a b" id="x">@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'duplicate' => [['type' => 'duplicate', 'node' => '<li>One'], '<ul class="a b" id="x"><li>One</li><li>One</li>@if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'move' => [['type' => 'move', 'node' => '<li>One', 'target' => 'b:@if', 'position' => 'inside'], '<ul class="a b" id="x">@if ($a) <li>{{ $b }}</li> <li>One</li>@endif<li>@php($c = 1)</li></ul>'],
    'wrap' => [['type' => 'wrap', 'node' => '<li>One', 'wrapper' => '@auth @endauth'], '<ul class="a b" id="x">@auth<li>One</li>@endauth @if ($a) <li>{{ $b }}</li> @endif<li>@php($c = 1)</li></ul>'],
    'unwrap' => [['type' => 'unwrap', 'node' => '@if'], '<ul class="a b" id="x"><li>One</li><li>{{ $b }}</li><li>@php($c = 1)</li></ul>'],
]);

it('reads an operation from plain data and writes it back the same', function () {
    $operation = Operation::from(['type' => 'setAttribute', 'node' => 12, 'name' => 'href', 'value' => null]);

    expect($operation->toArray())->toBe(['type' => 'setAttribute', 'node' => '12', 'name' => 'href', 'value' => null, 'blade' => false])
        ->and(Operation::from(json_decode((string) json_encode($operation), true))->toArray())->toBe($operation->toArray())
        ->and(Operation::from($operation))->toBe($operation)
        ->and(Operation::make('move', '12', ['target' => 40, 'position' => 'after'])->toArray())->toBe(['type' => 'move', 'node' => '12', 'target' => '40', 'position' => 'after']);
});

it('refuses an operation it doesn\'t understand', function (array $operation, string $reason) {
    expect(fn () => Operation::from($operation))->toThrow(EditRejected::class, $reason);
})->with([
    'no type' => [['node' => '0'], 'needs a "type"'],
    'an unknown type' => [['type' => 'explode', 'node' => '0'], 'no operation "explode"'],
    'no node' => [['type' => 'remove'], 'needs a "node"'],
    'something missing' => [['type' => 'addClass', 'node' => '0'], 'addClass needs "class"'],
    'text where a flag goes' => [['type' => 'setEchoRaw', 'node' => '0', 'raw' => 'yes'], 'has to be true or false'],
    'a number where text goes' => [['type' => 'setText', 'node' => '0', 'text' => 5], 'has to be text'],
    'null where text goes' => [['type' => 'setCode', 'node' => '0', 'code' => null], 'has to be text'],
    'a list where text or null goes' => [['type' => 'setArguments', 'node' => '0', 'arguments' => ['$a']], 'has to be text or null'],
    'a target that is no id' => [['type' => 'move', 'node' => '0', 'target' => null, 'position' => 'after'], 'has to be the id of a node'],
    'something it doesn\'t take' => [['type' => 'remove', 'node' => '0', 'force' => true], 'remove doesn\'t take "force"'],
]);

it('says which operation of a list was refused, and changes nothing', function () {
    $source = '<div><p>One</p><p>Two</p></div>';
    $first = nodeAt($source, '<p>One');
    $second = nodeAt($source, '<p>Two');

    $cases = [
        // What it names isn't there.
        [[['type' => 'addClass', 'node' => $first, 'class' => 'a'], ['type' => 'remove', 'node' => '999']], 1, 'no node "999"'],
        // The edit itself is refused.
        [[['type' => 'addClass', 'node' => $first, 'class' => 'a'], ['type' => 'setTag', 'node' => $second, 'tag' => 'br']], 1, ''],
        // The operation is not understood. Found before anything is changed.
        [[['type' => 'addClass', 'node' => $first, 'class' => 'a'], ['type' => 'remove', 'node' => $second], ['type' => 'nope', 'node' => $first]], 2, 'no operation "nope"'],
        // An earlier operation took the node out of the file.
        [[['type' => 'remove', 'node' => $first], ['type' => 'addClass', 'node' => $first, 'class' => 'a']], 1, 'earlier operation'],
        [[['type' => 'setCode', 'node' => $first, 'code' => '<p>1</p>'], ['type' => 'move', 'node' => $second, 'target' => $first, 'position' => 'before']], 1, 'earlier operation'],
        [[['type' => 'unwrap', 'node' => '0'], ['type' => 'addClass', 'node' => $first, 'class' => 'a']], 1, 'earlier operation'],
    ];
    foreach ($cases as [$operations, $index, $reason]) {
        $e = rejection(fn () => Operations::apply($source, $operations));

        expect($e)->toBeInstanceOf(EditRejected::class)
            ->and($e)->not->toBeInstanceOf(ResultRejected::class)
            ->and($e->operation)->toBe($index)
            ->and($e->getMessage())->toContain($reason);
    }

    // A node that stays where it is can be named again and again.
    expect(Operations::apply($source, [
        ['type' => 'addClass', 'node' => $first, 'class' => 'a'],
        ['type' => 'move', 'node' => $first, 'target' => $second, 'position' => 'after'],
        ['type' => 'setAttribute', 'node' => $first, 'name' => 'id', 'value' => 'one'],
    ]))->toBe('<div><p>Two</p><p class="a" id="one">One</p></div>');
});

it('will not hand back a file that doesn\'t compile', function (string $source, array $operation, string $reason) {
    foreach (['node', 'target'] as $key) {
        if (isset($operation[$key])) {
            $operation[$key] = str_starts_with($operation[$key], 'b:') ? nodeAt($source, substr($operation[$key], 2), 'b') : nodeAt($source, $operation[$key]);
        }
    }
    $e = rejection(fn () => Operations::apply($source, [$operation]));

    expect($e)->toBeInstanceOf(ResultRejected::class)
        ->and($e->getMessage())->toContain($reason);
})->with([
    'a condition that is no PHP' => ['<p>@if ($a) x @endif</p>', ['type' => 'setArguments', 'node' => 'b:@if', 'arguments' => '$a +*'], 'doesn\'t compile: syntax error'],
    'a loop that is no loop' => ['<p>@foreach ($a as $b) x @endforeach</p>', ['type' => 'setArguments', 'node' => 'b:@foreach', 'arguments' => '$a'], 'doesn\'t compile'],
    'an attribute with broken Blade' => ['<p>x</p>', ['type' => 'setAttribute', 'node' => '<p>', 'name' => 'title', 'value' => '{{ $a +* }}', 'blade' => true], 'doesn\'t compile'],
    'code that is no PHP' => ['<p>@php($a = 1)</p>', ['type' => 'setCode', 'node' => '@php', 'code' => '@php($a = )'], 'doesn\'t compile'],
    'a @break left without its loop' => ['<ul>@foreach ($a as $b) <li>x</li> @break($b) @endforeach</ul>', ['type' => 'unwrap', 'node' => '@foreach'], '@break with no loop around it'],
    'a @continue put outside a loop' => ['<ul><li>x</li></ul>', ['type' => 'insertAfter', 'node' => '<li>', 'blade' => '@continue'], '@continue with no loop around it'],
    'a @break moved out of its loop' => ['<ul>@foreach ($a as $b) @if ($b) @break @endif @endforeach<li>x</li></ul>', ['type' => 'move', 'node' => '@if', 'target' => '<li>', 'position' => 'after'], '@break with no loop around it'],
]);

it('leaves a stray @break that was there before to whoever wrote it', function () {
    // It may be inside a loop the tree can't see, such as a directive of the application's own.
    $source = '<ul>@break<li class="a">x</li></ul>';

    expect(Operations::apply($source, [['type' => 'addClass', 'node' => nodeAt($source, '<li'), 'class' => 'b']]))
        ->toBe('<ul>@break<li class="a b">x</li></ul>');
});

it('refuses to work on a template that only opens as code', function () {
    $e = rejection(fn () => Operations::apply('<div>@if ($a)</div>@endif', [['type' => 'remove', 'node' => '0']]));

    expect($e)->toBeInstanceOf(EditRejected::class)
        ->and($e->getMessage())->toStartWith('This template can only be edited as code: ');
});

it('hands back the file as it was when there is nothing to do', function () {
    $source = '<p class="a">x</p>';

    expect(Operations::apply($source, []))->toBe($source)
        ->and(Operations::run($source, [])->focus)->toBeNull()
        ->and(Operations::run($source, [['type' => 'addClass', 'node' => '0', 'class' => 'a']])->source)->toBe($source)
        ->and(Operations::run($source, [['type' => 'addClass', 'node' => '0', 'class' => 'a']])->focus)->toBe('0');
});

it('says which node to select afterwards, by its id in the new file', function (array $operations, ?string $focus) {
    $source = "<main>\n    <h1 class=\"t\">Title</h1>\n    <div class=\"box\">\n        <p>One</p>\n    </div>\n    @if (\$a)\n        <em>Two</em>\n    @endif\n</main>\n";
    $find = fn (string $needle) => str_starts_with($needle, 'b:') ? nodeAt($source, substr($needle, 2), 'b') : nodeAt($source, $needle);
    foreach ($operations as $i => $operation) {
        foreach (['node', 'target'] as $key) {
            if (isset($operation[$key])) {
                $operations[$i][$key] = $find($operation[$key]);
            }
        }
    }

    $applied = Operations::run($source, $operations);
    $node = $applied->focus === null ? null : Template::open($applied->source)->find($applied->focus);

    expect($node === null ? null : trim(strtok($node->print(), "\n")))->toBe($focus);
})->with([
    'what was inserted' => [[['type' => 'insertAfter', 'node' => '<h1', 'blade' => '<h2>Sub</h2>']], '<h2>Sub</h2>'],
    'the first of several inserted' => [[['type' => 'append', 'node' => '<div', 'blade' => '<p>Two</p><p>Three</p>']], '<p>Two</p>'],
    'what was prepended' => [[['type' => 'prepend', 'node' => 'b:@if', 'blade' => '<b>Zero</b>']], '<b>Zero</b>'],
    'the copy' => [[['type' => 'duplicate', 'node' => '<div']], '<div class="box">'],
    'the wrapper' => [[['type' => 'wrap', 'node' => '<h1', 'wrapper' => '<header></header>']], '<header>'],
    'the node where it was moved to' => [[['type' => 'move', 'node' => '<p>', 'target' => 'b:@if', 'position' => 'inside']], '<p>One</p>'],
    'the node after a reorder' => [[['type' => 'move', 'node' => '<h1', 'target' => '<div', 'position' => 'after']], '<h1 class="t">Title</h1>'],
    'what was inside the wrapper' => [[['type' => 'unwrap', 'node' => '<div']], '<p>One</p>'],
    'the node whose attribute changed, further down by now' => [[['type' => 'insertBefore', 'node' => '<h1', 'blade' => '<nav></nav>'], ['type' => 'addClass', 'node' => '<div', 'class' => 'wide']], '<div class="box wide">'],
    'the arm whose condition changed' => [[['type' => 'setArguments', 'node' => 'b:@if', 'arguments' => '$b']], '@if ($b)'],
    'what a node was rewritten as' => [[['type' => 'setCode', 'node' => '<h1', 'code' => '<h3>T</h3>']], '<h3>T</h3>'],
    'nothing, after a removal' => [[['type' => 'remove', 'node' => '<h1']], null],
    'nothing, when the last operation removed' => [[['type' => 'addClass', 'node' => '<h1', 'class' => 'x'], ['type' => 'remove', 'node' => '<div']], null],
]);
