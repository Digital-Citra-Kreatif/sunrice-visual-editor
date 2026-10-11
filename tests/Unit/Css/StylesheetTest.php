<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Css\CssRejected;
use Sunrice\VisualEditor\Css\Declaration;
use Sunrice\VisualEditor\Css\Rule;
use Sunrice\VisualEditor\Css\Stylesheet;

/*
 * The stylesheet reader over the fixture stylesheets and small cases.
 * `php spike/css.php` runs the same kinds of change over Bootstrap, Bulma
 * and Pico too, and `node spike/css-browser.mjs` asks a browser whether it
 * reads the same rules and sees the same changes.
 */

function cssFixture(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/css/'.$name);
}

/** @return list<array{0: Rule, 1: Declaration}> */
function everyDeclaration(Stylesheet $sheet): array
{
    $all = [];
    foreach ($sheet->all() as $rule) {
        foreach ($rule->declarations as $declaration) {
            $all[] = [$rule, $declaration];
        }
    }

    return $all;
}

dataset('stylesheets', fn () => array_map(fn (string $path) => basename($path), glob(dirname(__DIR__, 2).'/fixtures/css/*.css') ?: []));

it('has the fixtures it expects', function () {
    expect(glob(dirname(__DIR__, 2).'/fixtures/css/*.css'))->toHaveCount(6);
});

// --- Reading ------------------------------------------------------------------

it('finds every rule and declaration where it says they are', function (string $name) {
    $source = cssFixture($name);
    $sheet = Stylesheet::read($source);

    expect($sheet->all())->not->toBeEmpty();
    foreach ($sheet->all() as $rule) {
        expect(substr($source, $rule->start, strlen($rule->prelude)))->toBe($rule->prelude);
        if ($rule->open !== null) {
            expect($source[$rule->open])->toBe('{');
            expect($rule->close === strlen($source) || $source[$rule->close] === '}')->toBeTrue();
        }
    }
    foreach (everyDeclaration($sheet) as [$rule, $declaration]) {
        expect(substr($source, $declaration->start, strlen($declaration->property)))->toBe($declaration->property)
            ->and(substr($source, $declaration->valueStart, $declaration->valueEnd - $declaration->valueStart))->toBe($declaration->value)
            ->and($declaration->start)->toBeGreaterThan($rule->open)
            ->and($declaration->end)->toBeLessThanOrEqual($rule->close);
    }
})->with('stylesheets');

it('reads the same rules from a stylesheet and from it minified', function () {
    $says = fn (string $name) => array_map(
        // The selectors are written another way there (`::before` as `:before`): the declarations say which rule is which.
        fn (Rule $rule) => [$rule->isAt(), count($rule->within()), array_map(fn (Declaration $each) => $each->property, $rule->declarations)],
        Stylesheet::read(cssFixture($name))->all(),
    );

    expect($says('starter.min.css'))->toBe($says('starter.css'));
});

it('reads rules with and without a block, and what at-rules they are', function () {
    $sheet = Stylesheet::read(cssFixture('edge.css'));
    $top = array_map(fn (Rule $rule) => $rule->prelude, $sheet->rules);

    expect(array_slice($top, 0, 5))->toBe(['@charset "UTF-8"', '@import url("fonts.css") screen and (min-width: 40em)', "@import 'print.css' print", '@layer reset, base, components', ':root'])
        ->and($sheet->rules[0]->open)->toBeNull()
        ->and($sheet->rules[0]->isAt())->toBeTrue()
        ->and($sheet->rules[0]->at())->toBe('charset')
        ->and($sheet->rules[4]->isAt())->toBeFalse()
        ->and($sheet->rules[4]->at())->toBe('');
});

