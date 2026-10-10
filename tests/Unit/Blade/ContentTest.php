<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Edit;
use Sunrice\VisualEditor\Blade\EditRejected;
use Sunrice\VisualEditor\Blade\Fragment;
use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Template;

/** The first node of a kind in a snippet: "text", "echo", "directive", "arm" (of a block) or "code". */
function firstNode(Template $template, string $what): Node
{
    foreach ($template->root->walk() as $node) {
        $found = match ($what) {
            'text' => $node->type === 'text' && trim($node->open) !== '',
            'echo' => $node->type === 'atom' && in_array($node->kind, ['echo', 'raw_echo'], true),
            'directive' => $node->type === 'atom' && $node->kind === 'directive',
            'arm' => $node->type === 'branch',
            default => false,
        };
        if ($found) {
            return $node;
        }
    }

    throw new RuntimeException("No {$what} in the snippet.");
}

/** Applies an edit to the first node of a kind and returns the file as it would be written. */
function rewritten(string $blade, string $what, Closure $edit): string
{
    $template = Template::open($blade);
    $edit(firstNode($template, $what));

    return $template->print();
}

it('retypes text and keeps the whitespace around it', function (string $before, string $after) {
    expect(rewritten($before, 'text', fn (Node $text) => Edit::setText($text, 'New words')))->toBe($after);
})->with([
    'inline' => ['<h1>Old</h1>', '<h1>New words</h1>'],
    'on its own lines' => ["<p>\n    Old words\n</p>", "<p>\n    New words\n</p>"],
    'beside an echo' => ['<p>Hello {{ $name }}</p>', '<p>New words {{ $name }}</p>'],
    'inside a condition' => ['<p>@if ($a) one @else two @endif</p>', '<p>@if ($a) New words @else two @endif</p>'],
]);

it('puts a space where retyped text would run into a directive', function () {
    expect(rewritten('<p>@isset($a)!@endisset</p>', 'text', fn (Node $text) => Edit::setText($text, 'yes')))
        ->toBe('<p>@isset($a)yes @endisset</p>');

    $template = Template::open('<p>@if ($a) one @else-two @endif</p>');
    Edit::setText($template->texts()[1], 'two');
    expect($template->print())->toBe('<p>@if ($a) one @else two @endif</p>');
});

it('writes typed text as text', function () {
    expect(rewritten('<p>Old</p>', 'text', fn (Node $text) => Edit::setText($text, '<b>{{ $x }}</b> @if & "q"')))
        ->toBe('<p>&lt;b&gt;&#123;&#123; $x }}&lt;/b&gt; &#64;if &amp; "q"</p>');
});

