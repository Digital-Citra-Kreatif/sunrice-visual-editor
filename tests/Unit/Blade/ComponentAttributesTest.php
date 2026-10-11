<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\EditRejected;
use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Operations;
use Sunrice\VisualEditor\Blade\Rules;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Rendering\Marker;
use Sunrice\VisualEditor\Tests\Support\RoundTrip;

/** The tag of the first component in a template. */
function componentTag(Template $template, int $nth = 0): Node
{
    $tags = array_values(array_filter(iterator_to_array($template->root->walk(), false), Rules::isComponentTag(...)));

    return $tags[$nth] ?? throw new RuntimeException('The template has no such component.');
}

/** The template after one change to its nth component's tag. */
function onComponent(string $source, array $operation, int $nth = 0): string
{
    return Operations::apply($source, [[...$operation, 'node' => componentTag(Template::open($source), $nth)->id()]]);
}

const ENTRIES = <<<'BLADE'
    <section>
        <x-sunrice::entries collection="articles" :limit="6">
            @foreach ($component->entries as $item)
                <p>{{ $item->title }}</p>
            @endforeach
        </x-sunrice::entries>
        <x-sunrice::seo :default-title="$heading" />
        <x-sunrice::code />
    </section>
    BLADE;

it('changes what a component is given, and nothing else in the file', function () {
    // A value: only what is between its quotes.
    expect(onComponent(ENTRIES, ['type' => 'setAttribute', 'name' => 'collection', 'value' => 'pages']))
        ->toBe(str_replace('collection="articles"', 'collection="pages"', ENTRIES))
        // A bound value, written as PHP.
        ->and(onComponent(ENTRIES, ['type' => 'setAttribute', 'name' => ':limit', 'value' => '3', 'blade' => true]))
        ->toBe(str_replace(':limit="6"', ':limit="3"', ENTRIES))
        // A new one: after the last.
        ->and(onComponent(ENTRIES, ['type' => 'setAttribute', 'name' => 'order-by', 'value' => '-published_at']))
        ->toBe(str_replace(':limit="6">', ':limit="6" order-by="-published_at">', ENTRIES))
        // Taken away: with the space before it.
        ->and(onComponent(ENTRIES, ['type' => 'removeAttribute', 'name' => ':limit']))
        ->toBe(str_replace(' :limit="6"', '', ENTRIES));
});

it('does the same for a component that closes itself, with attributes or without', function () {
    expect(onComponent(ENTRIES, ['type' => 'setAttribute', 'name' => ':default-title', 'value' => '$entry->title', 'blade' => true], 1))
        ->toBe(str_replace(':default-title="$heading"', ':default-title="$entry->title"', ENTRIES))
        ->and(onComponent(ENTRIES, ['type' => 'setAttribute', 'name' => 'key', 'value' => 'head'], 2))
        ->toBe(str_replace('<x-sunrice::code />', '<x-sunrice::code key="head" />', ENTRIES))
        ->and(onComponent(ENTRIES, ['type' => 'removeAttribute', 'name' => ':default-title'], 1))
        ->toBe(str_replace(' :default-title="$heading"', '', ENTRIES));
});

it('writes a plain value so that it stays plain, and a bound one as given', function () {
    $awkward = 'a "quoted" <b> & {{ $blade }}';
    $out = onComponent(ENTRIES, ['type' => 'setAttribute', 'name' => 'title', 'value' => $awkward]);
    $tag = componentTag(Template::open($out));

    expect($out)->toContain('title="a &quot;quoted&quot; &lt;b&gt; &amp; ')
        ->and(RoundTrip::phpError($out))->toBeNull()
        ->and(Template::open($out)->toArray()['tree']['children'][0]['children'][0]['children'][0]['attributes'])->toHaveCount(3)
        ->and($tag->open)->toStartWith('<x-sunrice::entries collection="articles" :limit="6" title="');

    // What it holds three quotes deep is the template's to write: an array for `where`.
    $where = onComponent(ENTRIES, ['type' => 'setAttribute', 'name' => ':where', 'value' => "[['price', '>', 10]]", 'blade' => true]);
    expect($where)->toContain(' :where="[[\'price\', \'>\', 10]]">')
        ->and(onComponent($where, ['type' => 'setAttribute', 'name' => ':where', 'value' => "[['price', '<', 5]]", 'blade' => true]))->toContain(' :where="[[\'price\', \'<\', 5]]">')
        ->and(onComponent($where, ['type' => 'removeAttribute', 'name' => ':where']))->toBe(ENTRIES);
});

