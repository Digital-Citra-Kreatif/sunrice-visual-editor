<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Template;

/** @return list<array<string, mixed>> every node of the data, flat */
function flatten(array $node): array
{
    $nodes = [$node];
    foreach ($node['children'] ?? [] as $child) {
        $nodes = [...$nodes, ...flatten($child)];
    }

    return $nodes;
}

it('gives a template as data that survives JSON', function (string $path) {
    $data = Template::open((string) file_get_contents($path))->toArray();

    expect(json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR))->toBe($data);
})->with('templates that open');

it('gives every node an id of its own that finds the same node again', function (string $path) {
    $source = (string) file_get_contents($path);
    $template = Template::open($source);
    $nodes = flatten($template->toArray()['tree']);
    $ids = array_column($nodes, 'id');

    expect($ids)->toBe(array_values(array_unique($ids)));

    foreach ($nodes as $data) {
        $node = $template->find($data['id']);
        expect($node)->not->toBeNull()
            ->and($node->type)->toBe($data['type'])
            ->and($node->print())->toBe(substr($source, $data['start'], $data['end'] - $data['start']));
    }
})->with('templates that open');

it('finds every selectable node by its id', function (string $path) {
    $template = Template::open((string) file_get_contents($path));

    foreach ($template->selectable() as $node) {
        expect($template->find($node->id()))->toBe($node);
    }
    expect($template->find('root'))->toBe($template->root)
        ->and($template->find('999999'))->toBeNull()
        ->and($template->find('nonsense'))->toBeNull();
})->with('templates that open');

it('names the exact bytes it was opened from', function () {
    $one = Template::open('<p>One</p>');

    expect($one->version())->toBe(sha1('<p>One</p>'))
        ->and(Template::open('<p>One</p>')->version())->toBe($one->version())
        ->and(Template::open('<p>One</p> ')->version())->not->toBe($one->version())
        ->and($one->toArray())->toHaveKeys(['version', 'bytes', 'tree'])
        ->and($one->toArray()['bytes'])->toBe(10);
});

it('describes each kind of node', function () {
    $tree = Template::open(<<<'BLADE'
        <section class="hero big" id="top" data-n="{{ $n }}">
            <h1 class="{{ $size }} title">{{ $entry->title }}</h1>
            <p>Plain   words
               here</p>
            {!! $entry->get('body') !!}
            {{ __('sunrice::frontend.more') }}
            {{ $a ?? $b }}
            @if ($entry->get('image'))
                <img src="{{ $entry->get('image')->url() }}" alt="">
            @else
                <span @class(['none' => true])>No image</span>
            @endif
            @include('sunrice.partials.card', ['entry' => $entry])
            @php($x = 1)
            {{-- a note --}}
            <!-- an HTML note -->
        </section>
        BLADE)->toArray()['tree'];

    $section = $tree['children'][0];
    $byLabel = fn (string $label) => collect(flatten($tree))->firstWhere('label', $label);

    expect($tree['id'])->toBe('root')
        ->and($section['label'])->toBe('section#top')
        ->and($section['kind'])->toBe('visual')
        ->and(array_column($section['attributes'], 'value', 'name'))->toBe(['class' => 'hero big', 'id' => 'top', 'data-n' => '{{ $n }}'])
        ->and(array_column($section['attributes'], 'dynamic', 'name'))->toBe(['class' => false, 'id' => false, 'data-n' => true]);

    // A class built by Blade is no name for the element.
    expect($byLabel('h1'))->not->toBeNull()
        ->and($byLabel('Plain words here')['type'])->toBe('text')
        ->and($byLabel('$entry->title')['kind'])->toBe('field')
        ->and($byLabel('$entry->get(\'body\')'))->toMatchArray(['kind' => 'field', 'atom' => 'raw_echo'])
        ->and($byLabel('__(\'sunrice::frontend.more\')')['kind'])->toBe('label')
        ->and($byLabel('$a ?? $b')['kind'])->toBe('expression');

    $if = $byLabel('@if ($entry->get(\'image\'))');
    expect($if)->toMatchArray(['type' => 'block', 'kind' => 'visual', 'name' => 'if'])
        ->and(array_column($if['children'], 'type'))->toBe(['branch', 'branch'])
        ->and(array_column($if['children'], 'label'))->toBe(['@if ($entry->get(\'image\'))', '@else'])
        ->and($if['children'][0]['id'])->toBe('b'.$if['start'])
        ->and($if['children'][0]['arguments'])->toBe('$entry->get(\'image\')');

    $span = $byLabel('span');
    expect($span['kind'])->toBe('mixed')
        ->and($span['attributes'][0])->toMatchArray(['name' => '@class', 'code' => true, 'source' => '@class([\'none\' => true])']);

    expect($byLabel('@include(\'sunrice.partials.card\', [\'entry\' => $entry])'))->toMatchArray(['kind' => 'visual', 'arguments' => '\'sunrice.partials.card\', [\'entry\' => $entry]'])
        ->and($byLabel('@php($x = 1)')['kind'])->toBe('code')
        ->and($byLabel('Blade comment')['source'])->toBe('{{-- a note --}}')
        ->and($byLabel('Comment')['source'])->toBe('<!-- an HTML note -->');
});

it('leaves out the whitespace between nodes', function () {
    $tree = Template::open("<ul>\n    <li>One</li>\n\n    <li>Two</li>\n</ul>\n")->toArray()['tree'];

    expect(array_column($tree['children'], 'type'))->toBe(['element'])
        ->and(array_column($tree['children'][0]['children'], 'label'))->toBe(['li', 'li']);
});

it('says what may be done to a node', function () {
    $tree = Template::open(<<<'BLADE'
        <div class="list">
            <img src="a.png" alt="">
            @forelse ($items as $item)
                <p>{{ $item }}</p>
            @empty
                <p>None</p>
            @endforelse
            <script>let a = 1;</script>
        </div>
        BLADE)->toArray()['tree'];
    $can = fn (string $label) => collect(flatten($tree))->firstWhere('label', $label)['can'];

    expect($tree['can'])->toBe(['move' => false, 'delete' => false, 'wrap' => false, 'insert' => true, 'attributes' => false])
        ->and($can('div.list'))->toBe(['move' => true, 'delete' => true, 'wrap' => true, 'insert' => true, 'attributes' => true])
        ->and($can('None'))->toMatchArray(['move' => false, 'wrap' => true])                           // text: wrapped, not moved
        ->and($can('img')['insert'])->toBeFalse()                       // void: holds nothing
        ->and($can('script')['insert'])->toBeFalse()                    // its content isn't markup
        ->and($can('@forelse ($items as $item)'))->toMatchArray(['move' => true, 'insert' => false])   // the block; its arms hold the children
        ->and($can('@empty'))->toMatchArray(['move' => false, 'delete' => false])                      // an arm, not a node of its own
        ->and($can('$item')['move'])->toBeTrue();
});

it('shows a code block as one piece of text', function () {
    $tree = Template::open(<<<'BLADE'
        @switch($type)
            @case('a')
                <b>A</b>
                @break
            @default
                <i>Other</i>
        @endswitch
        BLADE)->toArray()['tree'];
    $switch = $tree['children'][0];

    expect($switch)->toMatchArray(['type' => 'block', 'kind' => 'code', 'name' => 'switch'])
        ->and($switch['source'])->toStartWith('@switch($type)')
        ->and($switch['source'])->toEndWith('@endswitch')
        ->and($switch)->not->toHaveKey('children');
});
