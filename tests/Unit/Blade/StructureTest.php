<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Edit;
use Sunrice\VisualEditor\Blade\EditRejected;
use Sunrice\VisualEditor\Blade\Layout;
use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Template;

const CARDS = <<<'BLADE'
    <ul class="cards">
        <li>One</li>
        <li>
            <b>Two</b>
        </li>
    </ul>

    BLADE;

/** Applies an edit to a snippet, checks the result still opens, and returns it. */
function restructured(string $blade, Closure $edit): string
{
    $template = Template::open($blade);
    $edit($template->elements(), $template);
    $out = $template->print();
    Template::open($out);

    return $out;
}

it('gives a node inserted beside one on its own line a line of its own', function () {
    expect(restructured(CARDS, fn (array $el) => Edit::insertAfter($el[1], '<li>New</li>')))->toBe(<<<'BLADE'
        <ul class="cards">
            <li>One</li>
            <li>New</li>
            <li>
                <b>Two</b>
            </li>
        </ul>

        BLADE)
        ->and(restructured(CARDS, fn (array $el) => Edit::insertBefore($el[1], '<li>New</li>')))->toBe(<<<'BLADE'
        <ul class="cards">
            <li>New</li>
            <li>One</li>
            <li>
                <b>Two</b>
            </li>
        </ul>

        BLADE);
});

it('indents the lines of an inserted snippet to where it lands', function () {
    expect(restructured(CARDS, fn (array $el) => Edit::append($el[0], "<li>\n    <i>New</i>\n</li>")))->toBe(<<<'BLADE'
        <ul class="cards">
            <li>One</li>
            <li>
                <b>Two</b>
            </li>
            <li>
                <i>New</i>
            </li>
        </ul>

        BLADE)
        ->and(restructured(CARDS, fn (array $el) => Edit::prepend($el[2], "<i>\n    New\n</i>")))->toBe(<<<'BLADE'
        <ul class="cards">
            <li>One</li>
            <li>
                <i>
                    New
                </i>
                <b>Two</b>
            </li>
        </ul>

        BLADE);
});

it('joins inline content without adding whitespace', function () {
    expect(restructured(CARDS, fn (array $el) => Edit::append($el[1], '<i>!</i>')))->toContain('<li>One<i>!</i></li>')
        ->and(restructured('<p>Hello <b>you</b>, hi</p>', fn (array $el) => Edit::insertAfter($el[1], '<i>all</i>')))->toBe('<p>Hello <b>you</b><i>all</i>, hi</p>')
        ->and(restructured('<p>Hello <b>you</b>, hi</p>', fn (array $el) => Edit::insertBefore($el[1], '<i>all</i>')))->toBe('<p>Hello <i>all</i><b>you</b>, hi</p>')
        ->and(restructured('<p>Hello</p>', fn (array $el) => Edit::prepend($el[0], '{{ $greeting }} ')))->toBe('<p>{{ $greeting }} Hello</p>');
});

it('opens up an empty element that has a line to itself', function () {
    expect(restructured("<main>\n    <section class=\"hero\"></section>\n</main>\n", fn (array $el) => Edit::append($el[1], '<h1>{{ $entry->title }}</h1>')))
        ->toBe("<main>\n    <section class=\"hero\">\n        <h1>{{ \$entry->title }}</h1>\n    </section>\n</main>\n")
        ->and(restructured("<main>\n    <ul>\n    </ul>\n</main>\n", fn (array $el) => Edit::prepend($el[1], '<li>x</li>')))
        ->toBe("<main>\n    <ul>\n        <li>x</li>\n    </ul>\n</main>\n")
        ->and(restructured('<p><b></b> tail</p>', fn (array $el) => Edit::append($el[1], 'x')))->toBe('<p><b>x</b> tail</p>')
        ->and(restructured('', fn (array $el, Template $t) => Edit::append($t->root, '<p>First</p>')))->toBe('<p>First</p>');
});

