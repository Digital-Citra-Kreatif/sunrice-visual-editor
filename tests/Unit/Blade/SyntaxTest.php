<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Operations;
use Sunrice\VisualEditor\Blade\ResultRejected;
use Sunrice\VisualEditor\Blade\Syntax;

/*
 * The syntax check with an application around it: Blade's compiler there
 * knows the application's directives and looks its components up.
 */

it('checks Blade for syntax errors, knowing the application\'s directives', function () {
    expect(Syntax::error('<p>{{ $entry->title }}</p> @if ($a) x @endif <body @bodyClass>'))->toBeNull()
        ->and(Syntax::error('@if ($a) <p>never closed</p>'))->toContain('of the compiled template')
        ->and(Syntax::error('{{ $a +* }}'))->toContain('syntax error')
        ->and(Syntax::error('<x-sunrice-cms::form handle="contact"></x-sunrice-cms::form>'))->toBeNull();
});

it('reports a component the application doesn\'t have, unless asked to leave components alone', function () {
    expect(Syntax::error('<x-sve-missing>Hi</x-sve-missing>'))->toContain('sve-missing')
        ->and(Syntax::error('<x-sve-missing>Hi</x-sve-missing>', components: false))->toBeNull()
        ->and(Syntax::error('<x-sve-missing>{{ $a +* }}</x-sve-missing>', components: false))->toContain('syntax error')
        // Left alone, the application's directives still count.
        ->and(Syntax::error('<body @bodyClass(\'dark\' +*)>', components: false))->toContain('syntax error');
});

it('refuses an operation that brings in a component the application doesn\'t have', function () {
    $source = '<main><p>Hi</p></main>';
    $paragraph = (string) strpos($source, '<p>');

    expect(fn () => Operations::apply($source, [['type' => 'insertAfter', 'node' => $paragraph, 'blade' => '<x-sve-missing />']]))
        ->toThrow(ResultRejected::class, 'sve-missing')
        ->and(Operations::apply($source, [['type' => 'insertAfter', 'node' => $paragraph, 'blade' => '<x-sunrice-cms::form handle="contact" />']]))
        ->toBe('<main><p>Hi</p><x-sunrice-cms::form handle="contact" /></main>');
});

it('still edits a template that names a missing component, as long as its PHP is sound', function () {
    $source = '<main><x-sve-missing>Old</x-sve-missing><p class="a">Hi</p></main>';
    $paragraph = (string) strpos($source, '<p');

    expect(Operations::apply($source, [['type' => 'addClass', 'node' => $paragraph, 'class' => 'b']]))
        ->toBe('<main><x-sve-missing>Old</x-sve-missing><p class="a b">Hi</p></main>')
        ->and(fn () => Operations::apply($source, [['type' => 'insertAfter', 'node' => $paragraph, 'blade' => '@if ($a +*) x @endif']]))
        ->toThrow(ResultRejected::class, 'syntax error');
});