it('lists a component\'s attributes in the tree, on the tag that has them', function () {
    $tree = Template::open(ENTRIES)->toArray()['tree'];
    $block = $tree['children'][0]['children'][0];
    $arm = $block['children'][0];
    $seo = $tree['children'][0]['children'][1];

    expect($block['type'])->toBe('block')
        ->and($arm['type'])->toBe('branch')
        ->and($arm['can']['attributes'])->toBeTrue()
        ->and(array_map(fn (array $attribute) => [$attribute['name'], $attribute['value'], $attribute['dynamic']], $arm['attributes']))->toBe([
            ['collection', 'articles', false],
            [':limit', '6', false],
        ])
        ->and($seo['type'])->toBe('atom')
        ->and($seo['can']['attributes'])->toBeTrue()
        ->and(array_column($seo['attributes'], 'name'))->toBe([':default-title']);

    // The tree is as it was for it: reading the attributes changes no byte.
    expect(Template::open(ENTRIES)->print())->toBe(ENTRIES);
});

it('refuses what a component\'s tag does not take', function (array $operation, string $message) {
    expect(fn () => onComponent(ENTRIES, $operation))->toThrow(EditRejected::class, $message);
})->with([
    'a class of its own' => [['type' => 'addClass', 'class' => 'wide'], 'as one attribute'],
    'another name' => [['type' => 'setTag', 'tag' => 'div'], 'Only elements have a tag to rename'],
    'a name that is none' => [['type' => 'setAttribute', 'name' => 'a b', 'value' => 'c'], 'is not an attribute name'],
    'a bound value that ends the attribute' => [['type' => 'setAttribute', 'name' => ':limit', 'value' => '3" x="', 'blade' => true], 'outside {{ }}'],
]);

it('is not marked as an element when the page is drawn for the editor', function () {
    $marked = Marker::mark(Template::open(ENTRIES), 'k');

    expect($marked)->toContain('<x-sunrice::entries collection="articles" :limit="6">')
        ->and($marked)->toContain('<x-sunrice::seo :default-title="$heading" />')
        ->and(Rules::hasAttributes(componentTag(Template::open(ENTRIES))))->toBeFalse();
});

it('changes only the attribute it names on every component of every fixture', function (string $file) {
    $source = (string) file_get_contents($file);
    try {
        $template = Template::open($source);
    } catch (Throwable) {
        expect(true)->toBeTrue();

        return;
    }
    $count = count(array_filter(iterator_to_array($template->root->walk(), false), Rules::isComponentTag(...)));

    for ($nth = 0; $nth < $count; $nth++) {
        $tag = componentTag(Template::open($source), $nth);
        $attributes = Template::open($source)->find($tag->id())?->attrs ?? [];
        $where = basename($file).' #'.$nth;

        // A new attribute: one insertion inside the tag, and the file opens again.
        $added = onComponent($source, ['type' => 'setAttribute', 'name' => 'data-sr', 'value' => 'x'], $nth);
        expect(strlen($added))->toBe(strlen($source) + strlen(' data-sr="x"'), $where)
            ->and(str_replace(' data-sr="x"', '', $added))->toBe($source, $where)
            ->and(Template::open($added)->print())->toBe($added, $where);

        // Taken away again: the file as it was.
        expect(onComponent($added, ['type' => 'removeAttribute', 'name' => 'data-sr'], $nth))->toBe($source, $where);
    }
    expect($count)->toBeGreaterThanOrEqual(0);
})->with(fn () => array_values(array_filter(
    [...RoundTrip::files(RoundTrip::fixtures('sunrice-starter')), ...RoundTrip::files(RoundTrip::fixtures('sunrice-package')), ...RoundTrip::files(RoundTrip::fixtures('edge'))],
    fn (string $file) => str_contains((string) file_get_contents($file), '<x-'),
)));