it('follows the indentation the file uses', function () {
    $two = "<nav>\n  <ul>\n    <li>a</li>\n  </ul>\n</nav>\n";
    $tabs = "<nav>\n\t<ul>\n\t\t<li>a</li>\n\t</ul>\n</nav>\n";

    expect(restructured($two, fn (array $el) => Edit::wrap($el[2], '<div class="w"></div>')))->toBe("<nav>\n  <ul>\n    <div class=\"w\">\n      <li>a</li>\n    </div>\n  </ul>\n</nav>\n")
        ->and(restructured($tabs, fn (array $el) => Edit::wrap($el[2], '<div class="w"></div>')))->toBe("<nav>\n\t<ul>\n\t\t<div class=\"w\">\n\t\t\t<li>a</li>\n\t\t</div>\n\t</ul>\n</nav>\n")
        ->and(restructured($tabs, fn (array $el) => Edit::insertAfter($el[2], '<li>b</li>')))->toBe("<nav>\n\t<ul>\n\t\t<li>a</li>\n\t\t<li>b</li>\n\t</ul>\n</nav>\n");
});

it('deletes a node with the line it had to itself', function () {
    expect(restructured(CARDS, fn (array $el) => Edit::remove($el[1])))->toBe("<ul class=\"cards\">\n    <li>\n        <b>Two</b>\n    </li>\n</ul>\n")
        ->and(restructured(CARDS, fn (array $el) => Edit::remove($el[2])))->toBe("<ul class=\"cards\">\n    <li>One</li>\n</ul>\n")
        ->and(restructured(CARDS, fn (array $el) => Edit::remove($el[3])))->toBe("<ul class=\"cards\">\n    <li>One</li>\n    <li>\n    </li>\n</ul>\n")
        ->and(restructured("{{-- note --}}\n<p>x</p>\n", fn (array $el, Template $t) => Edit::remove($t->selectable()[0])))->toBe("{{-- note --}}\n")
        ->and(restructured("@csrf\n<p>x</p>\n", fn (array $el, Template $t) => Edit::remove($t->selectable()[0])))->toBe("<p>x</p>\n")
        ->and(restructured("<p>\r\n    <b>x</b>\r\n    <i>y</i>\r\n</p>", fn (array $el) => Edit::remove($el[1])))->toBe("<p>\r\n    <i>y</i>\r\n</p>");
});

it('deletes an inline node and nothing around it', function () {
    expect(restructured('<p>Hello <b>you</b>, hi</p>', fn (array $el) => Edit::remove($el[1])))->toBe('<p>Hello , hi</p>')
        ->and(restructured("<p>\n    Hello <b>you</b>\n</p>", fn (array $el) => Edit::remove($el[1])))->toBe("<p>\n    Hello \n</p>");
});

it('keeps directives apart when a deletion brings them together', function () {
    expect(restructured('@if ($a) x @else{{ $b }}@endif', fn (array $el, Template $t) => Edit::remove($t->root->children[0]->children[1]->children[0])))
        ->toBe('@if ($a) x @else @endif')
        ->and(restructured('@if ($a)<b>x</b>@else<i>y</i>(note)@endif', fn (array $el) => Edit::remove($el[1])))
        ->toBe('@if ($a)<b>x</b>@else&#40;note)@endif');
});

it('duplicates a node beside itself', function () {
    expect(restructured(CARDS, fn (array $el) => Edit::duplicate($el[2])))->toBe(<<<'BLADE'
        <ul class="cards">
            <li>One</li>
            <li>
                <b>Two</b>
            </li>
            <li>
                <b>Two</b>
            </li>
        </ul>

        BLADE)
        ->and(restructured('<p>Hello <b>you</b>, hi</p>', fn (array $el) => Edit::duplicate($el[1])))->toBe('<p>Hello <b>you</b><b>you</b>, hi</p>')
        ->and(restructured("<svg>\n    <path d=\"M0 0\" />\n</svg>", fn (array $el) => Edit::duplicate($el[1])))->toBe("<svg>\n    <path d=\"M0 0\" />\n    <path d=\"M0 0\" />\n</svg>");
});

it('reorders siblings and leaves the whitespace between them where it is', function () {
    $moved = "<ul class=\"cards\">\n    <li>\n        <b>Two</b>\n    </li>\n    <li>One</li>\n</ul>\n";

    expect(restructured(CARDS, fn (array $el) => Edit::move($el[1], $el[2], 'after')))->toBe($moved)
        ->and(restructured(CARDS, fn (array $el) => Edit::move($el[2], $el[1], 'before')))->toBe($moved)
        ->and(restructured('<p><a>1</a> <b>2</b> text <i>3</i></p>', fn (array $el) => Edit::move($el[1], $el[3], 'after')))->toBe('<p><b>2</b> text <i>3</i> <a>1</a></p>');
});