it('is not misled by what text, comments and addresses hold', function (string $selector, array $declarations) {
    $rule = Stylesheet::read(cssFixture('edge.css'))->styling($selector)[0];

    expect(array_map(fn (Declaration $each) => [$each->property, $each->value], $rule->declarations))->toBe($declarations);
})->with([
    'a brace in a text' => ['.quote::after', [['content', "'}'"], ['color', 'red']]],
    'a semicolon in a text' => ['.semi::before', [['content', '";"'], ['margin', '0']]],
    'an escaped quote' => ['.escaped::before', [['content', '"say \"hi\"; ok"'], ['padding', '0']]],
    'a selector with text in it' => ['a[title="a;b{c}"]', [['text-decoration', 'underline']]],
    'an address without quotes' => ['.icon', [['background', 'url(data:image/svg+xml;charset=utf8,%3Csvg%20xmlns=%27http://www.w3.org/2000/svg%27%3E%3C/svg%3E) no-repeat'], ['width', '16px']]],
    'an address with brackets in it' => ['.photo', [['background-image', 'url("photo (1).jpg")'], ['background-size', 'cover']]],
    'comments around a value' => ['.comments', [['color', '/* inside */ red'], ['margin', '0 /* at the end */']]],
    'old hacks' => ['.hacks', [['*zoom', '1'], ['_height', '1px'], ['filter', "progid:DXImageTransform.Microsoft.gradient(startColorstr='#80000000', endColorstr='#80000000')"]]],
    'brackets in brackets' => ['.calc', [['width', 'calc(100% - (2 * var(--gap, 1rem)))'], ['margin', 'max(1rem, min(5vw, 3rem))']]],
    'an escaped selector' => ['.w-1\/2', [['width', '50%']]],
    'a custom property with braces' => [':root', [['--accent', '#2563eb'], ['--empty', ''], ['--json', '{ "a": [1, 2], "b": "x;y}" }'], ['--font-stack', '"Helvetica Neue", Arial, sans-serif'], ['--shadow', '0 1px 2px rgb(0 0 0 / 10%), 0 8px 24px rgb(0 0 0 / 15%)']]],
]);

it('says whether a declaration is important', function () {
    $rule = Stylesheet::read('.a { color: red !important; margin: 0!important; padding: 0 ! IMPORTANT; top: 0 }')->rules[0];

    expect(array_map(fn (Declaration $each) => $each->important(), $rule->declarations))->toBe([true, true, true, false]);
});

it('finds the rules that style a selector, under any at-rule', function () {
    $found = Stylesheet::read(cssFixture('edge.css'))->styling(' .card ');

    expect(array_map(fn (Rule $rule) => $rule->within(), $found))->toBe([
        ['@media (min-width: 40em) and (max-width: 60em), print'],
        ['@media (min-width: 40em) and (max-width: 60em), print', '@supports (display: grid)'],
        ['@supports not (display: grid)'],
        ['@container sidebar (min-width: 20em)'],
        ['@layer components'],
        [],
    ])->and($found[5]->selectors())->toBe(['.card', '.tile', '.panel > .card']);
});

it('splits selectors at their commas only', function () {
    $selectors = fn (string $prelude) => Stylesheet::read($prelude.' { color: red }')->rules[0]->selectors();

    expect($selectors('.a:is(.b, .c),  .d > .e'))->toBe(['.a:is(.b, .c)', '.d > .e'])
        ->and($selectors('a[title="x, y"], b'))->toBe(['a[title="x, y"]', 'b'])
        ->and($selectors(".a /* one, two */,\n.b"))->toBe(['.a', '.b'])
        ->and($selectors('.a\,b'))->toBe(['.a\,b'])
        ->and($selectors('@media (min-width: 1px), print'))->toBe([]);
});

it('reads rules nested in rules', function () {
    $all = Stylesheet::read(cssFixture('nested.css'))->all();

    expect(array_map(fn (Rule $rule) => [$rule->prelude, $rule->within(), count($rule->declarations)], array_slice($all, 0, 7)))->toBe([
        ['.card', [], 3],
        ['&:hover', ['.card'], 1],
        ['& > .title', ['.card'], 1],
        ['a:hover', ['.card', '& > .title'], 1],
        ['.dark &', ['.card'], 1],
        ['@media (min-width: 40em)', ['.card'], 1],
        ['& .title', ['.card', '@media (min-width: 40em)'], 1],
    ]);
});

it('takes the last declaration of a property for the one that counts', function () {
    $rule = Stylesheet::read('.a { color: red; Color: blue; margin: 0 }')->rules[0];

    expect($rule->declaration('color')?->value)->toBe('blue')
        ->and($rule->declaration('padding'))->toBeNull();
});

it('reads a file that starts with a byte order mark', function () {
    $sheet = Stylesheet::read(cssFixture('bom-crlf.css'));

    expect($sheet->rules[0]->prelude)->toBe('.bom')
        ->and($sheet->rules[0]->start)->toBe(3);
});

it('reads a file whose blocks are never closed', function () {
    $source = cssFixture('unclosed.css');
    $all = Stylesheet::read($source)->all();

    expect(array_map(fn (Rule $rule) => $rule->prelude, $all))->toBe(['.open', '.inner', '.last'])
        ->and($all[2]->close)->toBe(strlen($source));
});