it('only retypes text that is text', function () {
    $template = Template::open('<p>Words</p><script>let a = 1;</script><style>a{}</style>');
    [$p, $script, $style] = $template->elements();

    expect(fn () => Edit::setText($p, 'x'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::setText($script->children[0], 'x'))->toThrow(EditRejected::class, 'code')
        ->and(fn () => Edit::setText($style->children[0], 'x'))->toThrow(EditRejected::class, 'code')
        ->and($template->print())->toBe('<p>Words</p><script>let a = 1;</script><style>a{}</style>');
});

it('changes what an echo prints, keeping its delimiters and spacing', function (string $before, string $after) {
    expect(rewritten($before, 'echo', fn (Node $echo) => Edit::setExpression($echo, " \$entry->get('heading') ?? \$entry->title ")))->toBe($after);
})->with([
    'escaped' => ['<h1>{{ $entry->title }}</h1>', "<h1>{{ \$entry->get('heading') ?? \$entry->title }}</h1>"],
    'raw' => ['<div>{!! $entry->get(\'body\') !!}</div>', "<div>{!! \$entry->get('heading') ?? \$entry->title !!}</div>"],
    'written tight' => ['<h1>{{$a}}</h1>', "<h1>{{\$entry->get('heading') ?? \$entry->title}}</h1>"],
    'over lines' => ["<h1>{{\n    \$a\n}}</h1>", "<h1>{{\n    \$entry->get('heading') ?? \$entry->title\n}}</h1>"],
]);

it('refuses an expression that is not one', function (string $expression) {
    $template = Template::open('<h1>{{ $a }}</h1>');

    expect(fn () => Edit::setExpression(firstNode($template, 'echo'), $expression))->toThrow(EditRejected::class)
        ->and($template->print())->toBe('<h1>{{ $a }}</h1>');
})->with([
    'nothing' => ['   '],
    'the end of the echo and more' => ['$b }} <script>alert(1)</script> {{ $c'],
    'half an expression' => ['$b +'],
    'a statement' => ['$b; unlink(__FILE__)'],
    'a closing tag' => ['$b ?> <b>'],
]);

it('only sets the expression of an echo', function () {
    $template = Template::open('<p>Text</p>@csrf');

    expect(fn () => Edit::setExpression($template->elements()[0], '$a'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::setExpression(firstNode($template, 'directive'), '$a'))->toThrow(EditRejected::class);
});

it('switches an echo between escaped and raw', function () {
    expect(rewritten('<div>{{ $body }}</div>', 'echo', fn (Node $echo) => Edit::setEchoRaw($echo, true)))->toBe('<div>{!! $body !!}</div>')
        ->and(rewritten('<div>{!!$body!!}</div>', 'echo', fn (Node $echo) => Edit::setEchoRaw($echo, false)))->toBe('<div>{{$body}}</div>')
        ->and(rewritten('<div>{{ $body }}</div>', 'echo', fn (Node $echo) => Edit::setEchoRaw($echo, false)))->toBe('<div>{{ $body }}</div>');

    $template = Template::open('<div>{{ $body }}</div>');
    Edit::setEchoRaw(firstNode($template, 'echo'), true);
    expect(firstNode(Template::open($template->print()), 'echo')->kind)->toBe('raw_echo');
});

it('changes what is between the brackets of a block\'s arm', function (string $before, string $arguments, string $after) {
    expect(rewritten($before, 'arm', fn (Node $arm) => Edit::setArguments($arm, $arguments)))->toBe($after);
})->with([
    'a condition' => ['@if ($a) x @endif', '$a && $b', '@if ($a && $b) x @endif'],
    'written tight' => ['@if($a) x @endif', 'count($list) > (1 + 2)', '@if(count($list) > (1 + 2)) x @endif'],
    'a loop' => ['@foreach ($items as $item) x @endforeach', '$entry->get(\'rows\') as $i => $row', '@foreach ($entry->get(\'rows\') as $i => $row) x @endforeach'],
    'with a bracket inside a string' => ['@if ($a) x @endif', '$a === \')\'', '@if ($a === \')\') x @endif'],
]);

it('changes the arguments of a single directive', function () {
    expect(rewritten('@include(\'a\')', 'directive', fn (Node $d) => Edit::setArguments($d, "'sunrice.partials.card', ['entry' => \$entry]")))
        ->toBe("@include('sunrice.partials.card', ['entry' => \$entry])")
        ->and(rewritten('<b>@csrf</b>', 'directive', fn (Node $d) => Edit::setArguments($d, "'x'")))->toBe("<b>@csrf('x')</b>")
        ->and(rewritten("@yield('a')<b>x</b>", 'directive', fn (Node $d) => Edit::setArguments($d, null)))->toBe('@yield<b>x</b>')
        ->and(rewritten("@yield('a')text", 'directive', fn (Node $d) => Edit::setArguments($d, null)))->toBe('@yield text');
});

it('changes a later arm of a block', function () {
    $template = Template::open('@if ($a) x @elseif($b) y @else z @endif');
    Edit::setArguments($template->root->children[0]->children[1], '$b || $c');

    expect($template->print())->toBe('@if ($a) x @elseif($b || $c) y @else z @endif');
});

it('refuses arguments that would end the directive early', function (string $arguments) {
    $template = Template::open('@if ($a) x @endif');

    expect(fn () => Edit::setArguments(firstNode($template, 'arm'), $arguments))->toThrow(EditRejected::class)
        ->and($template->print())->toBe('@if ($a) x @endif');
})->with([['$a) @php(system("id")'], ['$a)('], ['($a'], ['"unclosed']]);

it('only sets arguments of a directive, and keeps @empty what it is', function () {
    $template = Template::open('<p>{{ $a }}</p>@forelse ($a as $b) x @empty y @endforelse @empty($c) z @endempty');
    $arm = fn (string $head) => collect(iterator_to_array($template->root->walk(), false))->first(fn (Node $n) => str_starts_with($n->open, $head));

    expect(fn () => Edit::setArguments($template->elements()[0], '$x'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::setArguments(firstNode($template, 'echo'), '$x'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::setArguments($arm('@empty'), '$x'))->toThrow(EditRejected::class, '@empty')
        ->and(fn () => Edit::setArguments($arm('@empty($c)'), null))->toThrow(EditRejected::class, '@empty');
});

it('keeps brackets on the directives that need them and off the ones that take none', function () {
    $blade = '@if ($a) x @else y @endif @switch($t) @case(1) one @break @default other @endswitch @foreach ($a as $b) z @endforeach';
    $template = Template::open($blade);
    $arm = fn (string $name) => collect(iterator_to_array($template->root->walk(), false))->first(fn (Node $n) => $n->type === 'branch' && $n->name === $name);

    expect(fn () => Edit::setArguments($arm('if'), null))->toThrow(EditRejected::class, '@if needs')
        ->and(fn () => Edit::setArguments($arm('switch'), null))->toThrow(EditRejected::class, '@switch needs')
        ->and(fn () => Edit::setArguments($arm('case'), null))->toThrow(EditRejected::class, '@case needs')
        ->and(fn () => Edit::setArguments($arm('foreach'), null))->toThrow(EditRejected::class, '@foreach needs')
        ->and(fn () => Edit::setArguments($arm('else'), '$b'))->toThrow(EditRejected::class, '@else takes no')
        ->and(fn () => Edit::setArguments($arm('default'), '2'))->toThrow(EditRejected::class, '@default takes no')
        ->and($template->print())->toBe($blade);

    Edit::setArguments($arm('switch'), '$type');
    Edit::setArguments($arm('case'), "'one'");
    expect($template->print())->toStartWith('@if ($a) x @else y @endif @switch($type) @case(\'one\') one @break @default other @endswitch');
});

it('rewrites a node as code', function () {
    $edit = fn (string $code) => function (Node $php) use ($code) {
        Edit::setCode($php, $code);
    };

    expect(rewritten('<p>@php($a = 1) x</p>', 'directive', $edit("@php(\$a = 2)\n@php(\$b = 3)")))->toBe("<p>@php(\$a = 2)\n@php(\$b = 3) x</p>")
        ->and(rewritten('<p>@php($a = 1) x</p>', 'directive', $edit('@if ($a) <b>y</b> @endif')))->toBe('<p>@if ($a) <b>y</b> @endif x</p>')
        ->and(rewritten('<p>@php($a = 1) x</p>', 'directive', $edit('')))->toBe('<p> x</p>')
        ->and(rewritten('<p>@isset($a)@csrf @endisset</p>', 'directive', $edit('yes')))->toBe('<p>@isset($a)yes @endisset</p>');

    // An element can be rewritten too: the escape hatch for anything the panels don't cover.
    $template = Template::open('<ul><li>One</li></ul>');
    Edit::setCode($template->elements()[1], '<li @class([\'on\' => $a])>One</li><li>Two</li>');
    expect($template->print())->toBe('<ul><li @class([\'on\' => $a])>One</li><li>Two</li></ul>');
});

it('refuses code the tree could not hold, and changes nothing', function (string $code) {
    $template = Template::open('<p>@php($a = 1) x</p>');

    expect(fn () => Edit::setCode(firstNode($template, 'directive'), $code))->toThrow(EditRejected::class)
        ->and($template->print())->toBe('<p>@php($a = 1) x</p>');
})->with([
    'an unclosed element' => ['<div>'],
    'a stray closing tag' => ['</p><p>'],
    'half a condition' => ['@if ($a) x'],
    'an element across an arm' => ['@if ($a) <b> @else </b> @endif'],
    'the end of a condition' => ['x @endif'],
    'an arm of nothing' => ['x @else y'],
    'a loop never closed' => ['@foreach ($a as $b) x'],
]);

it('keeps half a block as it is written, and takes none in a snippet', function (string $blade, string $reason) {
    // In a file: the directive stays, marked, and the rest is read as usual.
    $template = Template::open($blade);
    expect($template->print())->toBe($blade)
        ->and(array_map(fn (Node $node) => $node->stray, $template->strays()))->toBe([$reason]);

    // In something about to be put into a file: refused.
    expect(fn () => Fragment::parse($blade))->toThrow(EditRejected::class, $reason);
})->with([
    ['<p>@if ($a) x</p>', '@if is never closed'],
    ['<p>x @endif</p>', '@endif has nothing to close'],
    ['<p>x @else y</p>', '@else has nothing before it to continue'],
    ['<p>@empty none</p>', '@empty is outside any @forelse'],
    ['<p>@case(1) one</p>', '@case is outside any @switch'],
    ['@empty($a) none', '@empty is never closed'],
]);

it('takes directives that are whole on their own', function (string $blade) {
    expect(Template::open($blade)->print())->toBe($blade);
})->with([
    ["@section('title', 'Home')"],
    ["@section('sidebar') <p>x</p> @show"],
    ["@section('sidebar') <p>x</p> @stop"],
    ['@php($a = 1)'],
    ["@lang('messages.welcome')"],
    ['@foreach ($a as $b) @break($b) @continue @endforeach'],
    ["@hasSection('nav') <nav>@yield('nav')</nav> @endif"],
    ["@use('App\\Models\\Post') @props(['a']) @csrf @method('PUT')"],
]);

it('will not rewrite an arm of a block or what is inside a code block', function () {
    $template = Template::open("@forelse (\$a as \$b) x @empty y @endforelse\n@switch(\$t) @case(1) <b>one</b> @break @endswitch\n@while (\$w) <u>w</u> @endwhile");
    $nodes = collect(iterator_to_array($template->root->walk(), false));
    $empty = $nodes->first(fn (Node $n) => $n->type === 'branch' && $n->name === 'empty');
    $break = $nodes->first(fn (Node $n) => $n->type === 'atom' && $n->name === 'break');
    [$bold, $underline] = $template->elements();

    expect(fn () => Edit::setCode($empty, ''))->toThrow(EditRejected::class)
        ->and(fn () => Edit::setCode($break, ''))->toThrow(EditRejected::class)
        ->and(fn () => Edit::setCode($underline, '<i>w</i>'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::setCode($template->root, ''))->toThrow(EditRejected::class);

    // An arm of a @switch is no code block: what is in it is edited like anything else.
    Edit::setCode($bold, '<i>one</i>');
    expect($template->print())->toContain('@case(1) <i>one</i> @break');
});

it('reads a snippet into nodes that belong to no tree yet', function () {
    $nodes = Fragment::parse('<li>{{ $a }}</li> @csrf');

    expect(array_map(fn (Node $n) => $n->type, $nodes))->toBe(['element', 'text', 'atom'])
        ->and($nodes[0]->parent)->toBeNull()
        ->and($nodes[0]->children[0]->parent)->toBe($nodes[0])
        ->and(Fragment::parse(''))->toBe([])
        ->and(fn () => Fragment::parse('<li>'))->toThrow(EditRejected::class, 'never closed');
});

it('reads directives the way the installed Blade does', function () {
    $kinds = fn (string $blade) => array_values(array_map(
        fn (Node $n) => $n->type.':'.$n->name,
        array_filter(iterator_to_array(Template::open($blade)->root->walk(), false), fn (Node $n) => $n->type !== 'text'),
    ));

    // Newer than the parser's own list, but Blade compiles it.
    expect($kinds("@includeIsolated('a.b', ['x' => 1])"))->toBe(['atom:includeIsolated'])
        // Addresses: an "@" straight after a letter or digit is not a directive to Blade.
        ->and($kinds('Write to joe@if.org or me@section.com, x@endif'))->toBe([])
        ->and($kinds('é@csrf'))->toBe(['atom:csrf']);
});
