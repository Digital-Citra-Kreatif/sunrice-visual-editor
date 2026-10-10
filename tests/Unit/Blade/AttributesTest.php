<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Attributes;
use Sunrice\VisualEditor\Blade\Edit;
use Sunrice\VisualEditor\Blade\EditRejected;
use Sunrice\VisualEditor\Blade\Inert;
use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Template;

/**
 * Opens a snippet, hands its first element to the edit and returns the file
 * as it would be written.
 */
function edited(string $blade, Closure $edit): string
{
    $template = Template::open($blade);
    $edit($template->elements()[0], $template);

    return $template->print();
}

it('adds an attribute after the last one', function (string $before, string $after) {
    expect(edited($before, fn (Node $el) => Edit::setAttribute($el, 'title', 'Hi')))->toBe($after);
})->with([
    'after another' => ['<a href="/x">Go</a>', '<a href="/x" title="Hi">Go</a>'],
    'on a bare tag' => ['<p>Text</p>', '<p title="Hi">Text</p>'],
    'before a self-closing slash' => ['<img src="a.png" />', '<img src="a.png" title="Hi" />'],
    'on a void element' => ['<br>', '<br title="Hi">'],
    'after a directive in the tag' => ['<li @class([\'on\' => $a])>x</li>', '<li @class([\'on\' => $a]) title="Hi">x</li>'],
    'in a tag over several lines' => ["<a\n    href=\"/x\"\n>Go</a>", "<a\n    href=\"/x\" title=\"Hi\"\n>Go</a>"],
]);

it('changes only the value of an attribute that is there', function (string $before, string $after) {
    expect(edited($before, fn (Node $el) => Edit::setAttribute($el, 'title', 'New "one"')))->toBe($after);
})->with([
    'double quoted' => ['<a title="Old" href="/x">Go</a>', '<a title="New &quot;one&quot;" href="/x">Go</a>'],
    'single quoted' => ["<a title='Old' href='/x'>Go</a>", "<a title='New &quot;one&quot;' href='/x'>Go</a>"],
    'unquoted' => ['<a title=Old href=/x>Go</a>', '<a title="New &quot;one&quot;" href=/x>Go</a>'],
    'without a value' => ['<a title href="/x">Go</a>', '<a title="New &quot;one&quot;" href="/x">Go</a>'],
    'written in capitals' => ['<a TITLE="Old">Go</a>', '<a TITLE="New &quot;one&quot;">Go</a>'],
]);

it('writes a bare attribute for a null value', function () {
    expect(edited('<input type="checkbox">', fn (Node $el) => Edit::setAttribute($el, 'checked', null)))->toBe('<input type="checkbox" checked>')
        ->and(edited('<input checked="checked" type="checkbox">', fn (Node $el) => Edit::setAttribute($el, 'checked', null)))->toBe('<input checked type="checkbox">');
});

it('writes a Blade value as given', function () {
    expect(edited('<a href="/old">Go</a>', fn (Node $el) => Edit::setAttribute($el, 'href', '{{ $entry->url }}', blade: true)))
        ->toBe('<a href="{{ $entry->url }}">Go</a>')
        ->and(edited('<a>Go</a>', fn (Node $el) => Edit::setAttribute($el, 'href', '{{ $link["url"] ?? \'#\' }}', blade: true)))
        ->toBe('<a href="{{ $link["url"] ?? \'#\' }}">Go</a>');
});

it('refuses a Blade value that would end the attribute, and changes nothing', function () {
    $template = Template::open('<a href="/old" class="x">Go</a>');
    $link = $template->elements()[0];

    expect(fn () => Edit::setAttribute($link, 'href', '/a" onclick="alert(1)', blade: true))->toThrow(EditRejected::class)
        ->and(fn () => Edit::setAttribute($link, 'data-x', 'a"b', blade: true))->toThrow(EditRejected::class)
        ->and($template->print())->toBe('<a href="/old" class="x">Go</a>')
        ->and($link->attributeValue('class'))->toBe('x');
});

it('keeps typed text as text', function (string $typed) {
    $template = Template::open('<p title="old">x</p>');
    Edit::setAttribute($template->elements()[0], 'title', $typed);
    $reopened = Template::open($template->print());
    $p = $reopened->elements()[0];

    expect(Inert::decode((string) $p->attributeValue('title')))->toBe($typed)
        ->and($p->attr('title')['dynamic'])->toBeFalse()
        ->and($p->hasCodeAttributes())->toBeFalse()
        ->and(count($reopened->selectable()))->toBe(1);   // the <p>, and no Blade
})->with([
    'an echo' => ['{{ $x }}'],
    'a raw echo' => ['{!! $x !!}'],
    'a directive' => ['@php echo 1; @endphp'],
    'a condition' => ['@if (true) x @endif'],
    'a Blade comment' => ['{{-- gone? --}}'],
    'escapes of its own' => ['a @@b @{{ c }}'],
    'markup' => ['<script>alert(1)</script> "q" \'s\' & <b>'],
    'a PHP tag' => ['<?php echo 1 ?>'],
    'an address' => ['me@example.com, joe@if.org'],
    'other scripts' => ['é — 日本語 🙂'],
]);