// --- Changes ------------------------------------------------------------------

it('sets a value and changes no other byte', function (string $name) {
    $source = cssFixture($name);
    $sheet = Stylesheet::read($source);

    foreach (everyDeclaration($sheet) as [, $declaration]) {
        expect($sheet->withValue($declaration, ' inherit '))->toBe(substr($source, 0, $declaration->valueStart).'inherit'.substr($source, $declaration->valueEnd));
    }
})->with('stylesheets');

it('takes a declaration out and leaves what is around it', function (string $name) {
    $sheet = Stylesheet::read(cssFixture($name));

    foreach (everyDeclaration($sheet) as [$rule, $declaration]) {
        $after = Stylesheet::read($sheet->without($declaration));
        $index = array_search($rule, $sheet->all(), true);

        expect(count($after->all()))->toBe(count($sheet->all()))
            ->and(count($after->all()[$index]->declarations))->toBe(count($rule->declarations) - 1);
    }
})->with('stylesheets');

it('takes a declaration out with its line when the line was its own', function (string $source, int $which, string $expected) {
    $sheet = Stylesheet::read($source);

    expect($sheet->without($sheet->all()[count($sheet->all()) - 1]->declarations[$which]))->toBe($expected);
})->with([
    'its own line' => [".card {\n    padding: 1rem;\n    color: red;\n}\n", 0, ".card {\n    color: red;\n}\n"],
    'the last line' => [".card {\n    padding: 1rem;\n    color: red;\n}\n", 1, ".card {\n    padding: 1rem;\n}\n"],
    'lines ended the Windows way' => [".card {\r\n    padding: 1rem;\r\n    color: red;\r\n}\r\n", 0, ".card {\r\n    color: red;\r\n}\r\n"],
    'on a line with others' => ['.a { color: red; margin: 0; }', 0, '.a { margin: 0; }'],
    'the last on a line with others' => ['.a { color: red; margin: 0 }', 1, '.a { color: red; }'],
    'minified' => ['.a{color:red;margin:0}', 0, '.a{margin:0}'],
    'the only one' => ['.a { color: red }', 0, '.a { }'],
    'between comments' => ['.a { /* c */ color: red; /* d */ }', 0, '.a { /* c */ /* d */ }'],
    'in a rule in a rule' => ["@media print {\n    .a {\n        color: red;\n        margin: 0;\n    }\n}\n", 1, "@media print {\n    .a {\n        color: red;\n    }\n}\n"],
]);

it('adds a declaration written as the ones before it', function (string $source, string $expected) {
    $sheet = Stylesheet::read($source);

    expect($sheet->withDeclaration($sheet->all()[count($sheet->all()) - 1], ' margin ', ' 0 '))->toBe($expected);
})->with([
    'one to a line' => [".card {\n    padding: 1rem;\n    color: red;\n}\n", ".card {\n    padding: 1rem;\n    color: red;\n    margin: 0;\n}\n"],
    'indented with a tab' => [".a {\n\tcolor: red;\n}", ".a {\n\tcolor: red;\n\tmargin: 0;\n}"],
    'lines ended the Windows way' => [".a {\r\n    color: red;\r\n}\r\n", ".a {\r\n    color: red;\r\n    margin: 0;\r\n}\r\n"],
    'on one line' => ['.a { color: red; }', '.a { color: red; margin: 0; }'],
    'after one that has no semicolon' => ['.a { color: red }', '.a { color: red; margin: 0 }'],
    'after one that has none, on its own line' => [".a {\n  color: red\n}", ".a {\n  color: red;\n  margin: 0\n}"],
    'minified' => ['.a{color:red}', '.a{color:red;margin:0}'],
    'minified, with a semicolon' => ['.a{color:red;}', '.a{color:red;margin:0;}'],
    'spaced out' => ['.a { color : red ; top : 0 ; }', '.a { color : red ; top : 0 ; margin : 0; }'],
    'into an empty block' => ['.a {}', '.a { margin: 0; }'],
    'into a blank block' => ['.a { }', '.a { margin: 0; }'],
    'into an empty block over lines' => [".a {\n}\n", ".a {\n    margin: 0;\n}\n"],
    'into an empty block over Windows lines' => ["  .a {\r\n  }\r\n", "  .a {\r\n      margin: 0;\r\n  }\r\n"],
    'before a comment that ends the block' => ['.a { /* c */ color: red; /* d */ }', '.a { /* c */ color: red; margin: 0; /* d */ }'],
    'in a rule in a rule' => ["@media print {\n    .a {\n        color: red;\n    }\n}\n", "@media print {\n    .a {\n        color: red;\n        margin: 0;\n    }\n}\n"],
]);