it('moves a node to another parent and indents it for there', function () {
    expect(restructured(CARDS, fn (array $el) => Edit::move($el[3], $el[0], 'before')))->toBe(<<<'BLADE'
        <b>Two</b>
        <ul class="cards">
            <li>One</li>
            <li>
            </li>
        </ul>

        BLADE)
        ->and(restructured("<div>\n    <section>\n        <h1>\n            Title\n        </h1>\n    </section>\n    <footer></footer>\n</div>", fn (array $el) => Edit::move($el[2], $el[3], 'inside')))
        ->toBe("<div>\n    <section>\n    </section>\n    <footer>\n        <h1>\n            Title\n        </h1>\n    </footer>\n</div>")
        ->and(restructured("<div>\n    <section>\n        <h1>\n            Title\n        </h1>\n    </section>\n</div>", fn (array $el) => Edit::move($el[2], $el[1], 'after')))
        ->toBe("<div>\n    <section>\n    </section>\n    <h1>\n        Title\n    </h1>\n</div>");
});

it('wraps a node on its own line by moving it in one step', function () {
    expect(restructured(CARDS, fn (array $el) => Edit::wrap($el[2], '@if ($show)@endif')))->toBe(<<<'BLADE'
        <ul class="cards">
            <li>One</li>
            @if ($show)
                <li>
                    <b>Two</b>
                </li>
            @endif
        </ul>

        BLADE);
});

it('wraps inline nodes and text where they stand', function () {
    expect(restructured('<p>Hello <b>you</b></p>', fn (array $el) => Edit::wrap($el[1], '<a href="{{ $url }}"></a>')))->toBe('<p>Hello <a href="{{ $url }}"><b>you</b></a></p>')
        ->and(restructured('<p>Call us now</p>', fn (array $el) => Edit::wrap($el[0]->children[0], '<strong></strong>')))->toBe('<p><strong>Call us now</strong></p>')
        ->and(restructured("<li>\n    Hello world\n</li>", fn (array $el) => Edit::wrap($el[0]->children[0], '<a href="/x"></a>')))->toBe("<li>\n    <a href=\"/x\">Hello world</a>\n</li>")
        ->and(restructured('<p>{{ $a }}</p>', fn (array $el) => Edit::wrap($el[0]->children[0], '@isset($a)@endisset')))->toBe('<p>@isset($a){{ $a }}@endisset</p>');
});

it('refuses a wrapper that is not one empty element or block', function (string $wrapper) {
    $template = Template::open('<p><b>x</b></p>');

    expect(fn () => Edit::wrap($template->elements()[1], $wrapper))->toThrow(EditRejected::class)
        ->and($template->print())->toBe('<p><b>x</b></p>');
})->with([
    'two elements' => ['<div></div><div></div>'],
    'an element with content' => ['<div>x</div>'],
    'a void element' => ['<br>'],
    'text' => ['hello'],
    'a block with two arms' => ['@if ($a)@else@endif'],
    'something unclosed' => ['<div>'],
    'a script' => ['<script></script>'],
]);

it('unwraps an element or a block and moves what was inside out one step', function () {
    expect(restructured(CARDS, fn (array $el) => Edit::unwrap($el[2])))->toBe("<ul class=\"cards\">\n    <li>One</li>\n    <b>Two</b>\n</ul>\n")
        ->and(restructured(CARDS, fn (array $el) => Edit::unwrap($el[0])))->toBe("<li>One</li>\n<li>\n    <b>Two</b>\n</li>\n")
        ->and(restructured('<p>Hello <a href="/x"><b>you</b></a></p>', fn (array $el) => Edit::unwrap($el[1])))->toBe('<p>Hello <b>you</b></p>')
        ->and(restructured("<div>\n    @if (\$a)\n        <p>x</p>\n    @endif\n</div>", fn (array $el, Template $t) => Edit::unwrap($t->selectable()[1])))->toBe("<div>\n    <p>x</p>\n</div>")
        ->and(restructured("<div>\n    <span></span>\n    <p>x</p>\n</div>", fn (array $el) => Edit::unwrap($el[1])))->toBe("<div>\n    <p>x</p>\n</div>");
});

