<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Syntax;
use Sunrice\VisualEditor\Guard\Compiled;
use Sunrice\VisualEditor\Guard\Literal;

it('reads the PHP a template runs from what Blade compiles it to', function () {
    $islands = array_keys(Compiled::islands("<h1 class=\"{{ \$a }}\">{{ \$b }}</h1>\n@if (\$c)\n    {!! \$d !!}\n@endif\n@php(\$e = 1)\n<?php \$f = 2; ?>\n<?= \$g ?>\n{{-- note --}}\n@verbatim {{ \$not }} @endverbatim"));

    expect($islands)->toBe([
        'echo e ( $a ) ;',
        'echo e ( $b ) ;',
        'if ( $c ) :',
        'echo $d ;',
        'endif ;',
        '( $e = 1 ) ;',
        '$f = 2 ;',
        'echo $g',
    ]);
});

it('counts each piece once, and spaces and comments for nothing', function () {
    expect(Compiled::islands("{{ \$a }} {{\$a}} {{  \$a  }}\n<?php /* why */ echo  e(\$a) ; // so ?>"))->toHaveCount(1)
        ->and(Compiled::islands('<p>Text, an @ sign, {curly} brackets and a <?xml version="1.0"?> that is none.</p>'))->toBe([]);
});

it('tells code apart that only spaces tell apart', function () {
    expect(array_keys(Compiled::islands('{{ $a . $b }}')))->not->toBe(array_keys(Compiled::islands('{{ $a.b }}')))
        ->and(Compiled::islands("{{ 'a b' }}"))->not->toBe(Compiled::islands("{{ 'ab' }}"));
});

it('reads what a component is given as code of the component', function () {
    $plain = Compiled::islands('<x-sunrice-cms::entries collection="articles" :limit="3" />');
    $code = Compiled::islands('<x-sunrice-cms::entries collection="articles" :limit="system(1)" />');

    expect($plain)->not->toBe([])
        ->and(array_diff_key($code, $plain))->not->toBe([])
        ->and(implode(' ', array_keys(array_diff_key($code, $plain))))->toContain('system ( 1 )');
});

it('does not count what Blade makes up anew each time it compiles', function () {
    $once = "@once\n<script></script>\n@endonce";

    expect(Compiled::islands($once))->toBe(Compiled::islands($once))->not->toBe([]);
});

it('counts the PHP tags Blade leaves in the text when it pairs a @php(…) with an @endphp further down', function () {
    $paired = "@php(\$a = 1)\n<p>{{ \$a }}</p>\n@php\n    \$b = 2;\n@endphp";

    expect(Compiled::strayTags("@php(\$a = 1)\n<p>{{ \$a }}</p>"))->toBe(0)
        ->and(Compiled::strayTags("@php\n    \$b = 2;\n@endphp\n@php(\$a = 1)"))->toBe(0)
        ->and(Compiled::strayTags($paired))->toBeGreaterThan(0)
        // What was code is text now: the page would show it.
        ->and(Compiled::islands($paired))->not->toHaveKey('( $a = 1 ) ;')
        // And that is no way to leave a template, whoever writes it.
        ->and(Syntax::brokenBy("@php(\$a = 1)\n<p>{{ \$a }}</p>", $paired))->toContain('@php')
        ->and(Syntax::brokenBy($paired, $paired.'<p>more</p>'))->toBeNull();
});

it('takes a value written out for one, and nothing else', function (string $php, bool $scalar, bool $withLists) {
    expect(Literal::isScalar($php))->toBe($scalar)
        ->and(Literal::is($php))->toBe($withLists);
})->with([
    ['10', true, true],
    ['-2.5', true, true],
    ["'a text'", true, true],
    ["'it\\'s; } ) ('", true, true],
    ['true', true, true],
    ['NULL', true, true],
    ["[['price', '>', 10], ['on', '=', true]]", false, true],
    ["['a' => 1, 'b' => ['c']]", false, true],
    ['[]', false, true],
    ['', false, false],
    ['"double"', false, false],
    ['"a{$x}"', false, false],
    ['$x', false, false],
    ['PHP_VERSION', false, false],
    ['phpinfo()', false, false],
    ["'a'.'b'", false, false],
    ["'a' ?: system(1)", false, false],
    ['1 + 1', false, false],
    ['0x10', false, false],
    ['1e3', false, false],
    ['- 1 - 1', false, false],
    ["['a', system(1)]", false, false],
    ['[...$x]', false, false],
    ["['a' => 1 => 2]", false, false],
    ["['a',, 'b']", false, false],
    ["['a'", false, false],
    ["'a'] . system(1); ['b'", false, false],
    ['[[[[[1]]]]]', false, false],
    ['`id`', false, false],
    ["<<<A\nx\nA", false, false],
    ['new Foo', false, false],
    ['fn () => 1', false, false],
    // To PHP these are texts. Blade reads the template first, and takes each for its own.
    ["'<?php system(1); ?>'", false, false],
    ["'<?= 1 ?>'", false, false],
    ["'a ?> b'", false, false],
    ["'@php system(1);'", false, false],
    ["'{{ system(1) }}'", false, false],
    ["'{!! system(1) !!}'", false, false],
    ["['a', '<?php system(1); ?>']", false, false],
]);
