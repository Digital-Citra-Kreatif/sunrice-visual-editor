<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Variables;

/*
 * What a template has to be given to be drawn: the variables it reads
 * without setting them itself.
 */

it('finds the variables a template needs', function (string $blade, array $needed) {
    expect(Variables::needed($blade))->toBe($needed);
})->with([
    'an echo' => ['<h1>{{ $title }}</h1>', ['title']],
    'in the order they are first read, each once' => ['{{ $b }} {{ $a }} {{ $b->c }}', ['b', 'a']],
    'what a loop goes over, not what it calls each one' => ['@foreach ($rows as $key => $row) {{ $key }} {{ $row->x }} {{ $loop->index }} @endforeach', ['rows']],
    'set before it is read' => ['@php($n = 1) {{ $n }}', []],
    'read before it is set' => ['{{ $n }} @php($n = 1)', ['n']],
    'set in a @php block' => ["@php\n    \$site = cms_global('site');\n@endphp\n{{ \$site->name }}", []],
    'set with others at once' => ['@php([$value, $label] = $option) {{ $value }}{{ $label }}', ['option']],
    'set only if it was missing' => ['@php($total ??= 0) {{ $total }}', []],
    'added to: it has to be there already' => ['@php($list .= "x")', ['list']],
    'asked about with isset' => ['@isset($title) <h1>{{ $title }}</h1> @endisset', []],
    'read with a fallback' => ['{{ $sub ?? "" }} {{ $entry->image->alt ?? "" }} {{ $block["x"]["y"] ?? "" }}', []],
    'read with a fallback, then without' => ['{{ $entry->image->alt ?? $entry->title }} {{ $sub ?? "" }} {{ $sub }}', ['entry', 'sub']],
    'asked about with empty' => ['@if (! empty($button["url"])) <a href="{{ $button["url"] }}">x</a> @endif', []],
    'the fallback of a ?? has to be there' => ['{{ $a ?? $b }}', ['b']],
    'a parameter of a function is its own' => ['{{ collect($items)->map(fn ($item) => $item->name.$suffix)->implode(", ") }}', ['items', 'suffix']],
    'in a string' => ['{{ "Hello $name" }}', ['name']],
    'passed on to an include' => ["@include('sunrice.partials.menu', ['items' => \$mainMenu])", ['mainMenu']],
    'a static property is not a variable' => ['{{ App\Models\Post::$count }}', []],
    'what Blade and Laravel give every template' => ['{{ $locale }} {{ cms_page()->entry }} {{ $errors->first() }} {{ $loop->first }}', []],
    'nothing' => ['<p>Plain</p>', []],
]);

it('reads what it can of a template that does not compile', function () {
    expect(Variables::needed('@if ($a) {{ $b }}'))->toBe(['a', 'b']);
});