it('leaves an address readable in the file', function () {
    expect(Inert::attribute('me@example.com'))->toBe('me@example.com')
        ->and(Inert::text('Write to me@example.com or @sunrice'))->toBe('Write to me@example.com or &#64;sunrice');
});

it('removes an attribute with the space before it', function (string $before, string $name, string $after) {
    expect(edited($before, fn (Node $el) => Edit::removeAttribute($el, $name)))->toBe($after);
})->with([
    'the last' => ['<a href="/x" title="T">Go</a>', 'title', '<a href="/x">Go</a>'],
    'the first' => ['<a href="/x" title="T">Go</a>', 'href', '<a title="T">Go</a>'],
    'the only one' => ['<a href="/x">Go</a>', 'href', '<a>Go</a>'],
    'a bare one' => ['<input disabled type="text">', 'disabled', '<input type="text">'],
    'on its own line' => ["<a\n    href=\"/x\"\n    title=\"T\"\n>Go</a>", 'href', "<a\n    title=\"T\"\n>Go</a>"],
    'one that is not there' => ['<a href="/x">Go</a>', 'title', '<a href="/x">Go</a>'],
    'one with Blade in it' => ['<a href="{{ $u }}" title="T">Go</a>', 'href', '<a title="T">Go</a>'],
]);

it('will not guess between two attributes of the same name', function () {
    $template = Template::open('<p class="a" class="b">x</p>');

    expect(fn () => Edit::removeAttribute($template->elements()[0], 'class'))->toThrow(EditRejected::class, 'more than one')
        ->and($template->print())->toBe('<p class="a" class="b">x</p>');
});

it('lists the classes written as plain words', function () {
    $classes = fn (string $blade) => Attributes::classes(Template::open($blade)->elements()[0]);

    expect($classes('<p class="a  b c">x</p>'))->toBe(['a', 'b', 'c'])
        ->and($classes('<p>x</p>'))->toBe([])
        ->and($classes('<p class="">x</p>'))->toBe([])
        ->and($classes('<p class="a {{ $b }} c-{{ $d }} @if ($e) f @endif g">x</p>'))->toBe(['a', 'f', 'g']);
});

it('adds a class', function (string $before, string $after) {
    expect(edited($before, fn (Node $el) => Edit::addClass($el, 'new')))->toBe($after);
})->with([
    'to a list' => ['<p class="a b">x</p>', '<p class="a b new">x</p>'],
    'as the first attribute when there is no list' => ['<a href="/x">Go</a>', '<a class="new" href="/x">Go</a>'],
    'to an empty list' => ['<p class="">x</p>', '<p class="new">x</p>'],
    'after classes Blade prints' => ['<p class="a {{ $b }}">x</p>', '<p class="a {{ $b }} new">x</p>'],
    'once only' => ['<p class="new a">x</p>', '<p class="new a">x</p>'],
]);

it('removes a class and the space that went with it', function (string $before, string $class, string $after) {
    expect(edited($before, fn (Node $el) => Edit::removeClass($el, $class)))->toBe($after);
})->with([
    'the first' => ['<p class="a b c">x</p>', 'a', '<p class="b c">x</p>'],
    'the middle' => ['<p class="a b c">x</p>', 'b', '<p class="a c">x</p>'],
    'the last' => ['<p class="a b c">x</p>', 'c', '<p class="a b">x</p>'],
    'the only one, with its attribute' => ['<p id="i" class="a">x</p>', 'a', '<p id="i">x</p>'],
    'beside one Blade prints' => ['<p class="a {{ $b }} c">x</p>', 'a', '<p class="{{ $b }} c">x</p>'],
    'the last plain one beside one Blade prints' => ['<p class="{{ $b }} c">x</p>', 'c', '<p class="{{ $b }}">x</p>'],
    'one that is not there' => ['<p class="a b">x</p>', 'z', '<p class="a b">x</p>'],
    'not part of a longer name' => ['<p class="card card--wide">x</p>', 'card', '<p class="card--wide">x</p>'],
]);

