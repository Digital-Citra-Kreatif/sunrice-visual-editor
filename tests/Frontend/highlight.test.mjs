import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { highlight } from '../../resources/js/lib/highlight.ts';

/** [kind, text] pairs without the text between things, to read a line at a glance. */
const pieces = (source) => highlight(source).filter((token) => token.kind !== 'text' || token.text.trim() !== '').map((token) => [token.kind, token.text]);

const bladeFiles = (directory) =>
    readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);

        return statSync(path).isDirectory() ? bladeFiles(path) : name.endsWith('.blade.php') ? [path] : [];
    });

test('gives back every character of every fixture, in order', () => {
    const files = bladeFiles(new URL('../fixtures', import.meta.url).pathname);
    assert.ok(files.length > 40, `${files.length} fixtures`);
    for (const file of files) {
        const source = readFileSync(file, 'utf8');
        assert.equal(highlight(source).map((token) => token.text).join(''), source, file);
    }
});

test('tells a tag, its attributes and its values apart', () => {
    assert.deepEqual(pieces('<a class="button" href=\'/about\' download>About</a>'), [
        ['tag', '<a'],
        ['attribute', 'class'],
        ['text', '='],
        ['string', '"button"'],
        ['attribute', 'href'],
        ['text', '='],
        ['string', "'/about'"],
        ['attribute', 'download'],
        ['tag', '>'],
        ['text', 'About'],
        ['tag', '</a'],
        ['tag', '>'],
    ]);
    assert.deepEqual(pieces('<br/>'), [['tag', '<br'], ['tag', '/>']]);
});

test('picks out Blade: echoes, directives with their brackets, comments', () => {
    assert.deepEqual(pieces('{{ $entry->title }} {!! $body !!} {{-- a note --}}'), [
        ['echo', '{{'],
        ['php', ' $entry->title '],
        ['echo', '}}'],
        ['echo', '{!!'],
        ['php', ' $body '],
        ['echo', '!!}'],
        ['comment', '{{-- a note --}}'],
    ]);
    assert.deepEqual(pieces("@if (count($items) > 0 && $label !== ')') yes @else no @endif"), [
        ['directive', '@if'],
        ['php', " (count($items) > 0 && $label !== ')')"],
        ['text', ' yes '],
        ['directive', '@else'],
        ['text', ' no '],
        ['directive', '@endif'],
    ]);
    assert.deepEqual(pieces('<!-- html --> @include(\'sunrice.partials.card\', [\'entry\' => $entry])'), [
        ['comment', '<!-- html -->'],
        ['directive', '@include'],
        ['php', "('sunrice.partials.card', ['entry' => $entry])"],
    ]);
});

test('reads Blade inside a tag and inside a value', () => {
    assert.deepEqual(pieces('<a href="{{ $url }}#top" @class([\'on\' => $active]) {{ $attributes }}>'), [
        ['tag', '<a'],
        ['attribute', 'href'],
        ['text', '='],
        ['string', '"'],
        ['echo', '{{'],
        ['php', ' $url '],
        ['echo', '}}'],
        ['string', '#top"'],
        ['directive', '@class'],
        ['php', "(['on' => $active])"],
        ['echo', '{{'],
        ['php', ' $attributes '],
        ['echo', '}}'],
        ['tag', '>'],
    ]);
});

test('takes a block of PHP whole, and its one-line form by its brackets', () => {
    assert.deepEqual(pieces("@php\n    $a = '<b>';\n@endphp\n<p>x</p>"), [
        ['directive', '@php'],
        ['php', "\n    $a = '<b>';\n"],
        ['directive', '@endphp'],
        ['tag', '<p'],
        ['tag', '>'],
        ['text', 'x'],
        ['tag', '</p'],
        ['tag', '>'],
    ]);
    assert.deepEqual(pieces('@php($a = 1) <?php echo 1; ?>'), [
        ['directive', '@php'],
        ['php', '($a = 1)'],
        ['php', '<?php echo 1; ?>'],
    ]);
    assert.deepEqual(pieces('@verbatim {{ not Blade }} @endverbatim'), [
        ['directive', '@verbatim'],
        ['text', ' {{ not Blade }} '],
        ['directive', '@endverbatim'],
    ]);
});

test('leaves alone what only looks like Blade or a tag', () => {
    assert.deepEqual(pieces('mail joe@if.org, pay 5 < 6, @@if stays, a { brace'), [['text', 'mail joe@if.org, pay 5 < 6, @@if stays, a { brace']]);
    assert.deepEqual(highlight(''), []);
});

test('copes with what is not finished yet, as while typing', () => {
    for (const source of ['<div class="a', '<div class=', '{{ $a', '{{-- note', '<!-- note', '@if ($a', '@php $a = 1;', '<', '@', '{', '<a href="{{ $u']) {
        assert.equal(highlight(source).map((token) => token.text).join(''), source, source);
    }
});