it('will not unwrap what has nothing to keep or more than one arm', function () {
    $template = Template::open('<p><br>@if ($a) x @else y @endif</p>');

    expect(fn () => Edit::unwrap($template->elements()[1]))->toThrow(EditRejected::class)
        ->and(fn () => Edit::unwrap($template->selectable()[2]))->toThrow(EditRejected::class, 'more than one arm')
        ->and($template->print())->toBe('<p><br>@if ($a) x @else y @endif</p>');
});

it('puts nothing where nothing can go', function () {
    $template = Template::open('<div><img src="a"><script>let a;</script>@switch($t) @case(1) <b>x</b> @break @endswitch @forelse ($a as $b) <i>y</i> @empty <u>z</u> @endforelse</div>');
    [$div, $img, $script, $b, $i] = $template->elements();
    $nodes = iterator_to_array($template->root->walk(), false);
    $empty = collect($nodes)->first(fn (Node $n) => $n->type === 'atom' && $n->name === 'empty');
    $arm = collect($nodes)->first(fn (Node $n) => $n->type === 'branch' && $n->name === 'forelse');
    $before = $template->print();

    expect(fn () => Edit::append($img, '<b></b>'))->toThrow(EditRejected::class)            // void
        ->and(fn () => Edit::append($script, '<b></b>'))->toThrow(EditRejected::class)      // code, not markup
        ->and(fn () => Edit::append($b, 'x'))->toThrow(EditRejected::class)                 // inside a code block
        ->and(fn () => Edit::insertAfter($b, '<b></b>'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::insertBefore($template->root, '<b></b>'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::insertAfter($arm, '<b></b>'))->toThrow(EditRejected::class)    // an arm has no "beside"
        ->and(fn () => Edit::remove($empty))->toThrow(EditRejected::class)                  // an arm of @forelse
        ->and(fn () => Edit::move($empty, $div, 'inside'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::move($div, $i, 'inside'))->toThrow(EditRejected::class, 'itself')
        ->and(fn () => Edit::move($i, $i, 'after'))->toThrow(EditRejected::class, 'itself')
        ->and(fn () => Edit::move($i, $img, 'inside'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::move($i, $div, 'under'))->toThrow(EditRejected::class)
        ->and(fn () => Edit::append($div, '<li>'))->toThrow(EditRejected::class, 'never closed')
        ->and($template->print())->toBe($before);

    // An arm of a block does hold children. A @forelse is one arm with
    // @empty in it, so "inside, at the end" is after the @empty part;
    // the loop's own part is reached by inserting before @empty.
    Edit::append($arm, '<b>more</b>');
    Edit::insertBefore($empty, '<s>each</s>');
    expect($template->print())->toContain('<i>y</i> <s>each</s>@empty <u>z</u> <b>more</b>@endforelse');
});

it('reads how a file is laid out around a node', function () {
    $template = Template::open("<main>\n  <p>a <b>c</b></p>\n\t<i>d</i>\n</main>");
    [$main, $p, $b, $i] = $template->elements();

    expect(Layout::indent($main))->toBe('')
        ->and(Layout::indent($p))->toBe('  ')
        ->and(Layout::indent($b))->toBeNull()
        ->and(Layout::indent($i))->toBe("\t")
        ->and(Layout::step($p))->toBe('  ')
        ->and(Layout::step($main))->toBe(Layout::STEP);
});

it('reindents the lines of a snippet but not text where spaces matter', function () {
    expect(Layout::reindent("<a>\n    <b>\n\n    </b>\n</a>", '', '  '))->toBe("<a>\n      <b>\n\n      </b>\n  </a>")
        ->and(Layout::reindent("<a>\n        <b></b>\n    </a>", '    ', ''))->toBe("<a>\n    <b></b>\n</a>")
        ->and(Layout::reindent("<pre>\n  a\n</pre>", '', '    '))->toBe("<pre>\n  a\n</pre>")
        ->and(Layout::reindent('<a></a>', '', '    '))->toBe('<a></a>');
});
