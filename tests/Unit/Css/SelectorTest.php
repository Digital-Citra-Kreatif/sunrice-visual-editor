<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Css\Selector;

it('writes the selector for a class as CSS wants it', function (string $class, string $selector) {
    expect(Selector::forClass($class))->toBe($selector);
})->with([
    ['card', '.card'],
    ['site-header', '.site-header'],
    ['menu-toggle__icon', '.menu-toggle__icon'],
    ['sm:flex', '.sm\:flex'],
    ['w-1/2', '.w-1\/2'],
    ['hover:bg-[#fff]', '.hover\:bg-\[\#fff\]'],
    ['10px', '.\31 0px'],
    ['-1', '.-\31 '],
    ['-', '.\-'],
    ['--wide', '.--wide'],
    ['größe', '.größe'],
    ['a.b', '.a\.b'],
]);

it('says whether a selector names a class', function (string $selector, bool $mentions) {
    expect(Selector::mentions($selector, 'card'))->toBe($mentions);
})->with([
    ['.card', true],
    ['.card:hover', true],
    ['.panel > .card', true],
    ['a.card.wide', true],
    ['.card::before', true],
    ['.card[data-open]', true],
    ['.card-title', false],
    ['.cards', false],
    ['.card_x', false],
    ['.postcard', false],
    ['.x\.card', false],
    ['.cardé', false],
    ['card', false],
]);

it('finds a class written with escapes', function () {
    expect(Selector::mentions('.sm\:flex > a', 'sm:flex'))->toBeTrue()
        ->and(Selector::mentions('.sm\:flex-col', 'sm:flex'))->toBeFalse();
});
