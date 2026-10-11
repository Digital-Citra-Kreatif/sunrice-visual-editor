<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Css\Changes;
use Sunrice\VisualEditor\Css\CssRejected;
use Sunrice\VisualEditor\Css\Stylesheet;

const SVE_SHEET = ".card {\n    padding: 1rem;\n    color: red;\n}\n\n.tile { margin: 0 }\n\n@media (min-width: 40em) {\n    .card {\n        padding: 2rem;\n    }\n}\n";

/** Where a declaration starts: the first of that property in the n-th rule that has the selector. */
function cssPlace(string $source, string $selector, ?string $property = null, int $nth = 0): int
{
    $rule = Stylesheet::read($source)->styling($selector)[$nth];

    return $property === null ? $rule->start : (int) $rule->declaration($property)?->start;
}

it('makes each kind of change, and only it', function (array $change, string $expected) {
    foreach (['declaration', 'rule'] as $key) {
        if (isset($change[$key])) {
            $change[$key] = cssPlace(SVE_SHEET, ...$change[$key]);
        }
    }

    expect(Changes::run(SVE_SHEET, [$change]))->toBe($expected);
})->with([
    'a value set' => [['type' => 'set', 'declaration' => ['.card', 'color'], 'value' => 'blue'], str_replace('color: red', 'color: blue', SVE_SHEET)],
    'a value set under @media' => [['type' => 'set', 'declaration' => ['.card', 'padding', 1], 'value' => '3rem'], str_replace('2rem', '3rem', SVE_SHEET)],
    'a property renamed' => [['type' => 'rename', 'declaration' => ['.card', 'padding'], 'property' => 'padding-block'], str_replace('padding: 1rem', 'padding-block: 1rem', SVE_SHEET)],
    'a declaration taken out' => [['type' => 'remove', 'declaration' => ['.card', 'padding']], str_replace("    padding: 1rem;\n", '', SVE_SHEET)],
    'a declaration added' => [['type' => 'add', 'rule' => ['.card'], 'property' => 'margin', 'value' => '0'], str_replace("color: red;\n", "color: red;\n    margin: 0;\n", SVE_SHEET)],
    'one added after one without a semicolon' => [['type' => 'add', 'rule' => ['.tile'], 'property' => 'color', 'value' => 'red'], str_replace('margin: 0 }', 'margin: 0; color: red }', SVE_SHEET)],
    'a rule added' => [['type' => 'rule', 'selector' => '.new', 'declarations' => [['color', 'red'], ['color', 'rgb(1 2 3)']]], SVE_SHEET."\n.new {\n    color: red;\n    color: rgb(1 2 3);\n}\n"],
    'an empty rule added' => [['type' => 'rule', 'selector' => '.new'], SVE_SHEET."\n.new {\n}\n"],
]);

it('makes a list of changes that all name places of the same file, in whatever order they come', function (array $order) {
    $changes = [
        ['type' => 'set', 'declaration' => cssPlace(SVE_SHEET, '.card', 'padding'), 'value' => '4px'],
        ['type' => 'rename', 'declaration' => cssPlace(SVE_SHEET, '.card', 'padding'), 'property' => 'padding-inline'],
        ['type' => 'remove', 'declaration' => cssPlace(SVE_SHEET, '.card', 'color')],
        ['type' => 'add', 'rule' => cssPlace(SVE_SHEET, '.card'), 'property' => 'margin', 'value' => '0'],
        ['type' => 'add', 'rule' => cssPlace(SVE_SHEET, '.card'), 'property' => 'top', 'value' => '1px'],
        ['type' => 'set', 'declaration' => cssPlace(SVE_SHEET, '.tile', 'margin'), 'value' => 'auto'],
        ['type' => 'set', 'declaration' => cssPlace(SVE_SHEET, '.card', 'padding', 1), 'value' => '8px'],
        ['type' => 'rule', 'selector' => '.new', 'declarations' => [['color', 'red']]],
    ];

    expect(Changes::run(SVE_SHEET, array_map(fn (int $index) => $changes[$index], $order)))
        ->toBe(".card {\n    padding-inline: 4px;\n    margin: 0;\n    top: 1px;\n}\n\n.tile { margin: auto }\n\n@media (min-width: 40em) {\n    .card {\n        padding: 8px;\n    }\n}\n\n.new {\n    color: red;\n}\n");
})->with([
    'as written' => [[0, 1, 2, 3, 4, 5, 6, 7]],
    'from the end' => [[7, 6, 5, 3, 4, 2, 1, 0]],
    'mixed' => [[5, 3, 0, 7, 2, 4, 6, 1]],
]);