it('adds a declaration after the last one, whatever stands between them', function (string $source, string $expected) {
    $sheet = Stylesheet::read($source);

    expect($sheet->withDeclaration($sheet->rules[0], 'margin', '0'))->toBe($expected);
})->with([
    'a rule nested in it' => [".a {\n    color: red;\n    &:hover { color: blue; }\n    top: 0;\n}\n", ".a {\n    color: red;\n    &:hover { color: blue; }\n    top: 0;\n    margin: 0;\n}\n"],
    'a rule nested after it' => [".a {\n    color: red;\n    &:hover { color: blue; }\n}\n", ".a {\n    color: red;\n    margin: 0;\n    &:hover { color: blue; }\n}\n"],
    'a comment on a line of its own' => [".a {\n    color: red;\n    /* then */\n    top: 0;\n}\n", ".a {\n    color: red;\n    /* then */\n    top: 0;\n    margin: 0;\n}\n"],
    'only rules nested in it' => [".a {\n    &:hover { color: blue; }\n}\n", ".a {\n    margin: 0;\n    &:hover { color: blue; }\n}\n"],
]);

it('adds a declaration to every rule of a stylesheet, inside its block', function (string $name) {
    $source = cssFixture($name);
    $sheet = Stylesheet::read($source);

    foreach ($sheet->all() as $index => $rule) {
        if ($rule->open === null) {
            continue;
        }
        $after = Stylesheet::read($sheet->withDeclaration($rule, '--probe', '1'))->all();

        expect(count($after))->toBe(count($sheet->all()))
            ->and($after[$index]->declaration('--probe')?->value)->toBe('1')
            ->and(count($after[$index]->declarations))->toBe(count($rule->declarations) + 1);
    }
})->with('stylesheets');

it('adds a rule at the end of the file', function (string $source, string $expected) {
    expect(Stylesheet::read($source)->withRule("  .tile >\n .title ", ['color' => 'red', ' margin ' => ' 0 ']))->toBe($expected);
})->with([
    'an empty file' => ['', ".tile > .title {\n    color: red;\n    margin: 0;\n}\n"],
    'a file that ends its last line' => [".a { color: red; }\n", ".a { color: red; }\n\n.tile > .title {\n    color: red;\n    margin: 0;\n}\n"],
    'a file that does not' => ['.a { color: red; }', ".a { color: red; }\n\n.tile > .title {\n    color: red;\n    margin: 0;\n}\n"],
    'a file with an empty line at its end' => [".a { color: red; }\n\n", ".a { color: red; }\n\n.tile > .title {\n    color: red;\n    margin: 0;\n}\n"],
    'Windows lines' => [".a { color: red; }\r\n", ".a { color: red; }\r\n\r\n.tile > .title {\r\n    color: red;\r\n    margin: 0;\r\n}\r\n"],
]);

it('adds a rule inside a new @media block at the end of the file', function () {
    expect(Stylesheet::read(".a { color: red; }\n")->withRule('.new', [['color', 'blue']], " @media  (max-width:\n 760px) "))
        ->toBe(".a { color: red; }\n\n@media (max-width: 760px) {\n    .new {\n        color: blue;\n    }\n}\n")
        ->and(Stylesheet::read('')->withRule('.new', [], '@media print'))->toBe("@media print {\n    .new {\n    }\n}\n")
        ->and(Stylesheet::read(".a {}\r\n")->withRule('.new', ['top' => '0'], '@media print'))->toBe(".a {}\r\n\r\n@media print {\r\n    .new {\r\n        top: 0;\r\n    }\r\n}\r\n");
});

it('refuses a @media block that is none', function (string $media) {
    expect(fn () => Stylesheet::read('.a { color: red; }')->withRule('.new', [], $media))->toThrow(CssRejected::class, 'does not start a @media block');
})->with(['@media', 'print', '@supports (display: grid)', '@import "x.css"', '@media print { } .x', '@media print; .x', '@media (a) /* c */', '']);

