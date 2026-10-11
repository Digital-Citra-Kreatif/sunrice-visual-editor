<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Template;

/*
 * Template::at(): the place a position in the source is at, for asking
 * what can be read there while code is typed. In the sources below "|" is
 * the position, and is no part of the text.
 */

/** @return array{0: string, 1: bool} what the node at "|" is, and whether the position is inside it */
function placeAt(string $marked): array
{
    $offset = (int) strpos($marked, '|');
    [$node, $inside] = Template::open(str_replace('|', '', $marked))->at($offset);

    return [match ($node->type) {
        'root' => 'root',
        'element' => '<'.$node->name.'>',
        'block', 'branch' => $node->type.' @'.$node->name,
        'text' => 'text',
        default => $node->type.' '.trim(strtok($node->open, "\n")),
    }, $inside];
}

it('says where a position is: the node it is before, in, or at the end of', function (string $marked, string $node, bool $inside) {
    expect(placeAt($marked))->toBe([$node, $inside]);
})->with([
    'before everything' => ["|<div>\n</div>\n", '<div>', false],
    'in an opening tag: where the element stands' => ['<div cl|ass="a"><p>x</p></div>', '<div>', false],
    'in a closing tag: where the element stands' => ['<div><p>x</p></di|v>', '<div>', false],
    'between two elements, before the second' => ['<div><p>x</p>|<span>y</span></div>', '<span>', false],
    'in the middle of a text' => ['<p>some te|xt</p>', 'text', false],
    'in an echo' => ['<p>{{ $en|try->title }}</p>', 'atom {{ $entry->title }}', false],
    'in an element that holds nothing yet' => ['<div><footer>|</footer></div>', '<footer>', true],
    'after everything an element holds' => ['<div><p>x</p>|</div>', '<div>', true],
    'in a loop\'s own line: where the loop stands, not in it' => ["@foreach (\$items as \$it|em)\n<p>x</p>\n@endforeach", 'branch @foreach', false],
    'in a loop, before what it holds' => ['@foreach ($items as $item)|<p>x</p>@endforeach', '<p>', false],
    'in a loop, after what it holds' => ['@foreach ($items as $item)<p>x</p>|@endforeach', 'branch @foreach', true],
    'in the second arm of a condition' => ['@if ($a)<p>x</p>@else<p>y</p>|@endif', 'branch @else', true],
    'in what ends a block: where the block stands' => ['@if ($a)<p>x</p>@end|if', 'block @if', false],
    'in an element with no end tag of its own' => ['<p><img sr|c="a.png"></p>', '<img>', false],
    'at the very end' => ["<div></div>\n<p>x</p>|", 'root', true],
    'in a template with nothing in it' => ['|', 'root', true],
]);

it('takes a position past the end for the end', function () {
    [$node, $inside] = Template::open('<p>x</p>')->at(999);

    expect($node->type)->toBe('root')->and($inside)->toBeTrue();
});