it('adds declarations to one rule in the order they are listed', function () {
    $rule = cssPlace(SVE_SHEET, '.tile');

    expect(Changes::run(SVE_SHEET, [
        ['type' => 'add', 'rule' => $rule, 'property' => 'a', 'value' => '1'],
        ['type' => 'add', 'rule' => $rule, 'property' => 'b', 'value' => '2'],
        ['type' => 'add', 'rule' => $rule, 'property' => 'c', 'value' => '3'],
    ]))->toContain('.tile { margin: 0; a: 1; b: 2; c: 3 }');
});

it('changes rules nested in the rule it adds to', function () {
    $source = ".a {\n    color: red;\n    &:hover { color: blue; }\n}\n";
    $sheet = Stylesheet::read($source);

    expect(Changes::run($source, [
        ['type' => 'add', 'rule' => $sheet->rules[0]->start, 'property' => 'margin', 'value' => '0'],
        ['type' => 'set', 'declaration' => $sheet->rules[0]->rules[0]->declarations[0]->start, 'value' => 'green'],
    ]))->toBe(".a {\n    color: red;\n    margin: 0;\n    &:hover { color: green; }\n}\n");
});

it('refuses a list as a whole, and says which change it was', function (array $changes, int $which, string $why) {
    foreach ($changes as &$change) {
        foreach (['declaration', 'rule'] as $key) {
            if (is_array($change[$key] ?? null)) {
                $change[$key] = cssPlace(SVE_SHEET, ...$change[$key]);
            }
        }
    }

    try {
        Changes::run(SVE_SHEET, $changes);
    } catch (CssRejected $e) {
        expect($e->change)->toBe($which)
            ->and($e->getMessage())->toContain($why);

        return;
    }
    $this->fail('The changes were accepted.');
})->with([
    'a value that breaks out' => [[['type' => 'set', 'declaration' => ['.card', 'color'], 'value' => 'blue'], ['type' => 'set', 'declaration' => ['.card', 'padding'], 'value' => '0; } body { display: none']], 1, 'not one value'],
    'no declaration there' => [[['type' => 'set', 'declaration' => 3, 'value' => 'blue']], 0, 'no declaration there'],
    'no rule there' => [[['type' => 'add', 'rule' => 3, 'property' => 'color', 'value' => 'blue']], 0, 'no rule there'],
    'a place that is not a number' => [[['type' => 'remove', 'declaration' => '21']], 0, 'no declaration there'],
    'an unknown kind' => [[['type' => 'set', 'declaration' => ['.card', 'color'], 'value' => 'blue'], ['type' => 'explode']], 1, 'not a kind of change'],
    'no kind' => [[['declaration' => 21]], 0, 'not a kind of change'],
    'not an object' => [['set'], 0, 'has to be an object'],
    'a value that is no text' => [[['type' => 'set', 'declaration' => ['.card', 'color'], 'value' => ['red']]], 0, 'has to say its "value"'],
    'no property' => [[['type' => 'add', 'rule' => ['.card'], 'value' => 'red']], 0, 'has to say its "property"'],
    'a property that is none' => [[['type' => 'rename', 'declaration' => ['.card', 'color'], 'property' => 'co lor']], 0, 'not the name of a property'],
    'a value far too long' => [[['type' => 'set', 'declaration' => ['.card', 'color'], 'value' => str_repeat('a', 10_001)]], 0, 'too long'],
    'one declaration taken out and changed' => [[['type' => 'remove', 'declaration' => ['.card', 'color']], ['type' => 'rename', 'declaration' => ['.card', 'color'], 'property' => 'background']], 1, 'no declaration there'],
    'a selector that is none' => [[['type' => 'rule', 'selector' => '.a { } .b']], 0, 'not a selector'],
    'declarations that are no pairs' => [[['type' => 'rule', 'selector' => '.a', 'declarations' => [['color' => 'red']]]], 0, 'pairs'],
    'declarations of three' => [[['type' => 'rule', 'selector' => '.a', 'declarations' => [['color', 'red', 'x']]]], 0, 'pairs'],
]);

it('keeps a value a custom property may hold out of a property that may not', function () {
    $source = ':root { --theme: { a: b }; }';
    $at = Stylesheet::read($source)->rules[0]->declarations[0]->start;

    expect(fn () => Changes::run($source, [['type' => 'rename', 'declaration' => $at, 'property' => 'color']]))->toThrow(CssRejected::class)
        ->and(Changes::run($source, [['type' => 'rename', 'declaration' => $at, 'property' => '--other']]))->toBe(':root { --other: { a: b }; }');
});