it('adds a rule at the end of a block, written as what is in it', function (string $source, string $expected) {
    $sheet = Stylesheet::read($source);
    $block = array_values(array_filter($sheet->all(), fn (Rule $rule) => $rule->isAt()))[0];

    expect($sheet->withRuleIn($block, ' .new ', [['color', 'blue'], ['margin', '0']]))->toBe($expected);
})->with([
    'a rule to a line' => ["@media (max-width: 40em) {\n    .a { color: red; }\n    .b {\n        margin: 0;\n    }\n}\n.z { top: 0 }\n", "@media (max-width: 40em) {\n    .a { color: red; }\n    .b {\n        margin: 0;\n    }\n    .new {\n        color: blue;\n        margin: 0;\n    }\n}\n.z { top: 0 }\n"],
    'indented with tabs' => ["@media print {\n\t.a { color: red; }\n}\n", "@media print {\n\t.a { color: red; }\n\t.new {\n\t\tcolor: blue;\n\t\tmargin: 0;\n\t}\n}\n"],
    'Windows lines' => ["@media print {\r\n    .a { color: red; }\r\n}\r\n", "@media print {\r\n    .a { color: red; }\r\n    .new {\r\n        color: blue;\r\n        margin: 0;\r\n    }\r\n}\r\n"],
    'an empty block over lines' => ["@media print {\n}\n", "@media print {\n    .new {\n        color: blue;\n        margin: 0;\n    }\n}\n"],
    'an empty block' => ['@media print {}', '@media print { .new { color: blue; margin: 0; } }'],
    'on one line' => ["@media print { .a { color: red } }\n.z{}", "@media print { .a { color: red } .new { color: blue; margin: 0; } }\n.z{}"],
    'minified' => ['@media print{.a{color:red}}.z{top:0}', '@media print{.a{color:red}.new{color:blue;margin:0}}.z{top:0}'],
    'its end on the line of its last rule' => ["@media print {\n  .a { color: red; } }\n", "@media print {\n  .a { color: red; } \n  .new {\n      color: blue;\n      margin: 0;\n  }\n}\n"],
    'a block nested in a rule' => [".card {\n    color: red;\n    @media print {\n        color: blue;\n    }\n}\n", ".card {\n    color: red;\n    @media print {\n        color: blue;\n        .new {\n            color: blue;\n            margin: 0;\n        }\n    }\n}\n"],
]);

it('adds a rule to every block of a stylesheet, and only there', function (string $name) {
    $sheet = Stylesheet::read(cssFixture($name));
    $all = $sheet->all();
    expect($all)->not->toBeEmpty();

    foreach ($all as $index => $block) {
        if ($block->open === null || ! $block->isAt() || $block->close >= strlen($sheet->source)) {
            continue;
        }
        $after = Stylesheet::read($sheet->withRuleIn($block, '.probe', [['--probe', '1']]))->all();
        $inside = count(iterator_to_array($block->walk(), false));

        expect(count($after))->toBe(count($all) + 1)
            ->and($after[$index + $inside]->prelude)->toBe('.probe')
            ->and($after[$index + $inside]->parent?->prelude)->toBe($block->prelude)
            ->and(array_map(fn (Rule $rule) => $rule->prelude, [...array_slice($after, 0, $index + $inside), ...array_slice($after, $index + $inside + 1)]))->toBe(array_map(fn (Rule $rule) => $rule->prelude, $all));
    }
})->with('stylesheets');

it('refuses a rule for what is no block, or what would not stay in it', function () {
    $sheet = Stylesheet::read("@import 'a.css';\n@media print {\n    .a { color: red; }\n}\n");
    $open = Stylesheet::read("@media print {\n    .a { color: red; }\n");

    expect(fn () => $sheet->withRuleIn($sheet->rules[0], '.new'))->toThrow(CssRejected::class, 'no block')
        ->and(fn () => $open->withRuleIn($open->rules[0], '.new'))->toThrow(CssRejected::class, 'no block')
        ->and(fn () => $sheet->withRuleIn($sheet->rules[1], '.new } .x'))->toThrow(CssRejected::class, 'not a selector')
        ->and(fn () => $sheet->withRuleIn($sheet->rules[1], '.new', [['color', 'red; } } body { display: none']]))->toThrow(CssRejected::class, 'not one value')
        ->and(fn () => $sheet->withRuleIn($sheet->rules[1], '.new', [['co lor', 'red']]))->toThrow(CssRejected::class, 'not the name of a property');
});

