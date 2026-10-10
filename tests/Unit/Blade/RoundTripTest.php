<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Classifier;
use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Rules;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Blade\Unsupported;
use Sunrice\VisualEditor\Tests\Support\RoundTrip;

/*
 * The round-trip checks over the fixture templates. `php spike/run.php`
 * runs the same checks, adds Laravel's own views and writes the report.
 */

it('has the fixtures it expects', function () {
    expect(templateDataset('sunrice-starter'))->toHaveCount(19)
        ->and(templateDataset('sunrice-package'))->toHaveCount(11)
        ->and(templateDataset('edge', 'ok-'))->toHaveCount(8)
        ->and(templateDataset('edge', 'stray-'))->toHaveCount(10)
        ->and(templateDataset('edge', 'locked-'))->toHaveCount(2);
});

it('opens a template and prints it back byte for byte', function (string $path) {
    $source = (string) file_get_contents($path);

    expect(Template::open($source)->print())->toBe($source);
})->with('templates that open');

it('compiles every Sunrice fixture before any edit', function (string $path) {
    expect(RoundTrip::phpError((string) file_get_contents($path)))->toBeNull();
})->with('sunrice templates');

it('refuses a template the tree cannot hold', function (string $path) {
    Template::open((string) file_get_contents($path));
})->with('templates that are refused')->throws(Unsupported::class);

it('keeps tags that don\'t nest as they are written, and compiles as before', function (string $path) {
    $source = (string) file_get_contents($path);
    $template = Template::open($source);

    expect($template->strays())->not->toBeEmpty()
        ->and($template->print())->toBe($source)
        ->and(RoundTrip::phpError($source))->toBeNull();
})->with('templates with stray tags');

it('finds nothing stray in a template that nests cleanly', function (string $path) {
    expect(Template::open((string) file_get_contents($path))->strays())->toBe([]);
})->with('templates that nest cleanly');

it('changes only the bytes an edit names', function (string $path) {
    $results = RoundTrip::edits((string) file_get_contents($path));

    expect(RoundTrip::failures($results))->toBe([])
        ->and(array_sum(array_column($results, 'tried')))->toBeGreaterThan(0);
})->with('templates that open');

it('opens, prints back and edits the installed starter templates', function (string $path) {
    $source = (string) file_get_contents($path);

    expect(Template::open($source)->print())->toBe($source)
        ->and(RoundTrip::failures(RoundTrip::edits($source)))->toBe([]);
})->with('installed starter templates');

it('shows the @switch of the form templates as arms, not as code', function (string $file, int $arms) {
    $template = Template::open((string) file_get_contents(RoundTrip::fixtures('sunrice-starter').'/'.$file));
    $nodes = collect(iterator_to_array($template->root->walk(), false));
    $switch = $nodes->first(fn (Node $node) => $node->type === 'block' && $node->name === 'switch');
    $inside = $nodes->filter(fn (Node $node) => $node->type === 'element' && $node->start > $switch->start && $node->end < $switch->end);

    expect((new Classifier)->classify($switch))->toBe('visual')
        ->and($switch->children)->toHaveCount($arms)
        ->and($inside)->not->toBeEmpty()
        ->and($inside->every(fn (Node $element) => Rules::movable($element)))->toBeTrue();
})->with([
    ['blocks/form.blade.php', 6],
    ['partials/entry-filter.blade.php', 6],
]);

it('knows the directives the application registers', function () {
    // Sunrice's @bodyClass is not a Blade directive of its own: the parser
    // only sees it because the scanner asks the application.
    $template = Template::open('<body @bodyClass(\'dark\')><p>Hi</p></body>');
    $body = $template->elements()[0];

    expect($body->name)->toBe('body')
        ->and($body->hasCodeAttributes())->toBeTrue()
        ->and($template->print())->toBe('<body @bodyClass(\'dark\')><p>Hi</p></body>');
});