it('refuses classes it cannot place', function () {
    $directive = Template::open('<li @class([\'on\' => $a]) class="x">x</li>')->elements()[0];
    $plain = Template::open('<p class="a">x</p>')->elements()[0];

    expect(fn () => Edit::addClass($directive, 'new'))->toThrow(EditRejected::class, '@class')
        ->and(fn () => Edit::removeClass($directive, 'x'))->toThrow(EditRejected::class, '@class')
        ->and(fn () => Edit::addClass($plain, 'two words'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::addClass($plain, '{{ $x }}'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::addClass($plain, ''))->toThrow(EditRejected::class)
        ->and($plain->open)->toBe('<p class="a">');
});

it('sets, changes and removes the id', function () {
    expect(edited('<p class="a">x</p>', fn (Node $el) => Edit::setId($el, 'intro')))->toBe('<p class="a" id="intro">x</p>')
        ->and(edited('<p id="old">x</p>', fn (Node $el) => Edit::setId($el, ' intro ')))->toBe('<p id="intro">x</p>')
        ->and(edited('<p id="old" class="a">x</p>', fn (Node $el) => Edit::setId($el, '')))->toBe('<p class="a">x</p>');
});

it('renames both tags of an element', function () {
    expect(edited('<div class="hero" id="top"><h1>Hi</h1></div>', fn (Node $el) => Edit::setTag($el, 'section')))
        ->toBe('<section class="hero" id="top"><h1>Hi</h1></section>')
        ->and(edited("<DIV class=\"a\">\n  x\n</DIV >", fn (Node $el) => Edit::setTag($el, 'Main')))->toBe("<main class=\"a\">\n  x\n</main >")
        ->and(edited('<img src="a.png">', fn (Node $el) => Edit::setTag($el, 'source')))->toBe('<source src="a.png">')
        ->and(edited('<p>x</p>', fn (Node $el) => Edit::setTag($el, 'p')))->toBe('<p>x</p>');
});

it('refuses a rename that would change how the file reads', function (string $blade, string $tag) {
    $template = Template::open($blade);

    expect(fn () => Edit::setTag($template->elements()[0], $tag))->toThrow(EditRejected::class)
        ->and($template->print())->toBe($blade);
})->with([
    'content into a void element' => ['<div>x</div>', 'img'],
    'a void element into one with a closing tag' => ['<br>', 'div'],
    'to script' => ['<div>x</div>', 'script'],
    'from style' => ['<style>a{}</style>', 'div'],
    'from svg' => ['<svg><path d="M0 0" /></svg>', 'div'],
    'to svg' => ['<div><span></span></div>', 'svg'],
    'not a name' => ['<div>x</div>', 'my tag'],
    'markup' => ['<div>x</div>', 'b><script'],
]);

it('refuses names that are not names', function (string $name) {
    $template = Template::open('<p>x</p>');

    expect(fn () => Edit::setAttribute($template->elements()[0], $name, 'v'))->toThrow(EditRejected::class)
        ->and($template->print())->toBe('<p>x</p>');
})->with([['on click'], ['a"b'], ['a=b'], ['<b>'], [''], ['{{ $x }}'], ['a>b']]);

it('accepts the attribute names front-end libraries use', function (string $name) {
    $template = Template::open('<p>x</p>');
    Edit::setAttribute($template->elements()[0], $name, 'v');

    expect(Template::open($template->print())->elements()[0]->attributeValue($name))->toBe('v');
})->with([['data-id'], ['aria-label'], ['x-data'], [':class'], ['x-on:click.prevent'], ['@click'], ['hx-get'], ['wire:model.live']]);

it('only edits attributes of elements', function () {
    $text = Template::open('<p>Words</p>')->elements()[0]->children[0];

    expect(fn () => Edit::setAttribute($text, 'title', 'x'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::addClass($text, 'x'))->toThrow(EditRejected::class);
});

it('takes several edits to one element in a row', function () {
    $template = Template::open("<a href=\"/x\">\n    Go\n</a>\n");
    $link = $template->elements()[0];

    Edit::addClass($link, 'button');
    Edit::setAttribute($link, 'href', '{{ $entry->url }}', blade: true);
    Edit::addClass($link, 'button--wide');
    Edit::setAttribute($link, 'target', '_blank');
    Edit::removeClass($link, 'button');
    Edit::setTag($link, 'a');
    Edit::setId($link, 'cta');

    expect($template->print())->toBe("<a class=\"button--wide\" href=\"{{ \$entry->url }}\" target=\"_blank\" id=\"cta\">\n    Go\n</a>\n")
        ->and(Template::open($template->print())->print())->toBe($template->print());
});