it('keeps the byte order mark and the line endings of a file it changes', function () {
    $source = cssFixture('bom-crlf.css');
    $sheet = Stylesheet::read($source);
    $changed = $sheet->withDeclaration($sheet->rules[1], 'color', 'red');

    expect($changed)->toStartWith("\xEF\xBB\xBF.bom")
        ->and($changed)->toContain("    padding: 0;\r\n    color: red;\r\n}\r\n")
        ->and(str_replace("\r\n", '', $changed))->not->toContain("\n");
});

// --- Refusals -----------------------------------------------------------------

it('refuses a value that is not one value', function (string $value) {
    $sheet = Stylesheet::read(".a { color: red; }\n.b { margin: 0 }");

    expect(fn () => $sheet->withValue($sheet->rules[0]->declarations[0], $value))->toThrow(CssRejected::class)
        ->and(fn () => $sheet->withDeclaration($sheet->rules[0], 'top', $value))->toThrow(CssRejected::class)
        ->and(fn () => $sheet->withRule('.c', ['top' => $value]))->toThrow(CssRejected::class);
})->with([
    'ends the declaration' => ['red; margin: 0'],
    'ends the rule and starts another' => ['red; } body { display: none'],
    'ends the rule' => ['red }'],
    'opens a block' => ['a { b }'],
    'an unclosed text' => ['"unclosed'],
    'an unclosed comment' => ['red /* unclosed'],
    'an unclosed bracket' => ['rgb(0 0 0'],
    'an unclosed address' => ['url('],
    'a semicolon at its end' => ['red;'],
    'nothing' => ['   '],
]);

it('accepts values that only look as if they broke out', function (string $value) {
    $sheet = Stylesheet::read('.a { color: red; }');

    expect($sheet->withValue($sheet->rules[0]->declarations[0], $value))->toBe(".a { color: {$value}; }");
})->with([
    ['"a;b}"'],
    ['url(data:image/png;base64,AAAA)'],
    ['calc(1px + (2 * 3px))'],
    ['red !important'],
    ['var(--x, { })'],
    ["'it\\'s'"],
]);

it('lets a custom property hold braces, in pairs, and be empty', function () {
    $sheet = Stylesheet::read(':root { --a: 1; color: red; }');
    $custom = $sheet->rules[0]->declarations[0];

    expect($sheet->withValue($custom, '{ "a": 1; "b": 2 }'))->toBe(':root { --a: { "a": 1; "b": 2 }; color: red; }')
        ->and($sheet->withValue($custom, ''))->toBe(':root { --a: ; color: red; }')
        ->and(fn () => $sheet->withValue($custom, '{ unclosed'))->toThrow(CssRejected::class)
        ->and(fn () => $sheet->withValue($sheet->rules[0]->declarations[1], '{ }'))->toThrow(CssRejected::class);
});

it('refuses a property that is not the name of one', function (string $property) {
    $sheet = Stylesheet::read('.a { color: red; }');

    expect(fn () => $sheet->withDeclaration($sheet->rules[0], $property, 'red'))->toThrow(CssRejected::class)
        ->and(fn () => $sheet->withRule('.b', [$property => 'red']))->toThrow(CssRejected::class);
})->with(['', 'co lor', 'color: red; margin', 'color}', '1color', '/* c */color']);

it('refuses a declaration for a rule that has no block', function () {
    $sheet = Stylesheet::read('@import "a.css";');

    expect(fn () => $sheet->withDeclaration($sheet->rules[0], 'color', 'red'))->toThrow(CssRejected::class, 'no block');
});

it('refuses a new rule whose selector is not one', function (string $selector) {
    expect(fn () => Stylesheet::read('.a { color: red; }')->withRule($selector, ['color' => 'red']))->toThrow(CssRejected::class);
})->with(['', '  ', '.a { color: blue } .b', '.a; .b', '.a } .b', '.a /* c */', '@media print']);

it('refuses a new rule in a file whose last block is not closed', function () {
    expect(fn () => Stylesheet::read(cssFixture('unclosed.css'))->withRule('.new', ['color' => 'red']))->toThrow(CssRejected::class, 'may not be closed');
});

it('refuses a value a file that is not closed would let out', function () {
    // The file's own end would close the comment for the reader: the value has to be whole on its own.
    $sheet = Stylesheet::read('.a { color: red');

    expect(fn () => $sheet->withValue($sheet->rules[0]->declarations[0], 'red /* open'))->toThrow(CssRejected::class)
        ->and($sheet->withValue($sheet->rules[0]->declarations[0], 'blue'))->toBe('.a { color: blue');
});
